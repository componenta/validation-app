<?php

declare(strict_types=1);

namespace Componenta\Validation\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Validation\App\Compile\ValidationMapCompiler;
use Componenta\Validation\App\Discovery\ValidationDiscoveryIndex;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getInvokables(): array
    {
        return [
            ValidationDiscoveryIndex::class,
            ValidationMapCompiler::class,
        ];
    }

    /** @return array<string, list<class-string>> */
    protected function getConfig(): array
    {
        return [
            ClassFinderConfigKey::LISTENERS => [
                ValidationDiscoveryIndex::class,
            ],
            CompileConfigKey::LISTENER_COMPILERS => [
                ValidationMapCompiler::class,
            ],
            AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS => [
                ValidationDiscoveryIndex::class,
            ],
        ];
    }
}
