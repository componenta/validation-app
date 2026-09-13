<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigKey;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Field;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Length;
use Componenta\Validation\Rule\RuleFactoryInterface;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class HybridProviderTest extends TestCase
{
    #[DataProvider('providerRegistrations')]
    public function testApplicationProviderReplacesTheDefaultComposite(string $environment, string $registration): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $validator = new Validator(['name' => new Length(min: 3)]);
        $custom = new class($validator) implements ValidationProviderInterface {
            public function __construct(private ValidatorInterface $validator) {}
            public function provide(string $entryId): ?ValidatorInterface
            {
                return $entryId === 'custom-entry' ? $this->validator : null;
            }
        };
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(LiteralHybridDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            $dependencies = [ConfigKey::FACTORIES => [
                RuleFactoryInterface::class => static function (): never { throw new \LogicException('Default validation dependencies were created.'); },
            ]];
            if ($registration === ConfigKey::SERVICES) {
                $dependencies[ConfigKey::SERVICES]['application.validation_provider'] = $custom;
                $dependencies[ConfigKey::FACTORIES][ValidationProviderInterface::class] =
                    static fn (\Componenta\Config\ContainerValue $container): ValidationProviderInterface =>
                        $container->get('application.validation_provider', ValidationProviderInterface::class);
            } else {
                $dependencies[ConfigKey::FACTORIES][ValidationProviderInterface::class] =
                    static fn (): ValidationProviderInterface => $custom;
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), [
                ConfigKey::DEPENDENCIES => $dependencies,
            ])->get(ValidationProviderInterface::class);
            self::assertSame($custom, $provider);
            self::assertSame($validator, $provider->provide('custom-entry'));
            self::assertNull($provider->provide(LiteralHybridDto::class));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function providerRegistrations(): iterable
    {
        foreach (['development', 'production'] as $environment) {
            yield $environment . ' factory' => [$environment, ConfigKey::FACTORIES];
            yield $environment . ' service' => [$environment, ConfigKey::SERVICES];
        }
    }

    public function testBuildLeavesArgumentEvaluationAndFailuresToTheAttributeProvider(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        InvalidRuleArgument::$constructions = 0;
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([
                new ClassInfo(NativeArgumentDto::class), new ClassInfo(ConstantRuleDto::class),
            ]))->get(ApplicationBuildOrchestrator::class)->build();
            self::assertSame(0, InvalidRuleArgument::$constructions);
            self::assertSame([], require $root . '/validators.php');
            foreach (['development', 'production'] as $environment) {
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                self::assertSame('validation.required', $provider->provide(ConstantRuleDto::class)
                    ->validate([], \Componenta\Validation\Context::stopOnFirstFailure())->get('email')->messageId);
                try {
                    $provider->provide(NativeArgumentDto::class);
                    self::fail('A rule definition must be a string.');
                } catch (\TypeError $error) {
                    self::assertStringContainsString('must be of type string', $error->getMessage());
                }
            }
            self::assertSame(2, InvalidRuleArgument::$constructions);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testExplicitValidatorServiceTakesPriorityOverTheBuiltMap(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $validator = new Validator(['name' => new Length(min: 3)]);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(LiteralHybridDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            foreach (['development', 'production'] as $environment) {
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), [
                    \Componenta\Validation\ConfigKey::VALIDATORS_MAP => [LiteralHybridDto::class => 'application.validator'],
                    ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => ['application.validator' => $validator]],
                ])->get(ValidationProviderInterface::class);
                self::assertSame($validator, $provider->provide(LiteralHybridDto::class));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testMixedDtoFallsBackAsAWholeWithoutLosingRulesOrAliases(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([
                new ClassInfo(HybridDto::class), new ClassInfo(LiteralHybridDto::class),
            ]))->get(ApplicationBuildOrchestrator::class)->build();
            $map = require $root . '/validators.php';
            self::assertArrayNotHasKey(HybridDto::class, $map);
            self::assertSame(['email' => 'required|email'], $map[LiteralHybridDto::class]);
            foreach (['development', 'production'] as $environment) {
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                $validator = $provider->provide(HybridDto::class);
                self::assertTrue($validator->validate(['mail' => 'user@example.com', 'name' => 'Ada']));
                self::assertSame(['mail', 'name'], array_keys($validator->validate(['mail' => 'bad', 'name' => 'Al'])->toArray()));
                self::assertTrue($provider->provide(HybridDto::class)->validate(['mail' => 'user@example.com', 'name' => 'Ada']));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}

final class HybridDto
{
    #[Validate('required|email')]
    #[Field('mail')]
    public string $email;
    #[Validate('required')]
    #[Length(min: 3)]
    public string $name;
}
final class LiteralHybridDto
{
    #[Validate('required|email')]
    public string $email;
}
final class InvalidRuleArgument
{
    public static int $constructions = 0;
    public function __construct() { ++self::$constructions; }
}
final class NativeArgumentDto
{
    #[Validate(new InvalidRuleArgument())]
    public string $email;
}
final class ConstantRuleDto
{
    private const string RULES = 'required|email';
    #[Validate(self::RULES)]
    public string $email;
}
