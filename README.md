# Componenta Validation App

Application build integration for Componenta validation.

Install componenta/validation-app and run the application's normal command:

~~~sh
php bin/console.php app:build
~~~

The ConfigProvider registers ValidationBuilder in app.builders. Its factory receives the shared source ClassIteratorInterface from App\ConfigKey::DISCOVERY_SOURCE and resolves the output through PathResolverInterface. Listing commands and requesting help keep build dependencies lazy. Include application and package DTO directories in the application's discovery configuration.

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

The generated PHP file returns:

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

MapValidationProvider returns null for an absent entry. The composite then asks the attribute provider. Development uses the same composition without the generated map. Missing, unreadable or malformed artifacts use attributes. Runtime never starts a build or writes artifacts. Exceptions while providing a validator propagate without retry.

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

Configure the output path with:

~~~php
use Componenta\Validation\App\ConfigKey;

return [ConfigKey::MAP_FILE => 'var/cache/build/validators.php'];
~~~

The builder collects the map before writing it, creates the output directory and atomically replaces one PHP file. The artifact contains only arrays and strings. Deploy it together with the matching source code.

## Local integration checks

~~~sh
composer --working-dir=integration install
composer --working-dir=integration test
composer --working-dir=integration test:validation
~~~

The integration project uses sibling repositories under packages through Composer path repositories. Standalone package tests also work after composer install in the package.
