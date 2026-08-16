<?php

declare(strict_types=1);

namespace Componenta\Validation\App;

use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\ClassFinder\Compile\ConfigKey as CompileConfigKey;
use Componenta\ClassFinder\ConfigKey as ClassFinderConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Validation\App\Compile\ValidationMapCompiler;
use Componenta\Validation\App\Discovery\ValidationDiscoveryIndex;
use Componenta\Validation\ConfigKey as ValidationConfigKey;

/** Registers validation discovery and compilation with Componenta App. */
final class ConfigProvider extends BaseConfigProvider
{
    /** @return array<string, mixed> */
    protected function getConfig(): array
    {
        return [
            ValidationConfigKey::REQUIRE_COMPILED_VALIDATORS => true,
            ClassFinderConfigKey::LISTENERS => [ValidationDiscoveryIndex::class],
            CompileConfigKey::LISTENER_COMPILERS => [ValidationMapCompiler::class],
            AppConfigKey::AUTOWIRE_ENTRY_CONTRIBUTORS => [ValidationDiscoveryIndex::class],
        ];
    }

    /** @return list<class-string> */
    protected function getInvokables(): array
    {
        return [
            ValidationDiscoveryIndex::class,
            ValidationMapCompiler::class,
        ];
    }
}
