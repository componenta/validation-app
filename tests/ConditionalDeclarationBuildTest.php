<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class ConditionalDeclarationBuildTest extends TestCase
{
    public function testBuildPreservesTheActiveConditionalDeclaration(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(ConditionalEmailDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            foreach (['development', 'production'] as $mode) {
                $provider = ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                $result = $provider->provide(ConditionalEmailDto::class)->validate(['email' => 'not-an-email']);
                self::assertInstanceOf(ErrorMessageCollectorInterface::class, $result, $mode);
                self::assertSame('validation.email.invalid', $result->get('email')->messageId, $mode);
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}

if (false) {
    final class ConditionalEmailDto
    {
        #[Validate('string')]
        public string $email;
    }
} else {
    final class ConditionalEmailDto
    {
        #[Validate('email')]
        public string $email;
    }
}
