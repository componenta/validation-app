# Componenta Validation App

Application build integration for Componenta Validation 3.2+, App 6, Config 4 and DI 6. Validation App 4 requires PHP 8.5.13 or later.

Install componenta/validation-app and run the application's normal command:

~~~sh
php bin/console.php app:build
~~~

The ConfigProvider registers ValidationBuilder in app.builders. Its factory receives the shared source ClassIteratorInterface from App\ConfigKey::DISCOVERY_SOURCE. App owns artifact storage and publication. Listing commands and requesting help keep build dependencies lazy. Include application and package DTO directories in the application's discovery configuration.

## Generated map

The builder exports complete validation definitions for discovered classes and enums. The supported attributes are the exact built-in Validate, Field and ValidatedBy classes, including their PHP class aliases and case-insensitive names.

~~~php
final class RegistrationDto
{
    #[Validate('required|email', as: 'ignored')]
    #[Field('email')]
    public string $address;
}

#[ValidatedBy(RegistrationValidator::class)]
final class ExternalRegistrationDto {}
~~~

The `validation.rules` artifact payload contains:

~~~php
return [
    RegistrationDto::class => ['email' => 'required|email'],
    ExternalRegistrationDto::class => RegistrationValidator::class,
];
~~~

Field overrides Validate::as, which overrides the property name. Field alone does not define validation. The map preserves property order. Classes without validation are omitted.

Supported arguments are string literals, class-name expressions such as RegistrationValidator::class, and null for Validate::as. Constants requiring evaluation, nested new expressions, custom marker subclasses and other rule attributes remain in the attribute provider. The source parser only checks whether arguments can be represented as data; the builder does not evaluate arguments or construct attributes, rules or validators.

A class is exported only if its entire validation definition can be represented. A DTO combining Validate with Length, Required, When, a custom RuleAttribute or any other RuleInterface attribute delegates all its rules to AttributeValidationProvider. Invalid declarations also remain on the native path so construction errors retain their timing and behavior.

MapValidationProvider creates rules through the injected RuleFactoryInterface and passes them to ValidatorFactoryInterface::createFrom(). Service entries are resolved through ValidatorFactoryInterface::create() on each provide() call; service lifetimes are owned by the existing container.

## Runtime composition

The default factory creates CompositeValidationProvider with this priority:

1. ValidatableProvider.
2. Explicit service registrations in ConfigKey::VALIDATORS_MAP.
3. MapValidationProvider in production when a valid artifact is available.
4. AttributeValidationProvider.

MapValidationProvider returns null for an absent entry. The composite then asks the attribute provider. Development and `APP_DISCOVERY_SOURCE=1` use the same composition without the generated map. Production reads `validation.rules` only from the `PreparedBuild` selected by App. Missing, skipped, incompatible or malformed artifacts use attributes. The optional snapshot is checked through `App\ConfigKey::PREPARED_BUILD`, not DI class autowiring. Runtime never starts a build or writes artifacts. Exceptions while providing a validator propagate without retry.

The current rule factory, service registrations, database/MIME dependencies, formatter and locale apply in both environments. Every provide() creates fresh attribute rules and nested arguments. Repeated validate() calls on one validator preserve that validator's state.

## Application providers

An application can replace ValidationProviderInterface through its ConfigProvider, registered after the package providers:

~~~php
protected function getFactories(): array
{
    return [
        ValidationProviderInterface::class => ApplicationValidationProviderFactory::class,
    ];
}
~~~

That factory supplies the entire provider. The default composite is not constructed or added around it, even if the application provider returns null. A factory can also return an existing provider instance. Registering both a service and a factory under the same ID is rejected by DI; replace the factory binding or resolve the existing instance from a separate service ID.

## Publication

`ValidationBuilder::build(BuildContext $build)` writes the complete map as
`validation.rules`, schema version 1. Its constructor only receives the source
class iterator. The payload contains arrays and strings; runtime attribute
instances, rules, callbacks and services are never serialized.

App stages artifacts from all participants and publishes one generation after
every participant succeeds. A failure leaves the previous generation selected.
Deploy the generation with its matching source and configuration; `APP_BUILD_ID`
can identify that deployment. A structurally valid snapshot is deliberately
retained until a rebuild or `app:clean`; runtime does not scan source for changes.
Existing long-running readers retain their selected generation after cleanup.

## Migration to Validation App 4

Remove `validation.map_file` and `Componenta\Validation\App\ConfigKey` settings.
Independent `validators.php` files are no longer read or written. Remove old files
from deployments and run the application build again. Custom code calling
`ValidationBuilder` must supply `BuildContext` to `build()` and remove the former
filename constructor argument. Package-level `clean()` was removed; cleanup is
owned by App's `BuildStore` and `app:clean`.

## Local integration checks

~~~sh
composer --working-dir=integration install
composer --working-dir=integration test
composer --working-dir=integration test:validation
~~~

The integration project uses sibling repositories under packages through Composer path repositories. Standalone package tests also work after composer install in the package.
