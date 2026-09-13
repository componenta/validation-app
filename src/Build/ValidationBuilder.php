<?php
declare(strict_types=1);

namespace Componenta\Validation\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\ClassFinder\ClassIteratorInterface;
use Componenta\VarExport\VarExport;
use ErrorException;
use RuntimeException;

final readonly class ValidationBuilder implements ApplicationBuilderInterface
{
    public function __construct(private ClassIteratorInterface $classes, private string $file) {}

    public function build(): void
    {
        $extractor = new ValidatorMapExtractor();
        $map = [];
        foreach ($this->classes as $info) {
            if ($info->isClass || $info->isEnum) {
                $class = $info->reflector;
                $entry = $extractor->extract($class);
                if ($entry !== null) {
                    $map[$class->getName()] = $entry;
                }
            }
        }
        ksort($map, SORT_STRING);
        $this->write($this->file, "<?php\ndeclare(strict_types=1);\nreturn " . VarExport::withDefaults()->export($map) . ";\n");
    }

    private function write(string $file, string $content): void
    {
        $directory = dirname($file);
        $temporary = null;
        $stream = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create validation map directory "' . $directory . '".');
            }
            $temporary = $directory . '/.' . basename($file) . '.' . bin2hex(random_bytes(12)) . '.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false || fwrite($stream, $content) !== strlen($content) || !fflush($stream)) {
                throw new RuntimeException('Cannot write complete validation map "' . $file . '".');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $file)) {
                throw new RuntimeException('Cannot publish validation map "' . $file . '".');
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        } finally {
            try {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($temporary !== null && is_file($temporary)) {
                    unlink($temporary);
                }
            } finally {
                restore_error_handler();
            }
        }
    }
}
