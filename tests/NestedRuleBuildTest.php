<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;


require_once __DIR__ . '/ValidatorMapBuildTest.php';

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigKey;
use Componenta\Tokenizer\ClassInfo;

use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\In;
use Componenta\Validation\Rule\OneOf;
use Componenta\Validation\Rule\RuleFactory;
use Componenta\Validation\Rule\RuleFactoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NestedRuleBuildTest extends TestCase
{
    #[DataProvider('syntaxModes')]
    public function testNestedRulesKeepTheirIntendedBehaviorThroughBuild(string $environment, bool $built, string $dto, mixed $accepted, mixed $rejected): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $factory = new RuleFactory();
        $factory->register('any', static fn (array $rules): OneOf => new OneOf(...$rules), composite: true);
        $extra = [ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => [RuleFactoryInterface::class => static fn (): RuleFactory => $factory]]];
        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([new ClassInfo($dto)]), $extra);
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                self::assertArrayHasKey($dto, require $root . '/validators.php');
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), $extra)
                ->get(ValidationProviderInterface::class);
            $validator = $provider->provide($dto);
            self::assertNotNull($validator);
            self::assertTrue($validator->validate(['value' => $accepted]) === true);
            self::assertInstanceOf(ErrorMessageCollectorInterface::class, $validator->validate(['value' => $rejected]));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function syntaxModes(): iterable
    {
        foreach (self::environments() as $mode => [$environment, $built]) {
            yield $mode . ', flat control' => [$environment, $built, FlatPatternDto::class, ['red'], ['green']];
            yield $mode . ', custom comma parameter' => [$environment, $built, CustomLengthDto::class, 'ok', 'longer'];
            yield $mode . ', custom pipe children' => [$environment, $built, CustomChoiceDto::class, 'ok', 'longer'];
            yield $mode . ', nested regex' => [$environment, $built, NestedPatternDto::class, ['red'], ['green']];
        }
    }

    #[DataProvider('environments')]
    public function testExistingProviderUsesUpdatedFactoryWithoutChangingPreviouslyCreatedValidator(string $environment, bool $built): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $factory = new RuleFactory();
        $factory->alias('either', 'oneof');
        $extra = [ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => [RuleFactoryInterface::class => static fn (): RuleFactory => $factory]]];
        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([new ClassInfo(MutableFactoryDto::class)]), $extra);
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                self::assertArrayHasKey(MutableFactoryDto::class, require $root . '/validators.php');
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), $extra)
                ->get(ValidationProviderInterface::class);
            $before = $provider->provide(MutableFactoryDto::class);
            self::assertNotNull($before);
            self::assertTrue($before->validate(['value' => 'https://example.com']) === true);

            $factory->register('oneof', static fn (array $arguments): In => new In($arguments));

            $after = $provider->provide(MutableFactoryDto::class);
            self::assertNotNull($after);
            self::assertTrue($after->validate(['value' => 'email']) === true);
            self::assertInstanceOf(ErrorMessageCollectorInterface::class, $after->validate(['value' => 'https://example.com']));
            self::assertTrue($before->validate(['value' => 'https://example.com']) === true);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function environments(): iterable
    {
        yield 'development' => ['development', false];
        yield 'production fallback' => ['production', false];
        yield 'production map' => ['production', true];
    }
}

final class FlatPatternDto { #[Validate('arrayof:regex:~^(red|blue)$~')] public array $value; }
final class NestedPatternDto { #[Validate('allof:arrayof:regex:~^(red|blue)$~')] public array $value; }
final class CustomLengthDto { #[Validate('any:length:2,3')] public string $value; }
final class CustomChoiceDto { #[Validate('any:email|length:2,3')] public string $value; }
final class MutableFactoryDto { #[Validate('either:email,url')] public string $value; }
