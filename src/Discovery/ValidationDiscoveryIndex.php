<?php

declare(strict_types=1);

namespace Componenta\Validation\App\Discovery;

use Componenta\ClassFinder\Attribute\DevOnly;
use Componenta\ClassFinder\Exception\ListenerAlreadyFinalizedException;
use Componenta\ClassFinder\FinalizableListenerInterface;
use Componenta\ClassFinder\FinalizationStateInterface;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Autowire\AutowireEntryContributorInterface;
use Componenta\Tokenizer\ClassInfo;
use Componenta\Validation\ConfigKey;
use Componenta\Validation\Definition\AttributeValidatorDefinitionExtractor;
use Componenta\Validation\ValidatorInterface;
use ReflectionClass;
use RuntimeException;

#[DevOnly]
final class ValidationDiscoveryIndex implements
    FinalizableListenerInterface,
    FinalizationStateInterface,
    AutowireEntryContributorInterface
{
    /** @var array<class-string, array<string, mixed>> */
    private array $definitions = [];

    /** @var array<class-string<ValidatorInterface>, true> */
    private array $validatorServices = [];

    private bool $isFinalized = false;

    public bool $finalized {
        get => $this->isFinalized;
    }

    public function __construct(
        private readonly AttributeValidatorDefinitionExtractor $extractor = new AttributeValidatorDefinitionExtractor(),
    ) {}

    public function handle(ClassInfo $info): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        if (!$info->isClass) {
            return;
        }

        $reflection = $info->reflector;
        if (!$reflection instanceof ReflectionClass) {
            return;
        }

        $definition = $this->extractor->extract($reflection);
        if ($definition === null) {
            return;
        }

        /** @var class-string $entry */
        $entry = $reflection->getName();
        $this->definitions[$entry] = $definition;

        if (($definition['kind'] ?? null) !== 'validator') {
            return;
        }

        $validator = $definition['class'] ?? null;
        if (!is_string($validator) || !is_a($validator, ValidatorInterface::class, true)) {
            throw new RuntimeException(sprintf(
                'Compiled validator service for "%s" must implement %s.',
                $entry,
                ValidatorInterface::class,
            ));
        }

        $validatorReflection = new ReflectionClass($validator);
        if ($validatorReflection->isInstantiable()) {
            /** @var class-string<ValidatorInterface> $validator */
            $this->validatorServices[$validator] = true;
        }
    }

    public function finalize(): void
    {
        if ($this->isFinalized) {
            throw ListenerAlreadyFinalizedException::forListener($this);
        }

        ksort($this->definitions);
        ksort($this->validatorServices);
        $this->isFinalized = true;
    }

    /** @return array{version: int, validators: array<class-string, array<string, mixed>>} */
    public function map(): array
    {
        $this->assertFinalized();

        return [
            'version' => ConfigKey::COMPILED_VALIDATORS_VERSION,
            'validators' => $this->definitions,
        ];
    }

    public function entries(): iterable
    {
        $this->assertFinalized();

        foreach (array_keys($this->validatorServices) as $validator) {
            yield new AutowireEntry($validator);
        }
    }

    private function assertFinalized(): void
    {
        if (!$this->isFinalized) {
            throw new RuntimeException('Validation discovery must be finalized before compilation.');
        }
    }
}
