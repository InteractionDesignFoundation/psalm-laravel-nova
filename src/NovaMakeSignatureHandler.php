<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;

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
            self::rewriteBucketMake($storage->pseudo_methods, $sourceParams, $variadic);
            self::rewriteBucketMake($storage->pseudo_static_methods, $sourceParams, $variadic);

            if (!$hasOwnPseudoStaticMake) {
                $template = self::resolveRootMakeTemplate($codebase, $storage, $rootsFlipped);
                if ($template !== null) {
                    $synthetic = clone $template;
                    self::applySignature($synthetic, $sourceParams, $variadic);
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
     * @param array<lowercase-string, MethodStorage> $bucket `pseudo_methods` or `pseudo_static_methods`, modified in place
     * @param list<FunctionLikeParameter> $sourceParams
     */
    private static function rewriteBucketMake(array &$bucket, array $sourceParams, bool $variadic): void
    {
        $make = $bucket[self::MAKE] ?? null;
        if ($make === null) {
            return;
        }

        $replacement = clone $make;
        self::applySignature($replacement, $sourceParams, $variadic);
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
    private static function applySignature(MethodStorage $make, array $sourceParams, bool $variadic): void
    {
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
    }
}
