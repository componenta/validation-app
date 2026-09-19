<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Attribute\When;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class ConditionalValueBuildTest extends TestCase
{
    #[DataProvider('modes')]
    public function testTypedConditionsSelectTheSameBranchesWithAttributesAndMaps(string $environment, bool $built): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([
                    new ClassInfo(LiteralConditionsDto::class), new ClassInfo(AttributeConditionsDto::class),
                ]));
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                $map = require $root . '/validators.php';
                self::assertSame([
                    'email' => 'when:enabled:true,required',
                    'name' => 'when:level:2,required',
                ], $map[LiteralConditionsDto::class]);
                self::assertArrayNotHasKey(AttributeConditionsDto::class, $map);
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))->get(ValidationProviderInterface::class);
            foreach ([LiteralConditionsDto::class, AttributeConditionsDto::class] as $dto) {
                for ($invocation = 0; $invocation < 2; ++$invocation) {
                    $validator = $provider->provide($dto);
                    self::assertNotNull($validator);
                    self::assertTrue($validator->validate(['enabled' => false, 'level' => 1]));
                    self::assertTrue($validator->validate(['enabled' => true, 'level' => 2, 'email' => 'a@example.com', 'name' => 'Ada']));
                    $errors = $validator->validate(['enabled' => true, 'level' => 2]);
                    self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                    self::assertSame(['email', 'name'], array_keys($errors->toArray()));
                    self::assertSame('validation.required', $errors->get('email')->messageId);
                    self::assertSame('validation.required', $errors->get('name')->messageId);
                    self::assertTrue($validator->validate(['enabled' => 'true', 'level' => '2']));
                }
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    #[DataProvider('modes')]
    public function testExplicitElseRuleIsPreservedWithAttributesAndMaps(string $environment, bool $built): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([
                    new ClassInfo(LiteralElseDto::class), new ClassInfo(AttributeElseDto::class),
                ]));
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                $map = require $root . '/validators.php';
                self::assertSame(['value' => 'when:enabled:true,required,email'], $map[LiteralElseDto::class]);
                self::assertArrayNotHasKey(AttributeElseDto::class, $map);
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))->get(ValidationProviderInterface::class);
            foreach ([LiteralElseDto::class, AttributeElseDto::class] as $dto) {
                for ($invocation = 0; $invocation < 2; ++$invocation) {
                    $validator = $provider->provide($dto);
                    self::assertNotNull($validator);
                    self::assertTrue($validator->validate(['enabled' => true, 'value' => 'present']));
                    self::assertTrue($validator->validate(['enabled' => false, 'value' => 'a@example.com']));

                    $errors = $validator->validate(['enabled' => false, 'value' => 'present']);
                    self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                    self::assertSame('validation.email.invalid', $errors->get('value')->messageId);
                    self::assertTrue($validator->validate(['enabled' => false, 'value' => 'a@example.com']));
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

final class LiteralConditionsDto
{
    #[Validate('when:enabled:true,required')]
    public string $email;
    #[Validate('when:level:2,required')]
    public string $name;
}

final class AttributeConditionsDto
{
    #[When('enabled:true', then: 'required')]
    public string $email;
    #[When('level:2', then: 'required')]
    public string $name;
}

final class LiteralElseDto
{
    #[Validate('when:enabled:true,required,email')]
    public string $value;
}

final class AttributeElseDto
{
    #[When('enabled:true', then: 'required', else: 'email')]
    public string $value;
}
