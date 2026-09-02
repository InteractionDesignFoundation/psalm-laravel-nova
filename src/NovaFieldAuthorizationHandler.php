<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows `canSee()`'s callback parameter to `NovaRequest` for every `Field`-derived class, without
 * touching `Tool`/`Dashboard`/`Filters\Filter`/`Menu\*`, which share the same `AuthorizedToSee` trait
 * but can receive a plain `Illuminate\Http\Request` at runtime (`BootTools` middleware).
 *
 * A stub file cannot do this: `canSee()` is declared only on the `AuthorizedToSee` trait, and
 * `FieldElement`/`Field`/every concrete field class only *inherit* it (no class in that chain
 * redeclares it). A plugin stub can override a method the stubbed class itself declares, and can
 * add a genuinely new one, but — confirmed empirically against real Nova 5.10.1, redeclaring
 * `canSee()` on `Element.phpstub` (which actually `use`s the trait) or on `FieldElement.phpstub`
 * (which merely inherits it) — it cannot override a method the class only inherits: Psalm's
 * `Methods::getMethodParams()` resolves the call through `getDeclaringMethodId()`, which reads
 * `declaring_method_ids['cansee']` off the *called* class's own storage; that entry still points at
 * `AuthorizedToSee`/`Element` regardless of what the stub adds, so the stub's declaration is simply
 * never consulted. `MethodParamsProviderInterface` cannot fill the gap either: Psalm keys it by the
 * exact called class (`Methods::getMethodParams()`, `AtomicMethodCallAnalyzer::$fq_class_name`), with
 * no hierarchy walk, so it would need to enumerate every concrete Field subclass — impossible for an
 * open, user-extensible hierarchy (the same reason `MethodParamsProviderInterface` was already ruled
 * out for resolving a resource's model, see `NovaResourceQueryMethodHandler`).
 *
 * What does work, because it operates on the same storage fields `getDeclaringMethodId()` actually
 * reads: post-populate, for every class extending `FieldElement`, point that class's own
 * `declaring_method_ids['cansee']` at itself and give it its own `methods['cansee']` entry — a
 * narrowed clone of whatever `AuthorizedToSee::canSee()` currently declares. This is exactly what a
 * real `public function canSee(...)` override on that class would produce in storage, just built
 * programmatically instead of textually. Classes outside the `FieldElement` hierarchy are never
 * touched, so `Tool::canSee(fn(Request $request): bool => true)` keeps type-checking and
 * `Tool::canSee(fn(NovaRequest $request): bool => true)` keeps being rejected.
 * @internal
 */
final class NovaFieldAuthorizationHandler implements AfterCodebasePopulatedInterface
{
    private const FIELD_ELEMENT = 'laravel\nova\fields\fieldelement';

    private const CAN_SEE = 'cansee';

    private const NOVA_REQUEST = 'Laravel\Nova\Http\Requests\NovaRequest';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $isFieldElement = mb_strtolower($storage->name) === self::FIELD_ELEMENT
                || isset($storage->parent_classes[self::FIELD_ELEMENT]);
            if (!$isFieldElement) {
                continue;
            }

            self::narrowCanSee($codebase, $storage);
        }
    }

    private static function narrowCanSee(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $declaringId = $storage->declaring_method_ids[self::CAN_SEE] ?? null;
        if ($declaringId === null
            || mb_strtolower($declaringId->fq_class_name) === mb_strtolower($storage->name)
            || !$codebase->classlike_storage_provider->has($declaringId->fq_class_name)
        ) {
            // No canSee() to narrow, or the class already declares its own (leave user intent alone).
            return;
        }

        $declaringStorage = $codebase->methods->getStorage($declaringId);
        $narrowedCallback = self::narrowCallbackParam($declaringStorage);
        if ($narrowedCallback === null) {
            // Nova's canSee() shape changed in a way we don't recognise: silence over false positives.
            return;
        }

        $narrowed = clone $declaringStorage;
        $narrowed->params = [$narrowedCallback];

        $selfId = new MethodIdentifier($storage->name, self::CAN_SEE);
        $storage->methods[self::CAN_SEE] = $narrowed;
        $storage->declaring_method_ids[self::CAN_SEE] = $selfId;
        $storage->appearing_method_ids[self::CAN_SEE] = $selfId;
    }

    /**
     * `canSee(Closure $callback)`: rewrite the closure's own param type, not `$callback`'s.
     * @psalm-mutation-free
     */
    private static function narrowCallbackParam(MethodStorage $canSee): ?\Psalm\Storage\FunctionLikeParameter
    {
        $callbackParam = $canSee->params[0] ?? null;
        if ($callbackParam === null) {
            return null;
        }

        $callbackType = $callbackParam->type;
        if ($callbackType === null) {
            return null;
        }

        $closure = $callbackType->getSingleAtomic();
        if (!$closure instanceof TClosure || $closure->params === null || !isset($closure->params[0])) {
            return null;
        }

        $narrowedRequestParam = $closure->params[0]->setType(new Union([new TNamedObject(self::NOVA_REQUEST)]));
        $narrowedClosure = $closure->replace([$narrowedRequestParam], $closure->return_type);

        return $callbackParam->setType(new Union([$narrowedClosure]));
    }
}
