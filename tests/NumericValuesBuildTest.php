<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Range;
use Componenta\Validation\Rule\RequiredIf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class NumericValuesBuildTest extends TestCase
{
    #[DataProvider('modes')]
    public function testNumericBoundsAndConditionsAgreeWithNativeRulesThroughBuild(string $environment, bool $built): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            if ($built) {
                $container = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([
                    new ClassInfo(LiteralNumbersDto::class), new ClassInfo(AttributeNumbersDto::class),
                ]));
                self::assertSame(0, (new CommandTester($container->get(BuildCommand::class)))->execute([]));
                $map = require $root . '/validators.php';
                self::assertSame(['score' => 'min:1e-2', 'value' => 'required_if:level,1e-2'], $map[LiteralNumbersDto::class]);
                self::assertArrayNotHasKey(AttributeNumbersDto::class, $map);
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))->get(ValidationProviderInterface::class);
            foreach ([LiteralNumbersDto::class, AttributeNumbersDto::class] as $dto) {
                for ($invocation = 0; $invocation < 2; ++$invocation) {
                    $validator = $provider->provide($dto);
                    self::assertNotNull($validator);
                    self::assertTrue($validator->validate(['level' => 0.01, 'score' => 0.02, 'value' => 'present']));

                    $errors = $validator->validate(['level' => 0.01, 'score' => 0.005]);
                    self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                    self::assertSame(['score', 'value'], array_keys($errors->toArray()));
                    self::assertSame('validation.range.too_small', $errors->get('score')->messageId);
                    self::assertSame('validation.required_if', $errors->get('value')->messageId);
                    self::assertTrue($validator->validate(['level' => '0.01', 'score' => 0.02]));
                }
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function modes(): iterable
    {
        yield 'development' => ['development', false];
        yield 'production fallback' => ['production', false];
        yield 'production map' => ['production', true];
    }
}

final class LiteralNumbersDto
{
    #[Validate('min:1e-2')]
    public float $score;

    #[Validate('required_if:level,1e-2')]
    public string $value;
}

final class AttributeNumbersDto
{
    #[Range(min: 1e-2)]
    public float $score;

    #[RequiredIf('level', 1e-2)]
    public string $value;
}
