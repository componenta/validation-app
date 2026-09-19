<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class ValidationDiagnosticParityTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function testMapKeepsTheNativeDiagnosticForEverySpellingOfTheClass(string $identifier): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $expected = sprintf(
                '#[ValidatedBy] on "%s" must reference a class or interface implementing %s; got "stdClass".',
                InvalidDiagnosticDto::class,
                ValidatorInterface::class,
            );
            foreach ([['development', false], ['production', false], ['production', true]] as [$environment, $built]) {
                if ($built) {
                    $build = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([new ClassInfo(InvalidDiagnosticDto::class)]));
                    self::assertSame(0, (new CommandTester($build->get(BuildCommand::class)))->execute([]));
                    self::assertArrayHasKey(InvalidDiagnosticDto::class, require $root . '/validators.php');
                }
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                $caught = null;
                try {
                    $provider->provide($identifier);
                } catch (InvalidArgumentException $error) {
                    $caught = $error;
                }

                self::assertInstanceOf(InvalidArgumentException::class, $caught);
                self::assertSame($expected, $caught->getMessage());
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function identifiers(): iterable
    {
        yield 'canonical' => [InvalidDiagnosticDto::class];
        yield 'leading slash' => ['\\' . InvalidDiagnosticDto::class];
        yield 'case insensitive' => [strtolower(InvalidDiagnosticDto::class)];
    }
}

#[ValidatedBy(\stdClass::class)]
final class InvalidDiagnosticDto {}
