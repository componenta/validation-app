<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Console\Command\BuildCommand;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/Fixture/BuildStateDto.php';

final class ValidatorMapBuildTest extends TestCase
{
    public function testBuildPublishesRuleMapsUsedByProduction(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $reads = 0;
        $source = new ClassIterator((static function () use (&$reads): \Generator {
            ++$reads;
            yield new ClassInfo(MapBuildDto::class);
        })());
        try {
            $build = self::container($root, 'production', $source, [
                \Componenta\App\ConfigKey::BUILDERS => [SharedSourceBuilder::class],
                \Componenta\Config\ConfigKey::DEPENDENCIES => [
                    \Componenta\Config\ConfigKey::FACTORIES => [
                        SharedSourceBuilder::class => static fn (ContainerValue $container): SharedSourceBuilder =>
                            new SharedSourceBuilder($container->get(\Componenta\App\ConfigKey::DISCOVERY_SOURCE, \Componenta\ClassFinder\ClassIteratorInterface::class)),
                    ],
                ],
            ]);
            $command = $build->get(BuildCommand::class, BuildCommand::class);
            self::assertSame(0, $reads);
            self::assertFileDoesNotExist($root . '/validators.php');
            self::assertSame(0, (new CommandTester($command))->execute([]));
            self::assertSame(1, $reads);
            self::assertSame([MapBuildDto::class], $build->get(SharedSourceBuilder::class)->classes);
            $map = require $root . '/validators.php';
            self::assertSame(['name' => 'required'], $map[MapBuildDto::class]);
            $runtimeSource = new ClassIterator((static function (): \Generator {
                throw new \RuntimeException('Runtime discovery must remain lazy.');
                yield;
            })());
            foreach (['development', 'production'] as $environment) {
                $runtime = self::container($root, $environment, $runtimeSource);
                $provider = $runtime->get(ValidationProviderInterface::class, ValidationProviderInterface::class);
                $validator = $provider->provide(MapBuildDto::class);
                self::assertNotNull($validator);
                self::assertTrue($validator->validate(['name' => 'Ada']));
                $errors = $validator->validate([]);
                self::assertSame(['name'], array_keys($errors->toArray()));
                self::assertSame('validation.required', $errors->get('name')->messageId);
            }
        } finally {
            if (is_file($root . '/validators.php')) { unlink($root . '/validators.php'); }
            self::remove($root);
        }
    }

    public function testFallbackPreservesFreshRulesAndNativeArgumentExpressions(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        BuildStateRule::$constructions = 0;
        try {
            self::container($root, 'development', new ClassIterator([new ClassInfo(BuildStateDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            self::assertSame(0, BuildStateRule::$constructions);
            $map = require $root . '/validators.php';
            self::assertArrayNotHasKey(BuildStateDto::class, $map);
            $source = new ClassIterator([]);
            foreach (['development', 'production'] as $environment) {
                $container = self::container($root, $environment, $source);
                $provider = $container->get(ValidationProviderInterface::class, ValidationProviderInterface::class);
                $validator = $provider->provide(BuildStateDto::class);
                self::assertTrue($validator->validate(['value' => 1]));
                self::assertTrue($validator->validate(['value' => 2]));
                self::assertTrue($provider->provide(BuildStateDto::class)->validate(['value' => 1]));
            }
            self::assertSame(4, BuildStateRule::$constructions);
        } finally {
            self::remove($root);
        }
    }

    public static function remove(string $root): void
    {
        $resolved = realpath($root);
        if ($resolved === false || !str_starts_with(basename($resolved), 'validation_map_')) {
            throw new \LogicException('Unexpected validation test directory.');
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($resolved);
    }

    public static function container(string $root, string $environment, ClassIterator $source, array $extra = []): ContainerValue
    {
        $composition = (new ConfigFactory())->create(
            new Environment(['APP_ENV' => $environment]),
            new \Componenta\App\ConfigProvider(),
            new \Componenta\App\Console\ConfigProvider(),
            new \Componenta\Validation\ConfigProvider(),
            new \Componenta\Validation\App\ConfigProvider(),
            static fn (): array => [
                'validation.map_file' => 'validators.php',
                \Componenta\Config\ConfigKey::DEPENDENCIES => [
                    \Componenta\Config\ConfigKey::SERVICES => [
                        PathResolverInterface::class => new PathResolver($root),
                        \Componenta\App\ConfigKey::DISCOVERY_SOURCE => $source,
                    ],
                ],
            ],
            static fn (): array => $extra,
        );
        return (new ContainerFactory())->create($composition->config, $composition->dependencies);
    }
}

final class SharedSourceBuilder implements \Componenta\App\Build\ApplicationBuilderInterface
{
    public array $classes = [];
    public function __construct(private \Componenta\ClassFinder\ClassIteratorInterface $source) {}
    public function build(): void
    {
        foreach ($this->source as $info) { $this->classes[] = $info->fullyQualifiedName; }
    }
}

final class MapBuildDto
{
    #[Validate('required')]
    public string $name;
}
