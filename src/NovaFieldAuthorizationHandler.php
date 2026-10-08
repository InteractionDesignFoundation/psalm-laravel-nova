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
 * Narrows `canSee()` to `NovaRequest` for every `FieldElement`, `Actions\Action`, `Filters\Filter` and
 * `Lenses\Lens` descendant — the classes whose `authorizedToSee()` Nova only ever calls with a
 * `NovaRequest` (verified against Nova 5.11) — leaving `Tool`/`Dashboard`/`Menu\*` (same
 * `AuthorizedToSee` trait, but resolved with a plain `Request` by `BootTools`) untouched.
 * A stub can't do this — `canSee()` is only inherited, never declared, on the classes in between, and
 * a stub can override a declared method but not an inherited one (confirmed against real Nova). This
 * rewrites `declaring_method_ids`/`methods` directly instead — the fields Psalm's method resolution
 * actually reads — the same way `NovaResourceQueryMethodHandler` narrows query-builder params.
 * @internal
 */
final class NovaFieldAuthorizationHandler implements AfterCodebasePopulatedInterface
{
    /** Lower-cased roots whose descendants only ever see a `NovaRequest` in `authorizedToSee()`. */
    private const NOVA_REQUEST_ROOTS = [
        'laravel\nova\fields\fieldelement',
        'laravel\nova\actions\action',
        'laravel\nova\filters\filter',
        'laravel\nova\lenses\lens',
    ];

    private const AUTHORIZED_TO_SEE = 'laravel\nova\authorizedtosee';

    private const CAN_SEE = 'cansee';

    private const NOVA_REQUEST = 'Laravel\Nova\Http\Requests\NovaRequest';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (self::isNovaRequestOnly($storage)) {
                self::narrowCanSee($codebase, $storage);
            }
        }
    }

    /** @psalm-mutation-free */
    private static function isNovaRequestOnly(ClassLikeStorage $storage): bool
    {
        foreach (self::NOVA_REQUEST_ROOTS as $root) {
            if (mb_strtolower($storage->name) === $root || isset($storage->parent_classes[$root])) {
                return true;
            }
        }

        return false;
    }

    private static function narrowCanSee(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $declaringId = $storage->declaring_method_ids[self::CAN_SEE] ?? null;
        if ($declaringId === null || mb_strtolower($declaringId->fq_class_name) !== self::AUTHORIZED_TO_SEE) {
            // canSee() is missing, or a user class overrode it somewhere in the chain — only ever
            // narrow Nova's own trait method, never second-guess a user's own override.
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
