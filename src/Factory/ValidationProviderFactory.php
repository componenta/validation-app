<?php
declare(strict_types=1);

namespace Componenta\Validation\App\Factory;

use Componenta\App\Build\PreparedBuild;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\Validation\App\Build\ValidationBuilder;
use Componenta\Validation\Factory\ValidationProviderFactory as BaseFactory;
use Componenta\Validation\Provider\CompositeValidationProvider;
use Componenta\Validation\Provider\MapValidationProvider;

/** @phpstan-import-type Entry from MapValidationProvider */
final class ValidationProviderFactory
{
    public function __invoke(ContainerValue $container): CompositeValidationProvider
    {
        $development = $container->config->environment->match('APP_ENV', 'development', default: 'development', strict: true);
        $forceSource = $container->config->environment->bool('APP_DISCOVERY_SOURCE', false);
        $map = $development || $forceSource || !$container->has(AppConfigKey::PREPARED_BUILD)
            ? null
            : self::read($container->get(AppConfigKey::PREPARED_BUILD, PreparedBuild::class));
        return (new BaseFactory($map))($container);
    }

    /** @return array<string, Entry>|null */
    private static function read(PreparedBuild $prepared): ?array
    {
        $map = $prepared->read(ValidationBuilder::ARTIFACT_ID, ValidationBuilder::SCHEMA_VERSION);

        return MapValidationProvider::validMap($map) ? $map : null;
    }
}
