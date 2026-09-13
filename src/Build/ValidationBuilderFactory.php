<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Build;

use Componenta\App\ConfigKey;
use Componenta\ClassFinder\ClassIterator;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\Config\ContainerValue;
use Componenta\Validation\App\Factory\ValidationProviderFactory;

final class ValidationBuilderFactory
{
    public function __invoke(ContainerValue $container): ValidationBuilder
    {
        return new ValidationBuilder(
            $container->has(ConfigKey::DISCOVERY_SOURCE)
                ? $container->get(ConfigKey::DISCOVERY_SOURCE, ClassIteratorInterface::class)
                : new ClassIterator([]),
            ValidationProviderFactory::mapFile($container),
        );
    }
}
