<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Validation\App\Build\ValidationBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class ValidationCleanupTest extends TestCase
{
    public function testCentralCleanupUnpublishesWhileRetainingTheSelectedRuntimeSnapshot(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root . '/source.php', 'preserved');
        $store = ValidatorMapBuildTest::store($root);
        $orchestrator = new ApplicationBuildOrchestrator([
            new ValidationBuilder(new ClassIterator([])),
        ], $store);

        try {
            $orchestrator->clean();
            self::assertDirectoryDoesNotExist($root . '/build');
            $prepared = $orchestrator->build();
            self::assertSame([], $prepared->read(ValidationBuilder::ARTIFACT_ID));
            $orchestrator->clean();
            $orchestrator->clean();
            self::assertNull($store->load());
            self::assertSame([], $prepared->read(ValidationBuilder::ARTIFACT_ID));
            self::assertSame('preserved', file_get_contents($root . '/source.php'));
            self::assertNotSame($prepared->generation, $orchestrator->build()->generation);
            self::assertSame([], ValidatorMapBuildTest::map($root));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
