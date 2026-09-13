<?php
namespace Componenta\Validation\App\Tests\Other;

use Attribute;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Rule\RuleInterface;

final class NumericArgument
{
    public function __construct(public int $value) {}
}
#[Attribute(Attribute::TARGET_PROPERTY)]
final class WeakArgumentRule implements RuleInterface
{
    public string $name { get => 'weak-argument'; }
    public function __construct(
        private NumericArgument $argument,
        string $namespace,
        string $trait,
        string $class,
    ) {
        if ($namespace !== __NAMESPACE__ || $trait !== WeakValidationTrait::class
            || $class !== \Componenta\Validation\App\Tests\AdvancedBuildDto::class) {
            throw new \RuntimeException('Trait attribute scope changed.');
        }
    }
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface
    {
        if ($value !== $this->argument->value) {
            throw new \RuntimeException('Weak native argument coercion changed.');
        }
        return true;
    }
}
trait WeakValidationTrait
{
    #[WeakArgumentRule(new NumericArgument('7'), __NAMESPACE__, __TRAIT__, __CLASS__)]
    public int $number;
}
