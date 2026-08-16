<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Tests;

use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\App\Compile\ValidationMapCompiler;
use Componenta\Validation\App\Discovery\ValidationDiscoveryIndex;
use Componenta\Validation\Attribute\Validate;
use Componenta\Validation\Attribute\ValidatedBy;
use Componenta\Validation\ConfigKey;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\ValidatorInterface;
use PHPUnit\Framework\TestCase;

final class ValidationDiscoveryIndexTest extends TestCase
{
    public function testCompilesPropertyRulesAndValidatedByServices(): void
    {
        $index = new ValidationDiscoveryIndex();
        $index->handle(new ClassInfo(AttributedCommand::class));
        $index->handle(new ClassInfo(DelegatedCommand::class));
        $index->finalize();

        $map = $index->map();
        self::assertSame(ConfigKey::COMPILED_VALIDATORS_VERSION, $map['version']);
        self::assertSame('rules', $map['validators'][AttributedCommand::class]['kind']);
        self::assertSame([
            'kind' => 'validator',
            'class' => CommandValidator::class,
        ], $map['validators'][DelegatedCommand::class]);

        $entries = iterator_to_array($index->entries(), preserve_keys: false);
        self::assertCount(1, $entries);
        self::assertSame(CommandValidator::class, $entries[0]->class);
    }

    public function testKeepsInterfaceValidatorServiceIdsWithoutAutowiringThem(): void
    {
        $index = new ValidationDiscoveryIndex();
        $index->handle(new ClassInfo(InterfaceDelegatedCommand::class));
        $index->finalize();

        self::assertSame([
            'kind' => 'validator',
            'class' => CommandValidatorContract::class,
        ], $index->map()['validators'][InterfaceDelegatedCommand::class]);
        self::assertSame([], iterator_to_array($index->entries(), preserve_keys: false));
    }

    public function testCompilerKeepsAnEmptyVersionedMap(): void
    {
        $index = new ValidationDiscoveryIndex();
        $index->finalize();

        $result = (new ValidationMapCompiler())->compile($index, sys_get_temp_dir());

        self::assertSame(ConfigKey::COMPILED_VALIDATORS, $result->configKey);
        self::assertSame([
            'version' => ConfigKey::COMPILED_VALIDATORS_VERSION,
            'validators' => [],
        ], $result->configValue);
    }
}

final class AttributedCommand
{
    #[Required]
    #[Validate('string')]
    public string $name;
}

#[ValidatedBy(CommandValidator::class)]
final class DelegatedCommand {}

interface CommandValidatorContract extends ValidatorInterface {}

#[ValidatedBy(CommandValidatorContract::class)]
final class InterfaceDelegatedCommand {}

final class CommandValidator implements ValidatorInterface
{
    public function validate(iterable $data, ?ContextInterface $context = null): true|ErrorMessageCollectorInterface
    {
        return true;
    }
}
