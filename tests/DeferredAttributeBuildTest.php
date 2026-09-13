<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\RuleAttribute;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class DeferredAttributeBuildTest extends TestCase
{
    public function testBuildKeepsRulesWhoseAttributeClassBecomesAvailableLater(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(DeferredEmailDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            class_alias(DeferredEmailRule::class, __NAMESPACE__ . '\\DeferredEmailAlias');
            foreach (['development', 'production'] as $mode) {
                $provider = ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                $result = $provider->provide(DeferredEmailDto::class)->validate(['email' => 'not-an-email']);
                self::assertInstanceOf(ErrorMessageCollectorInterface::class, $result, $mode);
                self::assertSame('validation.email.invalid', $result->get('email')->messageId, $mode);
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DeferredEmailRule extends RuleAttribute
{
    public function __construct() { parent::__construct('email'); }
}
final class DeferredEmailDto
{
    #[Validate('string')]
    #[DeferredEmailAlias]
    public string $email;
}
