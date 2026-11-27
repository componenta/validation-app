<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class BuildLifecycleTest extends TestCase
{
    #[DataProvider('invalidFiles')]
    public function testLegacyFilesAreIgnoredWithoutDiscoveryOrArtifactWrites(?string $content): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $file = $root . '/validators.php';
        if ($content !== null) { file_put_contents($file, $content); }
        try {
            $source = new ClassIterator((static function (): \Generator {
                throw new \RuntimeException('Runtime discovery was invoked.');
                yield;
            })());
            $container = ValidatorMapBuildTest::container($root, 'production', $source);
            $provider = $container->get(ValidationProviderInterface::class);
            self::assertTrue($provider->provide(MapBuildDto::class)->validate(['name' => 'Ada']));
            self::assertSame($content !== null, is_file($file));
            if ($content !== null) { self::assertSame($content, file_get_contents($file)); }
            self::assertDirectoryDoesNotExist($root . '/build');
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function invalidFiles(): iterable
    {
        yield 'missing' => [null];
        yield 'syntax error' => ['<?php return ['];
        yield 'wrong type' => ['<?php return 42;'];
        yield 'old envelope' => ['<?php return ["version" => 1, "validators" => []];'];
        yield 'invalid factory' => ['<?php return ["ExampleDto" => 42];'];
        yield 'legacy factory entry' => ['<?php return ["Componenta\\\\Validation\\\\App\\\\Tests\\\\MapBuildDto" => static function () { throw new \\LogicException("Legacy factory executed."); }];'];
    }

    #[DataProvider('invalidPreparedMaps')]
    public function testIncompatiblePreparedRulesFallBackWithoutChangingTheSnapshot(array $map, int $schema): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $prepared = ValidatorMapBuildTest::publish($root, $map, $schema);
            $provider = ValidatorMapBuildTest::container($root, 'production', new ClassIterator([]))
                ->get(ValidationProviderInterface::class);
            self::assertTrue($provider->provide(MapBuildDto::class)->validate(['name' => 'Ada']));
            self::assertSame($prepared->generation, ValidatorMapBuildTest::store($root)->load()?->generation);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public static function invalidPreparedMaps(): iterable
    {
        yield 'invalid entry' => [[MapBuildDto::class => 42], 1];
        yield 'unsupported schema' => [[MapBuildDto::class => ['other' => 'required']], 2];
    }

    public function testHelpAndListKeepBuildDependenciesLazy(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $source = new ClassIterator((static function (): \Generator {
                throw new \RuntimeException('Help must not invoke build discovery.');
                yield;
            })());
            $container = ValidatorMapBuildTest::container($root, 'production', $source);
            $application = new Application();
            $application->setAutoExit(false);
            $application->addCommand($container->get(BuildCommand::class));
            foreach ([['command' => 'list'], ['command' => 'app:build', '--help' => true]] as $arguments) {
                $output = new BufferedOutput();
                self::assertSame(0, $application->run(new ArrayInput($arguments), $output));
                self::assertStringContainsString('app:build', $output->fetch());
            }
            self::assertNull(ValidatorMapBuildTest::store($root)->load());
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testFailedBuildLeavesThePreviousMapUsableAndReturnsFailure(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(MapBuildDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            $previous = ValidatorMapBuildTest::store($root)->load()?->generation;
            $source = new ClassIterator((static function (): \Generator {
                yield new ClassInfo(BuildStateDto::class);
                throw new \RuntimeException('Discovery failed.');
            })());
            $container = ValidatorMapBuildTest::container($root, 'production', $source);
            $application = new Application();
            $application->setAutoExit(false);
            $application->addCommand($container->get(BuildCommand::class));
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $output = new BufferedOutput();
                self::assertSame(1, $application->run(new ArrayInput(['command' => 'app:build']), $output));
                self::assertStringContainsString('Discovery failed.', $output->fetch());
                self::assertSame($previous, ValidatorMapBuildTest::store($root)->load()?->generation);
            }
            self::assertTrue($container->get(ValidationProviderInterface::class)->provide(MapBuildDto::class)->validate(['name' => 'Ada']));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testWriteFailureLeavesTheDestinationAndItsContentsIntact(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $file = $root . '/build';
        $previous = 'Do not replace a source file with the build store.';
        file_put_contents($file, $previous);
        try {
            $container = ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(BuildStateDto::class)]));
            $application = new Application();
            $application->setAutoExit(false);
            $application->addCommand($container->get(BuildCommand::class));
            self::assertSame(1, $application->run(new ArrayInput(['command' => 'app:build']), new BufferedOutput()));
            self::assertSame($previous, file_get_contents($file));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testEmptyDiscoveryPublishesAnEmptyMapAndRebuildUsesSource(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            self::assertSame([], ValidatorMapBuildTest::map($root));
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(MapBuildDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            $map = ValidatorMapBuildTest::map($root);
            self::assertSame(['name' => 'required'], $map[MapBuildDto::class]);
            self::assertTrue(ValidatorMapBuildTest::container($root, 'production', new ClassIterator([]))
                ->get(ValidationProviderInterface::class)->provide(MapBuildDto::class)->validate(['name' => 'Ada']));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
