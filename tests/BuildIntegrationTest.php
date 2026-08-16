<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Cache\CacheLayout;
use Componenta\App\Config\ConfigFactoryResult;
use Componenta\App\ConfigProvider as AppConfigProvider;
use Componenta\App\Console\Command\BuildCommand;
use Componenta\App\ContainerCacheMode;
use Componenta\App\ContainerFactory;
use Componenta\App\ContainerFactoryOptions;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ConfigProvider as ClassFinderConfigProvider;
use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey as DiConfigKey;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\App\ConfigProvider as ValidationAppConfigProvider;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\ConfigKey as ValidationConfigKey;
use Componenta\Validation\ConfigProvider as ValidationConfigProvider;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildIntegrationTest extends TestCase
{
    public function testAppBuildCompilesRulesAndValidatedByFactoriesForProduction(): void
    {
        $root = str_replace(DIRECTORY_SEPARATOR, '/', sys_get_temp_dir())
            . '/componenta_validation_app_' . bin2hex(random_bytes(4));
        $paths = new ValidationAppTestPathResolver($root);
        $environment = new Environment(['APP_ENV' => 'development']);
        $config = ConfigLoader::load(
            $environment,
            new ClassFinderConfigProvider(),
            new AppConfigProvider(),
            new ValidationConfigProvider(),
            new ValidationAppConfigProvider(),
        );
        $iterator = new ClassIterator([
            __FILE__ . '#attributed' => new ClassInfo(BuildAttributedCommand::class),
            __FILE__ . '#delegated' => new ClassInfo(BuildDelegatedCommand::class),
            __FILE__ . '#validator' => new ClassInfo(BuildCommandValidator::class),
        ]);
        $command = new BuildCommand(
            $config,
            $paths,
            static fn (): ConfigFactoryResult => new ConfigFactoryResult($config, $iterator),
        );

        try {
            self::assertSame(0, (new CommandTester($command))->execute([]));

            $cache = CacheLayout::fromConfig($config, $paths);
            $cachedConfig = ConfigLoader::loadFromFile($cache->config);
            $compiledMap = $cachedConfig->array(ValidationConfigKey::COMPILED_VALIDATORS);
            self::assertSame(ValidationConfigKey::COMPILED_VALIDATORS_VERSION, $compiledMap['version']);
            self::assertSame('rules', $compiledMap['validators'][BuildAttributedCommand::class]['kind']);
            self::assertSame([
                'kind' => 'validator',
                'class' => BuildCommandValidator::class,
            ], $compiledMap['validators'][BuildDelegatedCommand::class]);

            $containerCache = require $cache->container;
            $compiledFactory = CompiledFactoryDefinition::decode(
                $containerCache[DiConfigKey::DEPENDENCIES][DiConfigKey::FACTORIES][BuildCommandValidator::class],
            );
            self::assertInstanceOf(CompiledFactoryDefinition::class, $compiledFactory);
            self::assertFileExists($cache->build($compiledFactory->file));

            $productionConfig = new Config(
                $cachedConfig->toArray(),
                new Environment(['APP_ENV' => 'production']),
            );
            $container = ContainerFactory::create(
                $paths,
                $productionConfig,
                options: new ContainerFactoryOptions(ContainerCacheMode::RequireCache),
            );
            $provider = $container->get(ValidationProviderInterface::class);
            self::assertInstanceOf(ValidationProviderInterface::class, $provider);

            $attributeValidator = $provider->provide(BuildAttributedCommand::class);
            self::assertNotNull($attributeValidator);
            self::assertInstanceOf(
                ErrorMessageCollectorInterface::class,
                $attributeValidator->validate([]),
            );
            self::assertInstanceOf(
                BuildCommandValidator::class,
                $provider->provide(BuildDelegatedCommand::class),
            );
        } finally {
            self::removeCache(CacheLayout::fromConfig($config, $paths), $root);
        }
    }

    private static function removeCache(CacheLayout $cache, string $root): void
    {
        foreach (glob($cache->buildDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ([
            $cache->config,
            $cache->container,
            $cache->config . '.lock',
            $cache->container . '.lock',
        ] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ([
            $cache->buildDir,
            dirname($cache->buildDir),
            dirname(dirname($cache->buildDir)),
            $root,
        ] as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }
}

final class ValidationAppTestPathResolver implements PathResolverInterface
{
    public string $baseDir {
        get => $this->root;
    }

    public function __construct(private readonly string $root) {}

    public function resolve(string $path): string
    {
        if (preg_match('/^[A-Z]:[\\\\\/]/i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}

final class BuildAttributedCommand
{
    #[Required]
    public string $name;
}

#[ValidatedBy(BuildCommandValidator::class)]
final class BuildDelegatedCommand {}

final class BuildCommandValidator implements ValidatorInterface
{
    public function validate(iterable $data, ?ContextInterface $context = null): true|ErrorMessageCollectorInterface
    {
        return true;
    }
}
