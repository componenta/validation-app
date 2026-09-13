<?php
declare(strict_types=1);

$package = dirname(__DIR__);
$local = $package . '/vendor/autoload.php';
$integration = dirname($package) . '/validation-app/integration/vendor/autoload.php';
$loader = require is_file($local) ? $local : $integration;
$manifest = json_decode(file_get_contents($package . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['autoload-dev']['psr-4'] ?? [] as $prefix => $paths) {
    $loader->addPsr4($prefix, array_map(static fn (string $path): string => $package . '/' . $path, (array) $paths), true);
}
