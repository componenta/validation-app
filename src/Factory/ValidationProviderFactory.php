<?php
declare(strict_types=1);

namespace Componenta\Validation\App\Factory;

use Componenta\App\Build\PhpMapFile;
use Componenta\Config\ContainerValue;
use Componenta\Stdlib\PathResolverInterface;
use Componenta\Validation\App\ConfigKey;
use Componenta\Validation\Factory\ValidationProviderFactory as BaseFactory;
use Componenta\Validation\Provider\CompositeValidationProvider;
use Componenta\Validation\Provider\MapValidationProvider;
use InvalidArgumentException;

/** @phpstan-import-type Entry from MapValidationProvider */
final class ValidationProviderFactory
{
    public function __invoke(ContainerValue $container): CompositeValidationProvider
    {
        $development = $container->config->environment->match('APP_ENV', 'development', default: 'development', strict: true);
        $forceSource = $container->config->environment->bool('APP_DISCOVERY_SOURCE', false);
        return (new BaseFactory($development || $forceSource ? null : self::read(self::mapFile($container))))($container);
    }

    public static function mapFile(ContainerValue $container): string
    {
        $path = $container->config->get(ConfigKey::MAP_FILE, ConfigKey::DEFAULT_MAP_FILE);
        if (!is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException(ConfigKey::MAP_FILE . ' must be a non-empty path.');
        }
        return $container->get(PathResolverInterface::class, PathResolverInterface::class)->resolve($path);
    }

    /** @return array<string, Entry>|null */
    private static function read(string $file): ?array
    {
        $map = PhpMapFile::read($file);

        return MapValidationProvider::validMap($map) ? $map : null;
    }
}
