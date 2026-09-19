<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigKey;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Regex;
use Componenta\Validation\Rule\RuleFactory;
use Componenta\Validation\Rule\RuleFactoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class CompositeRuleBuildTest extends TestCase
{
    #[DataProvider('modes')]
    public function testCompositeRegistrationsBehaveEquallyWithAttributesAndMaps(
        string $environment,
        bool $built,
        string $dto,
        mixed $accepted,
        mixed $rejected,
        ?string $override,
    ): void {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $config = [ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => [
            RuleFactoryInterface::class => static function () use ($override): RuleFactory {
                $rules = new RuleFactory();
                $rules->alias('either', 'oneof');
                $rules->alias('items', 'arrayof');
                if ($override !== null) {
                    $rules->register($override, static fn (array $arguments): Regex => new Regex('~^selected$~'));
                }
                return $rules;
            },
        ]]];

        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([new ClassInfo($dto)]), $config);
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                self::assertArrayHasKey($dto, require $root . '/validators.php');
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), $config)
                ->get(ValidationProviderInterface::class);

            for ($invocation = 0; $invocation < 2; ++$invocation) {
                $validator = $provider->provide($dto);
                self::assertNotNull($validator);
                self::assertTrue($validator->validate(['value' => $accepted]) === true);
                self::assertInstanceOf(ErrorMessageCollectorInterface::class, $validator->validate(['value' => $rejected]));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function modes(): iterable
    {
        foreach ([
            'development' => ['development', false],
            'production fallback' => ['production', false],
            'production map' => ['production', true],
        ] as $mode => [$environment, $built]) {
            yield $mode . ', native oneof' => [$environment, $built, NativeOneOfDto::class, 'ok', 'nope', null];
            yield $mode . ', oneof alias' => [$environment, $built, AliasedOneOfDto::class, 'ok', 'nope', null];
            yield $mode . ', native arrayof' => [$environment, $built, NativeArrayOfDto::class, ['a@example.com'], ['bad'], null];
            yield $mode . ', arrayof alias' => [$environment, $built, AliasedArrayOfDto::class, ['a@example.com'], ['bad'], null];
            yield $mode . ', allof factory' => [$environment, $built, OverriddenAllOfDto::class, 'selected', 'original', 'allof'];
            yield $mode . ', oneof factory' => [$environment, $built, OverriddenOneOfDto::class, 'selected', 'original', 'oneof'];
            yield $mode . ', arrayof factory' => [$environment, $built, OverriddenArrayOfDto::class, 'selected', 'original', 'arrayof'];
        }
    }
}

final class NativeOneOfDto { #[Validate('oneof:email|length:2,3')] public string $value; }
final class AliasedOneOfDto { #[Validate('either:email|length:2,3')] public string $value; }
final class NativeArrayOfDto { #[Validate('arrayof:email|length:5,255')] public array $value; }
final class AliasedArrayOfDto { #[Validate('items:email|length:5,255')] public array $value; }
final class OverriddenAllOfDto { #[Validate('allof:email')] public string $value; }
final class OverriddenOneOfDto { #[Validate('oneof:email')] public string $value; }
final class OverriddenArrayOfDto { #[Validate('arrayof:email')] public string $value; }
