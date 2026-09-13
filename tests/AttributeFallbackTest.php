<?php
declare(strict_types=1);
namespace Componenta\Validation\App\Tests\Fallback;

require_once __DIR__ . '/ValidatorMapBuildTest.php';

use Attribute;
use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\ClassFinder\ClassIterator;
use Componenta\Config\ConfigKey;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\App\Tests\ValidatorMapBuildTest;
use Componenta\Validation\Attribute\Field;
use Componenta\Validation\Attribute\RuleAttribute;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Rule\RuleFactory;
use Componenta\Validation\Rule\RuleFactoryInterface;
use Componenta\Validation\Rule\RuleInterface;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeFallbackTest extends TestCase
{
    #[DataProvider('validationMarkers')]
    public function testValidationMarkerSubclassesAndNativeClassNamesAreNotLost(string $class): void
    {
        foreach ($this->providers($class) as $mode => $provider) {
            $validator = $provider->provide($class);
            self::assertInstanceOf(ValidatorInterface::class, $validator, $mode);
            self::assertSame(['email'], array_keys($validator->validate([])->toArray()), $mode);
        }
    }

    public static function validationMarkers(): iterable
    {
        yield 'Validate subclass' => [CustomValidateDto::class];
        yield 'different native class casing' => [CasedValidateDto::class];
        yield 'native class alias' => [AliasedValidateDto::class];
    }

    public function testFieldSubclassKeepsItsAlias(): void
    {
        foreach ($this->providers(CustomFieldDto::class) as $mode => $provider) {
            $validator = $provider->provide(CustomFieldDto::class);
            self::assertSame(['mail'], array_keys($validator->validate([])->toArray()), $mode);
        }
    }

    public function testValidatedBySubclassResolvesTheService(): void
    {
        $service = new Validator(['email' => new Required()]);
        foreach ($this->providers(CustomServiceDto::class, [
            ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => [ValidatorInterface::class => $service]],
        ]) as $mode => $provider) {
            self::assertSame($service, $provider->provide(CustomServiceDto::class), $mode);
        }
    }

    public function testCustomRuleAttributesAndRuleObjectsUseCurrentFactoryWithoutLosingRules(): void
    {
        $rules = new RuleFactory();
        $rules->register('custom', static fn () => new \Componenta\Validation\Rule\Email());
        $rules->register('required', static fn () => new \Componenta\Validation\Rule\Nullable());
        foreach ($this->providers(CustomRuleDto::class, [
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => [RuleFactoryInterface::class => static fn () => $rules]],
        ]) as $mode => $provider) {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $validator = $provider->provide(CustomRuleDto::class);
                self::assertTrue($validator->validate(['email' => 'user@example.com']), $mode);
                self::assertSame('validation.required', $validator->validate([], \Componenta\Validation\Context::stopOnFirstFailure())->get('email')->messageId, $mode);
                self::assertSame('validation.email.invalid', $validator->validate(['email' => 'bad'])->get('email')->messageId, $mode);
            }
        }
    }

    public function testNativeVisibilityErrorIsPreservedByFallback(): void
    {
        foreach ($this->providers(ProtectedConstructorDto::class) as $mode => $provider) {
            try {
                $provider->provide(ProtectedConstructorDto::class);
            } catch (\Error $error) {
                self::assertStringContainsString('constructor', strtolower($error->getMessage()), $mode);
                continue;
            }
            self::fail($mode . ': a non-public attribute constructor must fail like ReflectionAttribute::newInstance().');
        }
    }

    public function testConditionalAttributesRetainTheirCustomBuildRuleComposition(): void
    {
        foreach ($this->providers(ConditionalDto::class) as $mode => $provider) {
            $validator = $provider->provide(ConditionalDto::class);
            self::assertTrue($validator->validate(['status' => 'draft']), $mode);
            self::assertTrue($validator->validate(['status' => 'published', 'title' => 'abc']), $mode);
            self::assertSame(['title'], array_keys($validator->validate(['status' => 'published'])->toArray()), $mode);
            self::assertSame(['title'], array_keys($validator->validate(['status' => 'published', 'title' => 'ab'])->toArray()), $mode);
        }
    }
    /** @return \Generator<string, ValidationProviderInterface> */
    private function providers(string $class, array $extra = []): \Generator
    {
        $root = sys_get_temp_dir() . '/validation_map_' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            ValidatorMapBuildTest::container($root, 'production', new ClassIterator([new ClassInfo($class)]), $extra)
                ->get(ApplicationBuildOrchestrator::class)->build();
            foreach (['development', 'production'] as $mode) {
                yield $mode => ValidatorMapBuildTest::container($root, $mode, new ClassIterator([]), $extra)
                    ->get(ValidationProviderInterface::class);
            }
            unlink($root . '/validators.php');
            yield 'production without map' => ValidatorMapBuildTest::container($root, 'production', new ClassIterator([]), $extra)
                ->get(ValidationProviderInterface::class);
        } finally {
            ValidatorMapBuildTest::remove($root);
        }
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class RequiredEmail extends Validate
{
    public function __construct() { parent::__construct('required|email'); }
}
final class CustomValidateDto { #[RequiredEmail] public string $email; }
final class CasedValidateDto { #[\Componenta\Validation\Attribute\VALIDATE('required|email')] public string $email; }
class_alias(Validate::class, __NAMESPACE__ . '\ValidationAlias');
final class AliasedValidateDto { #[ValidationAlias('required|email')] public string $email; }
#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class EmailField extends Field {}
final class CustomFieldDto { #[Validate('required')] #[EmailField('mail')] public string $email; }
#[Attribute(Attribute::TARGET_CLASS)]
readonly class ApplicationValidator extends ValidatedBy {}
#[ApplicationValidator(ValidatorInterface::class)]
final class CustomServiceDto {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ApplicationRule extends RuleAttribute
{
    public function __construct() { parent::__construct('custom'); }
    public function buildRule(RuleFactoryInterface $factory): RuleInterface { return $factory->createRule($this->ruleName); }
}
final class CustomRuleDto { #[Required] #[ApplicationRule] public string $email; }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ProtectedConstructorRule implements RuleInterface
{
    public string $name { get => 'protected-constructor'; }
    protected function __construct(string $value) {}
    public function validate(mixed $value, ContextInterface $context): true|ErrorMessageCollectorInterface { return true; }
}
final class ProtectedConstructorDto extends ProtectedConstructorRule
{
    private const VALUE = 'value';
    #[ProtectedConstructorRule(self::VALUE)]
    public string $email;
}

final class ConditionalDto
{
    #[\Componenta\Validation\Attribute\When('status:published', then: 'required|string|length:3,20')]
    public string $title;
}
