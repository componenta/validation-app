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

final class RegisteredRuleBuildTest extends TestCase
{
    #[DataProvider('modes')]
    public function testRegisteredRulesBehaveEquallyWithAttributesAndBuiltMaps(
        string $environment,
        bool $built,
        string $dto,
        string $accepted,
        string $rejected,
        bool $override,
    ): void {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $config = [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => [
                    RuleFactoryInterface::class => static function () use ($override): RuleFactory {
                        $rules = new RuleFactory();
                        $rules->alias('pattern', 'regex');
                        if ($override) {
                            $rules->register('regex', static fn (array $arguments): Regex => new Regex('~^selected$~'));
                        }
                        return $rules;
                    },
                ],
            ],
        ];

        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container(
                    $root, $environment, new ClassIterator([new ClassInfo($dto)]), $config,
                );
                self::assertSame(0, new CommandTester($build->get(BuildCommand::class))->execute([]));
                self::assertArrayHasKey($dto, require $root . '/validators.php');
            }
            $container = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), $config);
            $provider = $container->get(ValidationProviderInterface::class);

            for ($invocation = 0; $invocation < 2; ++$invocation) {
                $validator = $provider->provide($dto);
                self::assertNotNull($validator);
                self::assertTrue($validator->validate(['value' => $accepted]));
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
            yield $mode . ', native regex' => [$environment, $built, RegisteredRegexControlDto::class, 'blue', 'green', false];
            yield $mode . ', regex alias' => [$environment, $built, RegisteredRegexAliasDto::class, 'blue', 'green', false];
            yield $mode . ', explicit factory' => [$environment, $built, RegisteredRegexOverrideDto::class, 'selected', 'original', true];
        }
    }
}

final class RegisteredRegexControlDto
{
    #[Validate('regex:~^(red|blue){1,3}$~')]
    public string $value;
}

final class RegisteredRegexAliasDto
{
    #[Validate('pattern:~^(red|blue){1,3}$~')]
    public string $value;
}

final class RegisteredRegexOverrideDto
{
    #[Validate('regex:~^original$~')]
    public string $value;
}
