# Psalm plugin for Laravel Nova

[![Latest Version on Packagist](https://img.shields.io/packagist/v/interaction-design-foundation/psalm-laravel-nova.svg)](https://packagist.org/packages/interaction-design-foundation/psalm-laravel-nova)
[![Total Downloads](https://img.shields.io/packagist/dt/interaction-design-foundation/psalm-laravel-nova.svg)](https://packagist.org/packages/interaction-design-foundation/psalm-laravel-nova)
[![License](https://img.shields.io/packagist/l/interaction-design-foundation/psalm-laravel-nova.svg)](LICENSE)

A [Psalm](https://psalm.dev) plugin that resolves [Laravel Nova](https://nova.laravel.com)'s magic: the reflective dispatch and `@method` annotations that static analysis cannot follow on its own. Works with Laravel apps and Nova packages.

> This plugin covers Nova only. Install it **in addition to** [`psalm/plugin-laravel`](https://github.com/psalm/psalm-plugin-laravel), which covers the Laravel framework itself. It is not a replacement.

Nova dispatches resources, actions, filters, lenses, cards, tools, dashboards and metrics through reflection and naming conventions that live entirely inside `laravel/nova`. Psalm cannot see those call sites, and several Nova base classes carry `@method` annotations whose signatures do not describe what their subclasses actually accept. The result is a stream of false positives on an otherwise clean Nova codebase.

This plugin removes them. Its guiding principle is **silence over false positives**: when a type cannot be resolved with confidence, the plugin declines and lets Psalm fall back to its own inference rather than guessing.

## Installation

```bash
composer require --dev interaction-design-foundation/psalm-laravel-nova
```

Then enable it:

```bash
vendor/bin/psalm-plugin enable interaction-design-foundation/psalm-laravel-nova
```

Keep [`psalm/plugin-laravel`](https://github.com/psalm/psalm-plugin-laravel) installed and enabled alongside it: this plugin handles Nova-specific conventions and relies on the Laravel plugin for everything else (Eloquent models, facades, container bindings).

## What it does

### `make()` calls are checked against the right constructor

`Laravel\Nova\Element` declares `@method static static make(string|null $component = null)` and `Laravel\Nova\Panel` declares its own differently-shaped `@method make(...)`. Psalm stores `@method` annotations as pseudo methods and, during static-call resolution, gives an ancestor's pseudo method priority over the class's own real (variadic) `make()`. Because almost no concrete Nova field or panel declares its own `@method make(...)` override, every `Text::make('Label', 'column')` resolves against `Element`'s single-optional-parameter signature and reports `TooManyArguments`.

`NovaMakeSignatureHandler` gives each class a `make()` signature derived from its own constructor.

### Resource query methods are narrowed to the resource's model

Nova's `Resource::indexQuery()`, `relatableQuery()` and friends receive a `Builder` that Psalm sees as `Builder<Model>`. `NovaResourceQueryMethodHandler` narrows it to the resource's concrete model, resolved in two steps:

1. the `@extends \Laravel\Nova\Resource<ConcreteModel>` template binding, including when inherited transitively through intermediate base classes;
2. the `public static $model = SomeModel::class` convention property, read from the AST when no template binding resolves.

The property value is validated (it must exist and be a genuine `Model` subclass) before narrowing, so a typo never produces a confidently wrong type.

### `$this->when(...)` no longer poisons `fields()`

`Illuminate\Http\Resources\ConditionallyLoadsAttributes::when()` returns `mixed`, which degrades the inferred element type of every array literal built with it, including Nova's `fields()` and `actions()`. `NovaWhenReturnTypeHandler` introspects the argument and returns the closure's return type (or the value's type) unioned with `MissingValue`. A literal `true`/`false` condition drops the dead branch. When the type cannot be resolved, the provider declines rather than leaking a raw `Closure` into the union.

### Reflectively dispatched members are not reported as unused

Under `findUnusedCode="true"`, members Nova reaches by reflection have no visible call site. `NovaSuppressHandler` covers the ones that stubs cannot express, mirroring what `Psalm\LaravelPlugin\Handlers\SuppressHandler` does for Laravel:

- `PossiblyUnusedMethod` on an action's `handle()`, which Nova dispatches through `method_exists()` and the container rather than a base-class declaration;
- `PossiblyUnusedMethod` on a resource's open-ended `relatable{FieldName}()` query methods;
- `PossiblyUnusedMethod` on the Gate methods (`viewAny`, `view`, `create`, …) of the policy a resource names in `public static $policy`, which Nova reaches via `authorizedTo()` → `Gate::callPolicyMethod()`;
- `NonInvariantPropertyType` on `$policy` itself, but only for declarations compatible with the stub's `string` — `public static int $policy` still raises.

Hook methods that override a base declaration (`fields()`, `apply()`, `calculate()`, …) already inherit the parent's "used" status from the stubs, so they need no suppression.

### Nova stubs

The plugin ships stubs for `Action`, `Field`, `Element`, `PartitionResult`, `Panel` and `Resource` that fix vendor signatures Psalm cannot resolve. `Resource` is templated, so a resource can declare its model with `@extends`:

```php
/** @extends \Laravel\Nova\Resource<\App\Models\User> */
final class User extends Resource
{
    public static $model = \App\Models\User::class;
}
```

The stubs are registered by the plugin itself; no `<stubs>` entry is needed in `psalm.xml`.

## Requirements

- PHP 8.4+
- Psalm 7.0+

`laravel/nova` is not a dependency: the plugin relies on its own stubs and resolves everything else from the analysed codebase.

## Contributing

Pull requests are welcome. Run the checks before opening one:

```bash
composer cs:fix     # auto-fix both
composer psalm
```

## License

[MIT](LICENSE). Copyright © The Interaction Design Foundation.
