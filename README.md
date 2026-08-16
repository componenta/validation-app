# Componenta Validation App

Application integration for `componenta/validation`. It discovers validation attributes in development and compiles them into a versioned production map during `app:build`.

## Installation

```bash
composer require componenta/validation-app
```

The package exposes `Componenta\Validation\App\ConfigProvider` through Composer metadata.

## Build pipeline

`ValidationDiscoveryIndex` receives the application class snapshot and records either:

- property rule descriptors for classes using `#[Required]`, `#[Validate]`, `#[When]`, `#[Unique]`, `#[Exists]`, and other validation attributes; or
- a validator service reference for classes using `#[ValidatedBy]`.

`ValidationMapCompiler` writes the versioned map to `Componenta\Validation\ConfigKey::COMPILED_VALIDATORS`. No validator classes are generated. At runtime the core package hydrates the descriptors through `RuleFactoryInterface` and creates the normal `Validator` through `ValidatorFactoryInterface`.

Concrete validator classes referenced by `#[ValidatedBy]` are also contributed as DI v4 autowiring roots. Interface validator ids are preserved as container service ids and are not sent to the autowiring compiler. Explicit aliases, factories, invokables, and services keep precedence over generated factories.

A class may not combine `#[ValidatedBy]` with property validation attributes. The build fails instead of silently choosing one source.

Run the production build with:

```bash
APP_ENV=development php bin/console.php app:build
```

The integration sets `ConfigKey::REQUIRE_COMPILED_VALIDATORS`. Consequently every non-development application fails during validation-provider creation when the compiled map is absent, malformed, or has an unsupported version. There is no silent reflection fallback in production; rebuild the application artifacts after changing validation attributes or validator service declarations.
