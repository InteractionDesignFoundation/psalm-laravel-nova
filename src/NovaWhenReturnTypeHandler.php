<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use Psalm\Internal\Type\TypeCombiner;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * `Illuminate\Http\Resources\ConditionallyLoadsAttributes::when()` is typed `mixed`, which poisons the
 * inferred element type of any resource array literal built with `$this->when(...)` (Nova `fields()`,
 * `actions()`, ...). A docblock cannot fix it: `when()` evaluates a *closure* value, and the natural
 * `(\Closure():T)|T` parameter makes Psalm bind `T` to both the closure's return *and* the closure
 * itself, leaving the raw `Closure` in the result. A return-type provider can introspect the argument
 * instead and return the closure's return type (or the value's type) unioned with `MissingValue`.
 *
 * If a closure/callable value's (or default's) return type cannot be resolved, the provider declines entirely
 * (returns `null`) rather than unioning in the raw `Closure`/`callable` atomic: a half-known union
 * is worse than falling back to Psalm's own (poisoned) declared signature, because it would silently
 * reintroduce the exact leak this handler exists to prevent.
 *
 * When the condition argument is a compile-time-literal `true` or `false` (not merely truthy/falsy —
 * only the single-atomic `TTrue`/`TFalse` case is trusted), the dead branch is dropped instead of
 * unioned in: a literal `true` condition returns only the value/closure-return type, a literal `false`
 * condition returns only the default (or `MissingValue`).
 *
 * Laravel-core behaviour (not Nova-specific); registered on `Laravel\Nova\Resource` so it covers every
 * Nova resource. Belongs upstream in psalm-plugin-laravel for all API resources.
 * @internal
 */
final class NovaWhenReturnTypeHandler implements MethodReturnTypeProviderInterface
{
    private const MISSING_VALUE = 'Illuminate\Http\Resources\MissingValue';

    /** @psalm-pure */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            'illuminate\http\resources\conditionallyloadsattributes', // trait that declares when()
            'laravel\nova\resource',
        ];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'when') {
            return null;
        }

        $args = $event->getCallArgs();
        if (!isset($args[0], $args[1])) { // when($condition, $value, $default = new MissingValue)
            return null;
        }

        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();
        $valueType = $nodeTypeProvider->getType($args[1]->value);
        if ($valueType === null) {
            return null;
        }

        $valueTypes = self::unwrapEvaluatedAtomics($valueType);
        if ($valueTypes === null) {
            return null;
        }

        // Falls back to MissingValue both when $default is omitted and when its type can't be
        // resolved, so this list is never empty (TypeCombiner::combine() requires at least one atomic).
        $defaultTypes = [new TNamedObject(self::MISSING_VALUE)];
        if (isset($args[2])) {
            $defaultType = $nodeTypeProvider->getType($args[2]->value);
            if ($defaultType !== null) {
                // The failed-condition branch runs the default through `value($default)`, which
                // invokes closures — so the default needs the same unwrapping as the value.
                $defaultTypes = self::unwrapEvaluatedAtomics($defaultType);
                if ($defaultTypes === null) {
                    return null;
                }
            }
        }

        $conditionType = $nodeTypeProvider->getType($args[0]->value);
        $conditionIsLiteralTrue = $conditionType !== null && $conditionType->isSingle()
            && $conditionType->getSingleAtomic() instanceof TTrue;
        $conditionIsLiteralFalse = $conditionType !== null && $conditionType->isSingle()
            && $conditionType->getSingleAtomic() instanceof TFalse;

        if ($conditionIsLiteralTrue) {
            $types = $valueTypes;
        } elseif ($conditionIsLiteralFalse) {
            $types = $defaultTypes;
        } else {
            $types = [...$valueTypes, ...$defaultTypes];
        }

        $codebase = $event->getSource()->getCodebase();

        return TypeCombiner::combine($types, $codebase);
    }

    /**
     * Unwrap atomics to what when()'s `value()` pass produces at runtime. `value()` invokes
     * `instanceof Closure` ONLY, so:
     * - TClosure: replaced by its return-type atomics (invoked for certain);
     * - TCallable: kept AND its return-type atomics are added — a value typed `callable` may be
     * a Closure instance (invoked) or a callable-string/array (passed through unchanged), so
     * the union of both outcomes is the only sound answer;
     * - anything else: kept as-is.
     * Returns null when an invocable atomic's return type is unresolvable — the caller must
     * decline entirely rather than guess.
     * @return non-empty-list<\Psalm\Type\Atomic>|null
     * @psalm-mutation-free
     */
    private static function unwrapEvaluatedAtomics(Union $type): ?array
    {
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TClosure) {
                if ($atomic->return_type === null) {
                    return null;
                }

                $atomics = [...$atomics, ...array_values($atomic->return_type->getAtomicTypes())];
            } elseif ($atomic instanceof TCallable) {
                if ($atomic->return_type === null) {
                    return null; // Might be a Closure at runtime; its invoked result is unknowable.
                }

                $atomics = [$atomic, ...$atomics, ...array_values($atomic->return_type->getAtomicTypes())];
            } else {
                $atomics[] = $atomic;
            }
        }

        return $atomics;
    }
}
