<?php
declare(strict_types=1);

namespace Componenta\Validation\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Validation\App\Build\ValidationBuilder;
use Componenta\Validation\App\Build\ValidationBuilderFactory;
use Componenta\Validation\App\Factory\ValidationProviderFactory;
use Componenta\Validation\Provider\ValidationProviderInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getConfig(): array
    {
        return [AppConfigKey::BUILDERS => [ValidationBuilder::class]];
    }

    protected function getFactories(): array
    {
        return [
            ValidationBuilder::class => ValidationBuilderFactory::class,
            ValidationProviderInterface::class => ValidationProviderFactory::class,
        ];
    }
}
