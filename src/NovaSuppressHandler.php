<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use InteractionDesignFoundation\PsalmLaravelNova\Support\StaticClassPropertyResolver;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\PropertyStorage;

/**
 * Suppresses PossiblyUnusedProperty / PossiblyUnusedMethod for Laravel Nova conventions.
 *
 * Nova dispatches Resources, Actions, Filters, Lenses, Cards, Tools, Dashboards and Metrics through
 * reflection and naming conventions that live entirely in `laravel/nova`, not `laravel/framework`, so
 * neither Psalm nor `psalm/plugin-laravel` can see the call sites. Under `findUnusedCode="true"` every
 * convention property (`$model`, `$policy`, …) and hook method (`fields()`, `handle()`, …) is reported
 * as unused. This mirrors `Psalm\LaravelPlugin\Handlers\SuppressHandler` (the data-table approach), but
 * for Nova base classes.
 *
 * All work happens in afterCodebasePopulated: parent_classes is fully resolved there, and re-reading the
 * AST for the `$policy` bridge stays in the main process (a static map populated during forked scanning
 * would not survive serialisation back to the analysis phase).
 *
 * Preferred order of mechanisms: declare convention members directly on the Nova base-class stub when
 * possible (a subclass's override then inherits the parent's "used" status for free, with no suppression
 * needed here). Fall back to the programmatic suppression below only for members that cannot live on a
 * stub — e.g. the open-ended `relatable*` methods, whose names are not fixed in advance.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/867
 * @internal
 */
final class NovaSuppressHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Hook methods Nova invokes by reflection that are *not* declared on the base class (so they are
     * reported as unused rather than inheriting the parent's "used" status). Keyed by parent FQCN then
     * lowercase method names.
     *
     * `Laravel\Nova\Actions\Action` declares `handleRequest()` / `handleUsing()` but not `handle()`:
     * Nova calls the user's `handle()` via `method_exists()` + container dispatch, so a Nova action's
     * `handle()` is a new method with no visible caller. Resource/Filter/Lens/Metric hook methods
     * (`fields()`, `apply()`, `calculate()`, …) override base declarations and are therefore already
     * considered used — suppressing them would trip `UnusedPsalmSuppress`.
     */
    private const METHOD_LEVEL_BY_PARENT_CLASS = [
        'laravel\nova\actions\action' => [
            'handle',
        ],
    ];

    private const NOVA_RESOURCE = 'laravel\nova\resource';

    private const POLICY_PROPERTY = 'policy';

    /**
     * Gate policy methods Nova routes authorization through, via
     * `Resource::authorizedTo($request, $ability)` -> `Gate::callPolicyMethod()`. There is no direct
     * `$policy->create(...)` call site, so Psalm reports each as PossiblyUnusedMethod.
     */
    private const POLICY_METHODS = [
        'viewany',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'forcedelete',
        'replicate',
        'runaction',
        'rundestructiveaction',
    ];

    /**
     * Nova convention properties Nova assigns through a constructor, a setter or reflection —
     * never at declaration — yet declares with a `@var` docblock (or a native nullable) and no
     * default. Every concrete subclass that does not redeclare them is reported
     * PropertyNotSetInConstructor. Keyed by the FQCN that declares the property.
     *
     * `$name` is the display name on Fields, Actions, Metrics, Filters and Lenses; a Field's
     * `$attribute` and `$resource` are set during resolution; a trend result's `$prefix`/
     * `$suffix`/`$format` are set when the metric builds its result; `$dependentShouldEmitChangesEvent`
     * lives on the `DependentFields` trait and is populated lazily by `dependentShouldEmit()`.
     *
     * Marking each initialized on its declaring storage (see markFrameworkInitializedProperties)
     * mirrors psalm/plugin-laravel's `Handlers\Diagnostics\SuppressHandler`: it is per-property
     * precise, so a subclass's OWN un-initialised typed property still reports. Preferred over a
     * stub default (whose value would be fiction, and which trips NonInvariantPropertyType on
     * subclasses that redeclare the property untyped) and over class-level issue suppression
     * (which would hide real bugs).
     */
    private const FRAMEWORK_INITIALIZED_PROPERTIES_BY_FQCN = [
        'Laravel\Nova\Fields\Field' => ['name', 'attribute', 'resource', 'dependentShouldEmitChangesEvent'],
        'Laravel\Nova\Actions\Action' => ['name'],
        'Laravel\Nova\Filters\Filter' => ['name'],
        'Laravel\Nova\Lenses\Lens' => ['name'],
        'Laravel\Nova\Metrics\Metric' => ['name'],
        'Laravel\Nova\Metrics\TrendResult' => ['prefix', 'suffix', 'format'],
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $provider = $codebase->classlike_storage_provider;

        self::markFrameworkInitializedProperties($provider);

        foreach ($provider::getAll() as $classStorage) {
            if (!$classStorage->user_defined || $classStorage->is_interface) {
                continue;
            }

            self::suppressHookMethods($classStorage);

            if (isset($classStorage->parent_classes[self::NOVA_RESOURCE])) {
                self::suppressResourceRelatableMethods($classStorage);

                $policyProperty = $classStorage->properties[self::POLICY_PROPERTY] ?? null;
                if ($policyProperty instanceof PropertyStorage) {
                    // The stub declares `public static string $policy` on the Nova Resource base
                    // (see Resource.phpstub). Psalm requires overridden property types to be
                    // invariant on the NATIVE signature, so a subclass declaring the property
                    // untyped (`public static $policy = X::class`) or nullable would raise
                    // NonInvariantPropertyType even though both styles are legitimate (Nova
                    // reads the property via `property_exists(...) && !is_null(...)`). The
                    // stub property is our own invention, so the variance check carries no
                    // signal for those shapes — but ONLY for those: a declaration like
                    // `public static int $policy` is genuinely wrong and must keep the issue.
                    if (self::hasStringCompatiblePolicyShape($policyProperty, $classStorage, $codebase)) {
                        self::suppress('NonInvariantPropertyType', $policyProperty);
                    }

                    self::suppressPolicyMethods($classStorage, $codebase);
                }
            }
        }
    }

    /**
     * Whether the subclass `$policy` declaration is a shape whose variance mismatch against the
     * stub's `string` is our own artifact: natively `string`/`?string`, or untyped with a
     * string-compatible docblock or a `SomePolicy::class` initializer. An untyped declaration
     * with a non-class-string initializer (e.g. `public static $policy = 123`) is genuinely
     * wrong and must keep its NonInvariantPropertyType issue.
     */
    private static function hasStringCompatiblePolicyShape(
        PropertyStorage $propertyStorage,
        ClassLikeStorage $classStorage,
        Codebase $codebase
    ): bool {
        $declaredType = $propertyStorage->signature_type ?? $propertyStorage->type;
        if ($declaredType !== null) {
            foreach ($declaredType->getAtomicTypes() as $atomic) {
                if (!$atomic instanceof \Psalm\Type\Atomic\TString && !$atomic instanceof \Psalm\Type\Atomic\TNull) {
                    return false;
                }
            }

            return true;
        }

        // Untyped and no docblock: trust the declaration only when its initializer is a real
        // `SomePolicy::class` constant (the same AST read used for policy-method suppression).
        return StaticClassPropertyResolver::resolve($classStorage, $codebase, self::POLICY_PROPERTY) !== null;
    }

    /**
     * Flag each Nova convention property as initialised on the class that declares it, so
     * PropertyNotSetInConstructor is not raised on subclasses that inherit it without a redeclaration.
     */
    /**
     * Mark framework-initialized properties as initialized on the class that declares them.
     *
     * `declaring_property_ids` is populated by Psalm's Populator after inheritance resolution, so
     * a property inherited via a trait points at the trait's storage and a property declared on
     * the parent class points at the parent. Writing `initialized_properties[$name] = true` there
     * is the same signal Psalm emits for properties with a default value, which the
     * PropertyNotSetInConstructor check honours without further configuration. The user's own
     * declared (and genuinely un-initialised) properties are unaffected.
     */
    private static function markFrameworkInitializedProperties(ClassLikeStorageProvider $provider): void
    {
        foreach (self::FRAMEWORK_INITIALIZED_PROPERTIES_BY_FQCN as $className => $propertyNames) {
            if (!$provider->has($className)) {
                continue;
            }

            $classStorage = $provider->get($className);

            foreach ($propertyNames as $propertyName) {
                $declaringClass = $classStorage->declaring_property_ids[$propertyName] ?? null;
                if ($declaringClass === null) {
                    continue;
                }

                if (!$provider->has($declaringClass)) {
                    continue;
                }

                self::markPropertyInitialized($provider->get($declaringClass), $propertyName);
            }
        }
    }

    private static function markPropertyInitialized(ClassLikeStorage $storage, string $propertyName): void
    {
        $storage->initialized_properties[$propertyName] = true;
    }

    private static function suppressHookMethods(ClassLikeStorage $classStorage): void
    {
        foreach (self::METHOD_LEVEL_BY_PARENT_CLASS as $parentClass => $methodNames) {
            if (!isset($classStorage->parent_classes[$parentClass])) {
                continue;
            }

            foreach ($methodNames as $methodName) {
                $methodStorage = $classStorage->methods[$methodName] ?? null;
                if ($methodStorage instanceof MethodStorage) {
                    self::suppressPublicMethod($methodStorage);
                }
            }
        }
    }

    /**
     * Suppress PossiblyUnusedMethod for `relatable{Resources}()` query methods on a Resource.
     *
     * Nova resolves `static::relatable{FieldName}($request, $query)` by reflection when building the
     * options for a relationship field (BelongsToMany / MorphToMany / HasMany etc.), so there is no
     * direct call site for Psalm to discover. The name is open-ended (one method per relationship
     * field), so match the prefix rather than enumerate. Method keys are stored lowercase.
     *
     * `relatableQuery` itself is excluded: it overrides `Laravel\Nova\Resource::relatableQuery`, so it
     * inherits the parent's "used" status and is never flagged — suppressing it would be unused.
     *
     * Known limitation: the prefix match cannot distinguish a genuine `relatable{Field}()` hook from a
     * method that merely happens to start with "relatable" but corresponds to no relationship field, so
     * a truly unused method with that name would be silently suppressed too. Accepted trade-off: the
     * name collision is improbable, and a false PossiblyUnusedMethod on a real Nova hook is worse.
     */
    private static function suppressResourceRelatableMethods(ClassLikeStorage $classStorage): void
    {
        foreach ($classStorage->methods as $methodName => $methodStorage) {
            if (\str_starts_with($methodName, 'relatable') && $methodName !== 'relatablequery') {
                self::suppressPublicMethod($methodStorage);
            }
        }
    }

    /**
     * Follow the Resource's `public static $policy = SomePolicy::class` to the policy FQCN and suppress
     * the standard Gate methods on it. The value is read from the AST: a typed `$policy` property keeps
     * its declared `string` type in storage, so the concrete class-string is not recoverable there.
     */
    private static function suppressPolicyMethods(ClassLikeStorage $classStorage, Codebase $codebase): void
    {
        $policyClass = StaticClassPropertyResolver::resolve($classStorage, $codebase, self::POLICY_PROPERTY);
        if ($policyClass === null) {
            return;
        }

        $provider = $codebase->classlike_storage_provider;
        if (!$provider->has($policyClass)) {
            return;
        }

        $policyStorage = $provider->get($policyClass);
        foreach (self::POLICY_METHODS as $methodName) {
            $methodStorage = $policyStorage->methods[$methodName] ?? null;
            if ($methodStorage instanceof MethodStorage) {
                self::suppressPublicMethod($methodStorage);
            }
        }
    }

    /** Suppress only public methods: Nova dispatches its hooks via reflection / `$instance->method()`, which requires public visibility. A non-public override is a real bug. */
    private static function suppressPublicMethod(MethodStorage $methodStorage): void
    {
        if ($methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return;
        }

        self::suppress('PossiblyUnusedMethod', $methodStorage);
    }

    private static function suppress(string $issue, MethodStorage | PropertyStorage $storage): void
    {
        if (!\in_array($issue, $storage->suppressed_issues, true)) {
            $storage->suppressed_issues[] = $issue;
        }
    }
}
