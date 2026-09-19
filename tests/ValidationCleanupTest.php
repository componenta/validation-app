<?php

declare(strict_types=1);

final class ValidationCleanupTest extends \PHPUnit\Framework\TestCase
{
    public function testCleansOnlyOwnedArtifactAndSupportsBuildAfterRepeatedCleanup(): void {
        $root = sys_get_temp_dir() . '/componenta_clean_validation_' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $file = $root . '/nested/map.php';
        file_put_contents($root . '/source.php', '<?php');
        $builder = new \Componenta\Validation\App\Build\ValidationBuilder(new \Componenta\ClassFinder\ClassIterator([]), $file);

        try {
            $builder->clean();
            self::assertDirectoryDoesNotExist($root . '/nested');
            $builder->build();
            self::assertFileExists($file);
            file_put_contents($root . '/nested/unrelated.php', 'preserved');
            file_put_contents($file, '<?php throw new LogicException("Cleanup must not load an artifact.");');

            $builder->clean();
            $builder->clean();

            self::assertFileDoesNotExist($file);
            self::assertSame('preserved', file_get_contents($root . '/nested/unrelated.php'));
            $builder->build();
            self::assertFileExists($file);
        } finally {
            foreach ([$file, $root . '/nested/unrelated.php', $root . '/source.php'] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            if (is_dir($root . '/nested')) { rmdir($root . '/nested'); }
            rmdir($root);
        }
    }

    public function testFailsWithoutDeletingDirectoryInPlaceOfArtifact(): void {
        $root = sys_get_temp_dir() . '/componenta_clean_failure_validation_' . bin2hex(random_bytes(8));
        $file = $root . '/map.php';
        mkdir($file, 0700, true);
        file_put_contents($file . '/keep.txt', 'preserved');
        $builder = new \Componenta\Validation\App\Build\ValidationBuilder(new \Componenta\ClassFinder\ClassIterator([]), $file);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($file);
            $builder->clean();
        } finally {
            self::assertSame('preserved', file_get_contents($file . '/keep.txt'));
            unlink($file . '/keep.txt');
            rmdir($file);
            rmdir($root);
        }
    }
}
