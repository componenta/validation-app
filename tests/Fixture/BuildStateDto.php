<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Attribute;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Rule\RuleInterface;

enum BuildMarker { case Value; }
final class BuildState { public int $calls = 0; }
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BuildStateRule implements RuleInterface
{
    public static int $constructions = 0;
    public string $name { get => 'build-state'; }
    public function __construct(
        private BuildState $state,
        string $prefix,
        string $class,
        string $file,
        BuildMarker $marker,
    ) {
        ++self::$constructions;
        if ($prefix !== 'private-prefix' || $class !== BuildStateDto::class
            || $file !== __FILE__ || $marker !== BuildMarker::Value) {
            throw new \RuntimeException('Native attribute argument meaning changed.');
        }
    }
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface
    {
        if ($value !== ++$this->state->calls) {
            throw new \RuntimeException('Validator rule state was reused.');
        }
        return true;
    }
}
final class BuildStateDto
{
    private const PREFIX = 'private-prefix';
    #[BuildStateRule(new BuildState(), self::PREFIX, __CLASS__, __FILE__, BuildMarker::Value)]
    public int $value;
}
