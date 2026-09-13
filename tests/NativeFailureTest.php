<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Attribute;
use Componenta\ClassFinder\ClassIterator;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Factory\ValidatorFactoryInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\RuleFactoryInterface;
use Componenta\Validation\Rule\RuleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class NativeFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testMapAndFallbackPreserveNativeErrorsWithoutRetry(string $class, string $exception, string $message): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        ThrowingBuildRule::$constructions = 0;
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo($class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            self::assertSame(0, ThrowingBuildRule::$constructions);
            $map = require $root . '/validators.php';
            foreach (['development', 'production', 'factory'] as $mode) {
                $container = ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]));
                try {
                    if ($mode === 'factory') {
                        (new \Componenta\Validation\Provider\CompositeValidationProvider(
                            new \Componenta\Validation\Provider\MapValidationProvider(
                                $map, $container->get(ValidatorFactoryInterface::class), $container->get(RuleFactoryInterface::class),
                            ),
                            new \Componenta\Validation\Provider\AttributeValidationProvider(
                                $container->get(ValidatorFactoryInterface::class), $container->get(RuleFactoryInterface::class),
                            ),
                        ))->provide($class);
                    } else {
                        $container->get(ValidationProviderInterface::class)->provide($class);
                    }
                    self::fail('Expected the native attribute error.');
                } catch (\Throwable $error) {
                    self::assertInstanceOf($exception, $error);
                    self::assertStringContainsString($message, $error->getMessage());
                }
            }
            self::assertSame($class === ThrowingBuildDto::class ? 3 : 0, ThrowingBuildRule::$constructions);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function failures(): iterable
    {
        yield 'wrong target' => [WrongTargetDto::class, \Error::class, 'cannot target property'];
        yield 'repeated attribute' => [RepeatedBuildDto::class, \Error::class, 'must not be repeated'];
        yield 'constructor failure' => [ThrowingBuildDto::class, \RuntimeException::class, 'attribute constructor failed'];
        yield 'invalid validator service' => [InvalidServiceDto::class, \InvalidArgumentException::class, 'must reference a class or interface implementing'];
        yield 'conflicting validator sources' => [ConflictingServiceDto::class, \InvalidArgumentException::class, 'cannot combine #[ValidatedBy] with property validation attributes'];
        yield 'repeated validator service' => [RepeatedServiceDto::class, \InvalidArgumentException::class, 'declares #[ValidatedBy] more than once'];
        yield 'duplicate field aliases' => [DuplicateFieldDto::class, \InvalidArgumentException::class, 'declared by more than one property'];
        yield 'empty field name' => [EmptyFieldDto::class, \InvalidArgumentException::class, 'must not be empty'];
        yield 'repeated Validate' => [RepeatedValidateDto::class, \Error::class, 'must not be repeated'];
    }
}
#[Attribute(Attribute::TARGET_CLASS)]
final class WrongTargetRule implements RuleInterface
{
    public string $name { get => 'wrong'; }
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface { return true; }
}
final class WrongTargetDto { #[WrongTargetRule] public string $value; }
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ThrowingBuildRule implements RuleInterface
{
    public static int $constructions = 0;
    public string $name { get => 'throwing'; }
    public function __construct()
    {
        ++self::$constructions;
        throw new \RuntimeException('attribute constructor failed');
    }
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface { return true; }
}
final class ThrowingBuildDto { #[ThrowingBuildRule] public string $value; }
final class RepeatedBuildDto
{
    #[\Componenta\Validation\Rule\Required]
    #[\Componenta\Validation\Rule\Required]
    public string $value;
}

#[\Componenta\Validation\Attribute\ValidatedBy(\stdClass::class)]
final class InvalidServiceDto {}
#[\Componenta\Validation\Attribute\ValidatedBy(\Componenta\Validation\ValidatorInterface::class)]
final class ConflictingServiceDto { #[\Componenta\Validation\Attribute\Validate('required')] public string $value; }
#[\Componenta\Validation\Attribute\ValidatedBy(\Componenta\Validation\ValidatorInterface::class)]
#[\Componenta\Validation\Attribute\ValidatedBy(\Componenta\Validation\ValidatorInterface::class)]
final class RepeatedServiceDto {}
final class DuplicateFieldDto {
    #[\Componenta\Validation\Attribute\Validate('required', as: 'same')] public string $first;
    #[\Componenta\Validation\Attribute\Validate('required', as: 'same')] public string $second;
}
final class EmptyFieldDto { #[\Componenta\Validation\Attribute\Validate('required', as: '')] public string $value; }
final class RepeatedValidateDto {
    #[\Componenta\Validation\Attribute\Validate('required')]
    #[\Componenta\Validation\Attribute\Validate('int')]
    public int $value;
}
