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
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Factory\ValidatorFactoryInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Length;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Rule\RuleFactory;
use Componenta\Validation\Rule\RuleFactoryInterface;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ValidatorMapBuildTest.php';
require_once __DIR__ . '/Fixture/WeakValidationTrait.php';

final class RuntimeParityTest extends TestCase
{
    public function testFallbackRetainsAliasesNullableInheritancePromotedPropertiesAndWeakTraitArguments(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'development', new ClassIterator([new ClassInfo(AdvancedBuildDto::class)]))
                ->get(ApplicationBuildOrchestrator::class)->build();
            $map = require $root . '/validators.php';
            foreach (['development', 'production', 'factory'] as $mode) {
                $container = ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]));
                $validator = $mode === 'factory'
                    ? (new \Componenta\Validation\Provider\CompositeValidationProvider(
                        new \Componenta\Validation\Provider\MapValidationProvider(
                            $map, $container->get(ValidatorFactoryInterface::class), $container->get(RuleFactoryInterface::class),
                        ),
                        new \Componenta\Validation\Provider\AttributeValidationProvider(
                            $container->get(ValidatorFactoryInterface::class), $container->get(RuleFactoryInterface::class),
                        ),
                    ))->provide(AdvancedBuildDto::class)
                    : $container->get(ValidationProviderInterface::class)->provide(AdvancedBuildDto::class);
                self::assertTrue($validator->validate(['mail' => null, 'qty' => 3, 'number' => 7]));
                self::assertTrue($validator->validate(['mail' => 'user@example.com', 'qty' => 3, 'number' => 7]));
                $errors = $validator->validate(['mail' => 'bad', 'qty' => 3, 'number' => 7]);
                self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                self::assertSame(['mail'], array_keys($errors->toArray()));
                self::assertSame(\Componenta\Validation\Rule\Email::INVALID_EMAIL_MESSAGE_ID, $errors->get('mail')->messageId);
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }

    public function testServiceAndRuleFactoriesRemainRuntimeDependencies(): void
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([
                new ClassInfo(ServiceBuildDto::class), new ClassInfo(CustomRuleBuildDto::class),
                new ClassInfo(ServiceBuildEnum::class, \Componenta\Tokenizer\DeclarationType::Enum_),
            ]))->get(ApplicationBuildOrchestrator::class)->build();
            $map = require $root . '/validators.php';
            self::assertSame(ServiceValidatorContract::class, $map[ServiceBuildEnum::class]);
            foreach (['development', 'production'] as $mode) {
                $rules = new RuleFactory();
                $rules->register('application_rule', static fn (array $arguments) => new Length(min: 3));
                $service = new \Componenta\Validation\Validator(['service' => new Required()]);
                $container = ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]), [
                    ConfigKey::DEPENDENCIES => [
                        ConfigKey::FACTORIES => [RuleFactoryInterface::class => static fn (): RuleFactoryInterface => $rules],
                        ConfigKey::SERVICES => [ServiceValidatorContract::class => $service],
                    ],
                ]);
                $provider = $container->get(ValidationProviderInterface::class);
                self::assertSame($service, $provider->provide(ServiceBuildDto::class));
                self::assertSame($service, $provider->provide(ServiceBuildDto::class));
                self::assertSame($service, $provider->provide(ServiceBuildEnum::class));
                $validator = $provider->provide(CustomRuleBuildDto::class);
                self::assertTrue($validator->validate(['value' => 'long']));
                $errors = $validator->validate(['value' => 'ab']);
                self::assertInstanceOf(ErrorMessageCollectorInterface::class, $errors);
                self::assertSame(['value'], array_keys($errors->toArray()));
            }
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}
class ParentBuildDto
{
    #[Length(max: 255)]
    #[Validate('nullable|email', as: 'ignored_alias')]
    #[Field('mail')]
    public ?string $email = null;
}
final class AdvancedBuildDto extends ParentBuildDto
{
    use \Componenta\Validation\App\Tests\Other\WeakValidationTrait;
    public function __construct(
        #[Required] #[Validate('int')] #[Field('qty')]
        #[PromotedScopeRule(__FUNCTION__, __METHOD__)]
        public int $quantity = 1,
    ) {}
}
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class PromotedScopeRule implements \Componenta\Validation\Rule\RuleInterface
{
    public string $name { get => 'promoted-scope'; }
    public function __construct(string $function, string $method)
    {
        if ($function !== '__construct' || $method !== AdvancedBuildDto::class . '::__construct') {
            throw new \RuntimeException('Promoted attribute lexical scope changed.');
        }
    }
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface { return true; }
}
interface ServiceValidatorContract extends ValidatorInterface {}
#[ValidatedBy(ServiceValidatorContract::class)]
final class ServiceBuildDto {}
#[ValidatedBy(ServiceValidatorContract::class)]
enum ServiceBuildEnum { case Value; }
final class CustomRuleBuildDto { #[Validate('application_rule')] public string $value; }
