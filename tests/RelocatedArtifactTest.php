<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class RelocatedArtifactTest extends TestCase
{
    public function testFallbackUsesTheDeployedSourcePathForNativeFileArguments(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/build');
        mkdir($root . '/runtime');
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace ValidationRelocationFixture;
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class FileRule implements \Componenta\Validation\Rule\RuleInterface {
    public string $name { get => 'file'; }
    public function __construct(private string $expected) {}
    public function validate(mixed $value, \Componenta\Validation\ContextInterface $context): true|\Componenta\Validation\Error\ErrorMessageCollectorInterface {
        if ($value !== $this->expected) { throw new \RuntimeException('Attribute source path was frozen at build time.'); }
        return true;
    }
}
final class Dto {
    #[FileRule(__FILE__)] public string $file;
    #[FileRule(__DIR__)] public string $directory;
    public static function data(): array { return ['file' => __FILE__, 'directory' => __DIR__]; }
}
PHP;
        try {
            file_put_contents($root . '/build/source.php', $source);
            require $root . '/build/source.php';
            ValidatorMapBuildTest::container($root . '/build', 'development', new ClassIterator([
                new ClassInfo(\ValidationRelocationFixture\Dto::class),
            ]))->get(ApplicationBuildOrchestrator::class)->build();
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/build', \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($root . '/build') + 1);
                $destination = $root . '/runtime/' . $relative;
                if ($file->isDir()) { mkdir($destination); } else { copy($file->getPathname(), $destination); }
            }
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) { $autoload = dirname(__DIR__) . '/integration/vendor/autoload.php'; }
            $script = '<?php require ' . var_export($autoload, true) . '; require __DIR__ . "/source.php";' . <<<'PHP'
$map = require __DIR__ . '/validators.php';
$container = new class implements \Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new \LogicException($id); }
    public function has(string $id): bool { return false; }
};
$rules = new \Componenta\Validation\Rule\RuleFactory();
$validators = new \Componenta\Validation\Factory\ValidatorFactory($container, $rules);
$provider = new \Componenta\Validation\Provider\CompositeValidationProvider(
    new \Componenta\Validation\Provider\MapValidationProvider($map, $validators, $rules),
    new \Componenta\Validation\Provider\AttributeValidationProvider($validators, $rules),
);
echo json_encode($provider->provide(\ValidationRelocationFixture\Dto::class)->validate(\ValidationRelocationFixture\Dto::data()));
PHP;
            file_put_contents($root . '/runtime/run.php', $script);
            $command = [PHP_BINARY];
            if (php_ini_loaded_file() !== false) { array_push($command, '-c', php_ini_loaded_file()); }
            $command[] = $root . '/runtime/run.php';
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame('true', $output);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
