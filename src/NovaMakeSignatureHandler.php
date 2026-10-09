<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Gives every Nova `make()` call its own class's constructor signature, so that calls with the
 * right number/type of positional args (e.g. `Text::make('Label', 'column')`,
 * `BelongsTo::make('Label', 'rel', Resource::class)`) are checked against that class's actual
 * constructor instead of an unrelated ancestor's.
 *
 * Root cause: `Laravel\Nova\Element` declares `@method static static make(string|null $component = null)`
 * (one optional param) and `Laravel\Nova\Panel` declares its own differently-shaped `@method make(...)`.
 * Psalm stores `@method` annotations as pseudo methods. During static-call resolution, Psalm's
 * `AtomicStaticCallAnalyzer::findPseudoMethodAndClassStorages()` looks for a `pseudo_static_methods['make']`
 * entry on the CALLED class itself first, and only if that's absent does it walk `class_implements +
 * parent_classes` and use the FIRST ancestor that has one. Critically, `AtomicStaticCallAnalyzer::
 * handleNamedCall()` gives that pseudo entry priority over the class's own real `make()` method
 * (`Makeable::make(...$arguments)`) whenever a pseudo entry is found anywhere in the chain (see
 * `checkPseudoMethod()` / the `$found_method_and_class_storage` branch) — so a real, correctly-variadic
 * method is not enough to save a descendant from an ancestor's pseudo signature.
 *
 * Since almost every concrete Nova field/panel class does NOT declare its own `@method make(...)`
 * override, essentially all of them resolve through the ancestor walk to `Element`'s or `Panel`'s
 * one pseudo signature — which matches neither `Element`'s/`Panel`'s own constructor (they have none
 * beyond what `Makeable` proxies) nor, more importantly, the wildly different constructors of concrete
 * descendants (compare `Field::__construct($name, $attribute, $resolveCallback)` with
 * `BelongsTo::__construct($name, $attribute, $resource)`). A stub `@method` override on `Element`/`Panel`
 * cannot fix this by itself: it would just become the one signature every descendant is wrongly checked
 * against, trading `TooManyArguments` false positives for `InvalidArgument` ones.
 *
 * Fix: post-populate, for every class in the `Element`/`Panel` hierarchies (both are independent
 * make-able roots — `Panel` extends `Fields\FieldMergeValue`, not `Element`):
 *
 * 1. Resolve the class's *effective* constructor: its own `methods['__construct']`, or — if it has
 *    none of its own — the constructor storage of whichever ancestor `declaring_method_ids['__construct']`
 *    points to. If no constructor exists anywhere in the chain (e.g. `Element` itself), or the
 *    constructor has by-ref params (a shape unsafe to mirror onto a factory method), fall back to a
 *    single optional variadic `mixed ...$arguments` parameter — permissive, never a false positive.
 * 2. Wherever `make` already has an entry (in `methods`, `pseudo_methods`, or `pseudo_static_methods` —
 *    e.g. `Element`, `Field`, `Panel`, `ResourceTool`, `Tabs\TabsGroup`), that entry's params are
 *    rewritten in place to the class's own effective-constructor params (preserving its `static`
 *    return type). Note: `Tabs\Tab` extends `Fields\FieldMergeValue` directly — a *sibling* of `Panel`,
 *    not a descendant — so it's outside `MAKEABLE_ROOTS` and this handler never touches it; its own
 *    `@method make(...)` annotation already matches its own constructor and needs no fix.
 * 3. Every class that does NOT already have its own `pseudo_static_methods['make']` (i.e. every
 *    concrete field/relation/panel class that doesn't redeclare `@method make`) gets ONE synthesized:
 *    cloned from whichever root (`Element` or `Panel`) backs it — to inherit `cased_name`, `is_static`,
 *    the `static` return type, etc. — with `params`/`variadic` overwritten from step 1. Because
 *    `findPseudoMethodAndClassStorages()` checks the called class's OWN `pseudo_static_methods` bucket
 *    before walking ancestors, this guarantees every concrete class resolves `make()` against its own
 *    constructor, never an ancestor's. This is applied unconditionally (not only where the effective
 *    constructor provably differs from the ancestor's): the makeable universe is a few dozen Nova
 *    classes, scanned once post-populate, so the extra synthesized entries cost nothing measurable,
 *    and skipping "no-op" cases would require re-implementing Psalm's own ancestor walk just to save
 *    a handful of clones.
 *
 * 4. The resolve callback (`callable(mixed, mixed, ?string):mixed`, the 3rd constructor param of `Field`,
 *    `DateTime`, `Date`, `Email`, `ID`, ...) is re-typed on the synthesized `make()` as
 *    `callable(mixed, TResource, string):mixed`, with `TResource` a method-level template bounded exactly
 *    like the one `FieldElement.phpstub` uses for visibility callbacks. Nova calls it as
 *    `call_user_func($resolveCallback, $value, $resource, $attribute)` where `$attribute` is
 *    `$attribute ?? $this->attribute` (never null) and `$resource` is the resolved model/pivot/repeater
 *    row, so the idiomatic `fn (mixed $value, Post $resource, string $attribute)` is sound but was
 *    rejected twice over (`Post` vs `mixed` and `string` vs `?string` are both contravariance
 *    violations). The template can only live here: `make()` is a synthesized pseudo method, a `@method`
 *    annotation cannot declare templates, and the real constructor is the field class's own (often
 *    Nova's own, with its own docblock) — the constructor itself keeps Nova's wide signature, because
 *    narrowing it would need a stub redeclaring the constructor of every field class that has one.
 *    Constructors without a resolve callback of that exact shape are left untouched.
 *
 * `FunctionLikeParameter` instances are cloned per target `MethodStorage` (never shared) — Psalm
 * storages are mutated in place during analysis, so aliasing the same parameter object across two
 * methods would let a mutation on one bleed into the other.
 *
 * `Action` and `Filter` use the `Makeable` trait directly (they do not extend `Element`) and declare no
 * competing `@method make`, so their real variadic `make()` already resolves correctly and they need no fix.
 *
 * Note: `Fields\Field.phpstub`'s `@method make(...)` annotation (if any) becomes redundant/overwritten
 * input once this handler runs, since this handler rewrites `pseudo_static_methods['make']` on `Field`
 * directly from `Field`'s real constructor.
 * @internal
 */
final class NovaMakeSignatureHandler implements AfterCodebasePopulatedInterface
{
    /** Independent make-able roots: every descendant of either gets `make()` normalised. */
    private const MAKEABLE_ROOTS = [
        'laravel\nova\element',
        'laravel\nova\panel',
    ];

    private const MAKE = 'make';

    private const CONSTRUCT = '__construct';

    private const RESOURCE_TEMPLATE = 'TResource';

    /**
     * Keep in sync with the `@template TResource of ...` bound in `stubs/Nova/Fields/FieldElement.phpstub`
     * and `Field.phpstub`: the widest thing Nova hands a field as its resource.
     */
    private const RESOURCE_BOUND = 'Illuminate\\Database\\Eloquent\\Model|Laravel\\Nova\\Support\\Fluent|array<array-key, mixed>|object';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $rootsFlipped = array_flip(self::MAKEABLE_ROOTS);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $isMakeable = isset($rootsFlipped[mb_strtolower($storage->name)])
                || array_intersect_key($storage->parent_classes, $rootsFlipped) !== [];
            if (!$isMakeable) {
                continue;
            }

            [$sourceParams, $variadic] = self::resolveMakeSignature($codebase, $storage);

            $hasOwnPseudoStaticMake = isset($storage->pseudo_static_methods[self::MAKE]);

            // The real `methods` bucket is deliberately not touched: the real
            // `Makeable::make(...$arguments)` is already correctly variadic, and pseudo entries
            // take precedence over it during static-call resolution anyway.
            self::rewriteBucketMake($storage->pseudo_methods, $sourceParams, $variadic, $storage->name);
            self::rewriteBucketMake($storage->pseudo_static_methods, $sourceParams, $variadic, $storage->name);

            if (!$hasOwnPseudoStaticMake) {
                $template = self::resolveRootMakeTemplate($codebase, $storage, $rootsFlipped);
                if ($template !== null) {
                    $synthetic = clone $template;
                    self::applySignature($synthetic, $sourceParams, $variadic, $storage->name);
                    $storage->pseudo_static_methods[self::MAKE] = $synthetic;
                }
            }
        }
    }

    /**
     * Psalm's populator copies pseudo-method MethodStorage objects to descendants BY REFERENCE,
     * so a bucket entry here may be shared with dozens of other classes. Never mutate the found
     * object in place (the last-processed class's params would win globally): clone it, rewrite
     * the clone, and assign it back to THIS class's bucket only.
     * @param array<lowercase-string, \Psalm\Storage\MethodStorage> $bucket `pseudo_methods` or `pseudo_static_methods`, modified in place
     * @param list<\Psalm\Storage\FunctionLikeParameter> $sourceParams
     */
    private static function rewriteBucketMake(
        array &$bucket,
        array $sourceParams,
        bool $variadic,
        string $className
    ): void {
        $make = $bucket[self::MAKE] ?? null;
        if ($make === null) {
            return;
        }

        $replacement = clone $make;
        self::applySignature($replacement, $sourceParams, $variadic, $className);
        $bucket[self::MAKE] = $replacement;
    }

    /**
     * @return array{list<\Psalm\Storage\FunctionLikeParameter>, bool} Source params (to be cloned per target) and the variadic flag.
     * @psalm-mutation-free
     */
    private static function resolveMakeSignature(Codebase $codebase, ClassLikeStorage $storage): array
    {
        $constructor = self::resolveEffectiveConstructor($codebase, $storage);

        if ($constructor === null || $constructor->params === [] || !self::isSafeToMirror($constructor)) {
            return [[new FunctionLikeParameter('arguments', by_ref: false, is_optional: true, is_variadic: true)], true];
        }

        return [$constructor->params, $constructor->variadic];
    }

    /**
     * A by-ref param can't be safely reproduced on a synthetic `static::make()` factory signature.
     * @psalm-mutation-free
     */
    private static function isSafeToMirror(MethodStorage $constructor): bool
    {
        foreach ($constructor->params as $param) {
            if ($param->by_ref) {
                return false;
            }
        }

        return true;
    }

    /**
     * The class's own constructor, or — if it has none of its own — the one it inherits.
     * @psalm-mutation-free
     */
    private static function resolveEffectiveConstructor(Codebase $codebase, ClassLikeStorage $storage): ?MethodStorage
    {
        $own = $storage->methods[self::CONSTRUCT] ?? null;
        if ($own instanceof MethodStorage) {
            return $own;
        }

        $declaring = $storage->declaring_method_ids[self::CONSTRUCT] ?? null;
        if ($declaring === null || !$codebase->classlike_storage_provider->has($declaring->fq_class_name)) {
            return null;
        }

        $declaringStorage = $codebase->classlike_storage_provider->get($declaring->fq_class_name);

        return $declaringStorage->methods[$declaring->method_name] ?? null;
    }

    /**
     * A `make` MethodStorage to clone as a template for a class without its own (for its `cased_name`,
     * `is_static`, `static` return type, etc.) — taken from whichever makeable root backs this class.
     * @param array<lowercase-string, int> $rootsFlipped Hoisted `array_flip(self::MAKEABLE_ROOTS)`.
     * @psalm-mutation-free
     */
    private static function resolveRootMakeTemplate(
        Codebase $codebase,
        ClassLikeStorage $storage,
        array $rootsFlipped
    ): ?MethodStorage {
        if (isset($rootsFlipped[mb_strtolower($storage->name)])) {
            return self::findMakeStorage($storage);
        }

        foreach (self::MAKEABLE_ROOTS as $rootLc) {
            $rootFqcn = $storage->parent_classes[$rootLc] ?? null;
            if ($rootFqcn === null || !$codebase->classlike_storage_provider->has($rootFqcn)) {
                continue;
            }

            $template = self::findMakeStorage($codebase->classlike_storage_provider->get($rootFqcn));
            if ($template !== null) {
                return $template;
            }
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function findMakeStorage(ClassLikeStorage $storage): ?MethodStorage
    {
        return $storage->pseudo_static_methods[self::MAKE]
            ?? $storage->pseudo_methods[self::MAKE]
            ?? $storage->methods[self::MAKE]
            ?? null;
    }

    /** @param list<\Psalm\Storage\FunctionLikeParameter> $sourceParams */
    private static function applySignature(
        MethodStorage $make,
        array $sourceParams,
        bool $variadic,
        string $className
    ): void {
        $make->params = array_map(
            static function (FunctionLikeParameter $param): FunctionLikeParameter {
                $cloned = clone $param;
                // Constructor promotion is meaningless on a factory pseudo-method; carrying the
                // flag over could make Psalm treat make() args as property writes.
                $cloned->promoted_property = false;

                return $cloned;
            },
            $sourceParams
        );
        $make->variadic = $variadic;

        // `$make` may be a clone of an entry this handler already narrowed for another class.
        if ($make->template_types !== null) {
            unset($make->template_types[self::RESOURCE_TEMPLATE]);
            if ($make->template_types === []) {
                $make->template_types = null;
            }
        }

        self::narrowResolveCallback($make, $className);
    }

    /**
     * Re-types every Nova-shaped resolve callback param of `$make` (see class docblock, point 4) onto a
     * method-level `TResource` template. The template's `defining_class` follows Psalm's own
     * `fn-<lowercase method id>` convention for function-level templates.
     */
    private static function narrowResolveCallback(MethodStorage $make, string $className): void
    {
        $bound = Type::parseString(self::RESOURCE_BOUND);
        $definingClass = 'fn-'.mb_strtolower($className).'::make';
        $template = new Union(
            [new TTemplateParam(self::RESOURCE_TEMPLATE, $bound, $definingClass, from_docblock: true)],
            ['from_docblock' => true]
        );

        $narrowed = false;
        foreach ($make->params as $param) {
            if ($param->type === null) {
                continue;
            }

            $atomics = [];
            $changed = false;
            foreach ($param->type->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TCallable && self::isNovaResolveCallable($atomic)) {
                    $atomics[] = self::narrowCallable($atomic, $template);
                    $changed = true;
                } else {
                    $atomics[] = $atomic;
                }
            }

            if ($changed) {
                $param->type = new Union($atomics, ['from_docblock' => true]);
                $narrowed = true;
            }
        }

        if ($narrowed) {
            $make->template_types[self::RESOURCE_TEMPLATE] = [$definingClass => $bound];
        }
    }

    /**
     * Nova's docblock for the resolve callback: `callable(mixed, mixed, ?string)`.
     * @psalm-mutation-free
     */
    private static function isNovaResolveCallable(TCallable $callable): bool
    {
        if ($callable->params === null || \count($callable->params) !== 3) {
            return false;
        }

        [$value, $resource, $attribute] = $callable->params;

        return $value->type?->isMixed() === true
            && $resource->type?->isMixed() === true
            && $attribute->type !== null
            && $attribute->type->isNullable()
            && $attribute->type->hasString()
            && \count($attribute->type->getAtomicTypes()) === 2;
    }

    private static function narrowCallable(TCallable $callable, Union $resourceTemplate): TCallable
    {
        /** @var list<\Psalm\Storage\FunctionLikeParameter> $params guarded by isNovaResolveCallable() */
        $params = $callable->params;

        $resource = clone $params[1];
        $resource->type = $resourceTemplate;

        $attribute = clone $params[2];
        $attribute->type = Type::getString();

        return $callable->replace([$params[0], $resource, $attribute], $callable->return_type);
    }
}
