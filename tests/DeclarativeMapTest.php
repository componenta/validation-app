<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests;

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigKey;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\Attribute\Field;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

final class DeclarativeMapTest extends TestCase
{
    public function testGeneratedMapPreservesNativeClassIdentifierSemantics(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(RegistrationDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            foreach (['development', 'production'] as $environment) {
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                foreach ([RegistrationDto::class, '\\' . RegistrationDto::class, strtolower(RegistrationDto::class)] as $id) {
                    self::assertTrue($provider->provide($id)->validate(['email' => 'user@example.com', 'years' => 25]));
                }
                self::assertNull($provider->provide('\\\\' . RegistrationDto::class), $environment);
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testGeneratedMapPreservesLiteralRegularExpressions(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo(LiteralRuleTextDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            foreach (['development', 'production'] as $environment) {
                $provider = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]))
                    ->get(ValidationProviderInterface::class);
                $validator = $provider->provide(LiteralRuleTextDto::class);
                self::assertTrue($validator->validate(['value' => ' use ($properties)']));
                self::assertSame(['value'], array_keys($validator->validate(['value' => 'different'])->toArray()));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testBuildExportsFieldRulesAndValidatedByServiceAsData(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([
                new ClassInfo(RegistrationDto::class),
                new ClassInfo(ExternalRegistrationDto::class),
                new ClassInfo(NoValidationDto::class),
            ]))->get(ApplicationBuildOrchestrator::class)->build();

            $map = require $root . '/validators.php';
            self::assertSame(['email' => 'required|email', 'years' => 'required|int'], $map[RegistrationDto::class]);
            self::assertSame(RegistrationValidator::class, $map[ExternalRegistrationDto::class]);
            self::assertArrayNotHasKey(NoValidationDto::class, $map);
            $service = new Validator(['token' => new Required()]);
            foreach (['development', 'production'] as $environment) {
                $container = ValidatorMapBuildTest::container($root, $environment, new ClassIterator([]), [
                    ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => [RegistrationValidator::class => $service]],
                ]);
                $provider = $container->get(ValidationProviderInterface::class);
                $validator = $provider->provide(RegistrationDto::class);
                self::assertTrue($validator->validate(['email' => 'user@example.com', 'years' => 25]));
                self::assertSame(['email', 'years'], array_keys($validator->validate([])->toArray()));
                self::assertSame($service, $provider->provide(ExternalRegistrationDto::class));
                self::assertSame($service, $provider->provide(ExternalRegistrationDto::class));
                self::assertNull($provider->provide(NoValidationDto::class));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
final class RegistrationDto
{
    #[Validate(rules: 'required|email', as: 'email')]
    public string $address;
    #[Validate('required|int', as: 'ignored')]
    #[Field(name: 'years')]
    public int $age;
}
interface RegistrationValidator extends ValidatorInterface {}
#[ValidatedBy(validator: RegistrationValidator::class)]
final class ExternalRegistrationDto {}
final class NoValidationDto {}

final class LiteralRuleTextDto
{
    #[Validate('regex:~\Q use ($properties)\E~')]
    public string $value;
}
