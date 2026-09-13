<?php
declare(strict_types=1);

namespace Componenta\Validation\App\Build;

use Componenta\Validation\Attribute\Field;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\Internal\AttributeMetadata;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionAttribute;
use ReflectionClass;

/** @internal Extracts complete, literal validator definitions without evaluating attribute arguments. */
final class ValidatorMapExtractor
{
    /** @var array<string, array<Node>> */
    private array $sources = [];

    /** @param ReflectionClass<object> $class
     *  @return array<array-key, string>|string|null Null delegates the entire class to the attribute provider.
     */
    public function extract(ReflectionClass $class): array|string|null
    {
        $metadata = AttributeMetadata::inspect($class);
        if (!$metadata['complete']) {
            return null;
        }
        if ($metadata['validated_by'] !== []) {
            if (count($metadata['validated_by']) !== 1 || $metadata['properties'] !== []) {
                return null;
            }
            $position = $metadata['validated_by'][0];
            $arguments = $this->arguments($class->getAttributes()[$position], $class, null, $position);
            $service = $arguments['validator'] ?? null;
            return is_string($service) && trim($service) !== '' ? $service : null;
        }

        $fields = [];
        foreach ($metadata['properties'] as $property => $positions) {
            if ($positions['rules'] !== [] || $positions['attributes'] !== []
                || count($positions['validate']) !== 1 || count($positions['field']) > 1
            ) {
                return null;
            }
            $reflection = $class->getProperty($property);
            $scope = $reflection->getDeclaringClass();
            $attributes = $reflection->getAttributes();
            $position = $positions['validate'][0];
            $validate = $this->arguments($attributes[$position], $scope, $property, $position);
            if ($validate === null || !is_string($validate['rules'])) {
                return null;
            }
            $name = $validate['as'] ?? $property;
            if ($positions['field'] !== []) {
                $position = $positions['field'][0];
                $field = $this->arguments($attributes[$position], $scope, $property, $position);
                if ($field === null) {
                    return null;
                }
                $name = $field['name'];
            }
            if (!is_string($name) || $name === '' || array_key_exists($name, $fields)) {
                return null;
            }
            $fields[$name] = $validate['rules'];
        }
        return $fields === [] ? null : $fields;
    }

    /** @param ReflectionAttribute<object> $attribute
     *  @param ReflectionClass<object> $scope
     *  @return array<string, string|null>|null
     */
    private function arguments(ReflectionAttribute $attribute, ReflectionClass $scope, ?string $property, int $position): ?array
    {
        // Only these exact classes have known, side-effect-free constructors.
        // Class aliases and different spelling refer to the same class; subclasses do not.
        $type = (new ReflectionClass($attribute->getName()))->getName();
        $parameters = match ($type) {
            Validate::class => ['rules', 'as'],
            Field::class => ['name'],
            ValidatedBy::class => ['validator'],
            default => null,
        };
        if ($parameters === null || $attribute->isRepeated()) {
            return null;
        }
        $declaration = $this->declaration($scope, $property);
        $node = $declaration[$position] ?? null;
        if (!$node instanceof Node\Attribute || strcasecmp($node->name->toString(), $attribute->getName()) !== 0) {
            return null;
        }

        $values = [];
        $named = false;
        foreach ($node->args as $index => $argument) {
            $name = $argument->name?->toString();
            if ($argument->unpack || ($named && $name === null)) {
                return null;
            }
            $named = $named || $name !== null;
            $name ??= $parameters[$index] ?? '';
            if (!in_array($name, $parameters, true) || array_key_exists($name, $values)) {
                return null;
            }
            $value = $argument->value;
            if ($value instanceof Node\Scalar\String_) {
                $values[$name] = $value->value;
            } elseif ($name === 'as' && $value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null') {
                $values[$name] = null;
            } elseif ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name
                && $value->name instanceof Node\Identifier && $value->name->toLowerString() === 'class'
            ) {
                $class = $value->class->toString();
                if (strtolower($class) === 'parent') {
                    $parent = $scope->getParentClass();
                    if ($parent === false) { return null; }
                    $class = $parent->getName();
                } elseif (strtolower($class) === 'self') {
                    $class = $scope->getName();
                } elseif (strtolower($class) === 'static') {
                    return null;
                }
                $values[$name] = $class;
            } else {
                // Constants, nested new, coercions and other expressions keep native runtime evaluation.
                return null;
            }
        }
        if (!array_key_exists($parameters[0], $values)) {
            return null;
        }
        return $type === Validate::class ? $values + ['as' => null] : $values;
    }

    /** @param ReflectionClass<object> $class
     *  @return list<Node\Attribute>|null
     */
    private function declaration(ReflectionClass $class, ?string $property): ?array
    {
        $file = $class->getFileName();
        if ($file === false || !is_readable($file)) {
            return null;
        }
        if (!isset($this->sources[$file])) {
            $code = file_get_contents($file);
            if ($code === false) {
                return null;
            }
            $nodes = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
            $this->sources[$file] = (new NodeTraverser(new NameResolver()))->traverse($nodes);
        }
        $declarations = (new NodeFinder())->find($this->sources[$file], static fn (Node $node): bool =>
            $node instanceof Node\Stmt\ClassLike && isset($node->namespacedName)
            && $node->namespacedName->toString() === $class->getName());
        // Multiple conditional declarations cannot be identified safely by name alone.
        if (count($declarations) !== 1) {
            return null;
        }
        $node = reset($declarations);
        if (!$node instanceof Node\Stmt\ClassLike) {
            return null;
        }
        $target = $property === null ? $node : null;
        if ($property !== null) {
            foreach ($node->stmts as $statement) {
                if ($statement instanceof Node\Stmt\Property) {
                    foreach ($statement->props as $declared) {
                        if ($declared->name->toString() === $property) {
                            $target = $statement;
                            break 2;
                        }
                    }
                }
                if ($statement instanceof Node\Stmt\ClassMethod && $statement->name->toLowerString() === '__construct') {
                    foreach ($statement->params as $parameter) {
                        if ($parameter->flags !== 0 && $parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $property) {
                            $target = $parameter;
                            break 2;
                        }
                    }
                }
            }
            if ($target === null) {
                foreach ($class->getTraits() as $trait) {
                    if ($trait->hasProperty($property)) {
                        return $this->declaration($trait, $property);
                    }
                }
            }
        }
        if ($target === null) {
            return null;
        }
        $attributes = [];
        foreach ($target->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $attribute;
            }
        }
        return $attributes;
    }
}
