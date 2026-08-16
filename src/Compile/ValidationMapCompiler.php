<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Compile;

use Componenta\ClassFinder\Compile\CompileResult;
use Componenta\ClassFinder\Compile\ListenerCompilerInterface;
use Componenta\Validation\App\Discovery\ValidationDiscoveryIndex;
use Componenta\Validation\ConfigKey;
use InvalidArgumentException;

final readonly class ValidationMapCompiler implements ListenerCompilerInterface
{
    public function supports(object $listener): bool
    {
        return $listener instanceof ValidationDiscoveryIndex;
    }

    public function compile(object $listener, string $cacheDir): CompileResult
    {
        if (!$listener instanceof ValidationDiscoveryIndex) {
            throw new InvalidArgumentException(sprintf(
                '%s supports only %s.',
                self::class,
                ValidationDiscoveryIndex::class,
            ));
        }

        return CompileResult::config(
            ConfigKey::COMPILED_VALIDATORS,
            $listener->map(),
        );
    }
}
