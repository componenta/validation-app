<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\ClassFinder\ClassIterator;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class MapConsumptionTest extends TestCase
{
    public function testProductionUsesTheBuiltMapWithoutDtoAttributeDeclarations(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        $class = 'ComponentaValidationMapConsumptionFixture\\Dto';
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace ComponentaValidationMapConsumptionFixture;
final class Dto
{
    #[\Componenta\Validation\Attribute\Validate('allof:arrayof:regex:~^(red|blue)$~')]
    public array $value;
}
PHP;
        try {
            file_put_contents($root . '/source.php', $source);
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) { $autoload = dirname(__DIR__) . '/integration/vendor/autoload.php'; }
            $script = '<?php require ' . var_export($autoload, true) . '; require '
                . var_export(__DIR__ . '/ValidatorMapBuildTest.php', true) . '; require __DIR__ . "/source.php";' . <<<'PHP'
$container = \Componenta\Validation\App\Tests\ValidatorMapBuildTest::container(
    __DIR__, 'production', new \Componenta\ClassFinder\ClassIterator([
        new \Componenta\Tokenizer\ClassInfo(\ComponentaValidationMapConsumptionFixture\Dto::class),
    ]),
);
$command = new \Symfony\Component\Console\Tester\CommandTester($container->get(\Componenta\App\Console\Command\BuildCommand::class));
exit($command->execute([]));
PHP;
            file_put_contents($root . '/build.php', $script);
            $command = [PHP_BINARY];
            if (php_ini_loaded_file() !== false) { array_push($command, '-c', php_ini_loaded_file()); }
            $command[] = $root . '/build.php';
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame(['value' => 'allof:arrayof:regex:~^(red|blue)$~'], (require $root . '/validators.php')[$class]);
            self::assertFalse(class_exists($class, false));

            $provider = ValidatorMapBuildTest::container($root, 'production', new ClassIterator([]))->get(ValidationProviderInterface::class);
            $validator = $provider->provide($class);

            self::assertNotNull($validator);
            self::assertTrue($validator->validate(['value' => ['red', 'blue']]));
            $errors = $validator->validate(['value' => ['green']]);
            self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
            self::assertSame(['value.0'], array_keys($errors->toArray()));
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
