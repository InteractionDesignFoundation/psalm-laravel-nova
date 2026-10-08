<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use InteractionDesignFoundation\PsalmLaravelNova\Support\NovaResourceModelResolver;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Lets a resource's property reads resolve against its model's property set, the way Nova's
 * `DelegatesToResource::__get()` forwards them at runtime.
 *
 * Nova declares `@mixin TModel` on `Resource`, but Psalm resolves property lookups only against
 * *named* mixins; a templated mixin is honoured for method calls alone. So `$this->headline` inside
 * `PostResource` and `$post->headline` in a policy that receives the resource (which is what Nova
 * passes whenever the resource declares `public static $policy`) are reported as undefined even
 * though the model carries `@property string $headline`.
 *
 * Once the codebase is populated, every `Resource` descendant whose model resolves (see
 * {@see NovaResourceModelResolver}) gets that model appended to its `namedMixins`, which is the field
 * Psalm's property-fetch analyzer reads. `mixin_declaring_fqcln` is already inherited from `Resource`
 * and is left alone. Registered per class rather than on `Resource` because a named mixin cannot
 * carry a template parameter, and per class rather than on abstract bases only because Psalm copies
 * `namedMixins` down during populate, which has already happened by the time this hook runs.
 *
 * A property the model does not declare is still reported. A property `Resource` itself declares
 * stays resolved against the resource first, as at runtime.
 * @internal
 */
final class NovaResourcePropertyForwardingHandler implements AfterCodebasePopulatedInterface
{
    private const NOVA_RESOURCE = 'laravel\nova\resource';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!isset($storage->parent_classes[self::NOVA_RESOURCE])) {
                continue;
            }

            $model = NovaResourceModelResolver::resolve($storage, $codebase);
            if ($model === null) {
                continue;
            }

            foreach ($storage->namedMixins as $mixin) {
                if (mb_strtolower($mixin->value) === mb_strtolower($model)) {
                    continue 2;
                }
            }

            $storage->namedMixins[] = new TNamedObject($model);
        }
    }
}
