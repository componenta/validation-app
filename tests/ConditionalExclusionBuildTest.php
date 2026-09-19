<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\ExcludeIf;
use Componenta\Validation\Rule\Required;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class ConditionalExclusionBuildTest extends TestCase
{
    #[DataProvider('modes')]
    public function testExclusionIsEquivalentWithAttributesAndBuiltMaps(string $environment, bool $built): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            if ($built) {
                $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([
                    new ClassInfo(LiteralExclusionDto::class), new ClassInfo(AttributeExclusionDto::class),
                ]));
                self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                $map = require $root . '/validators.php';
                self::assertSame([
                    'title' => 'exclude_if:is_draft,true|required',
                    'summary' => 'required|exclude_if:is_draft,true',
                    'author' => 'required',
                ], $map[LiteralExclusionDto::class]);
                self::assertArrayNotHasKey(AttributeExclusionDto::class, $map);
            }
            $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                ->get(ValidationProviderInterface::class);
            foreach ([LiteralExclusionDto::class, AttributeExclusionDto::class] as $dto) {
                for ($invocation = 0; $invocation < 2; ++$invocation) {
                    $validator = $provider->provide($dto);
                    self::assertNotNull($validator);
                    self::assertTrue($validator->validate(['is_draft' => true, 'author' => 'Ada']));
                    $errors = $validator->validate(['is_draft' => true]);
                    self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                    self::assertSame(['author'], array_keys($errors->toArray()));
                    self::assertSame('validation.required', $errors->get('author')->messageId);
                    foreach ([false, 'true'] as $inactive) {
                        $errors = $validator->validate(['is_draft' => $inactive]);
                        self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                        self::assertSame(['title', 'summary', 'author'], array_keys($errors->toArray()));
                        foreach (['title', 'summary', 'author'] as $field) {
                            self::assertSame('validation.required', $errors->get($field)->messageId);
                        }
                    }
                    self::assertTrue($validator->validate(['is_draft' => true, 'author' => 'Ada']));
                    self::assertTrue($validator->validate([
                        'is_draft' => false, 'title' => 'Present', 'summary' => 'Ready', 'author' => 'Ada',
                    ]));
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

final class LiteralExclusionDto
{
    #[Validate('exclude_if:is_draft,true|required')]
    public string $title;
    #[Validate('required|exclude_if:is_draft,true')]
    public string $summary;
    #[Validate('required')]
    public string $author;
}

final class AttributeExclusionDto
{
    #[ExcludeIf('is_draft', true)]
    #[Required]
    public string $title;
    #[Required]
    #[ExcludeIf('is_draft', true)]
    public string $summary;
    #[Required]
    public string $author;
}
