<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use InteractionDesignFoundation\PsalmLaravelNova\Support\NovaQueryBuilderParamNarrower;
use InteractionDesignFoundation\PsalmLaravelNova\Support\StaticClassPropertyResolver;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;

/**
 * Narrows the query-builder parameter of Nova resource query methods to the resource's model.
 *
 * The concrete model is resolved in two steps, template binding preferred, static `$model` property
 * as fallback:
 *  1. The resource's `@extends \Laravel\Nova\Resource<ConcreteModel>` binding, whether declared
 *     directly or inherited transitively through any intermediate templated base class. Psalm's
 *     Populator resolves `template_extended_params` for every ancestor, so the binding always lands
 *     under the `Laravel\Nova\Resource` key regardless of how many app-level base classes sit
 *     between the concrete resource and Nova's own class.
 *  2. Every Nova resource also declares `public static $model = SomeModel::class`, independent of
 *     whether `@extends` templating is used. When no template binding resolves, this convention
 *     property is read from the AST instead.
 *
 * Step 2's result is validated (must exist and be a genuine Model subclass, not bare Model itself)
 * before narrowing: this plugin's guiding principle is silence over false positives, so a garbage
 * `$model` value (typo, non-Model class) must never produce a narrowed, wrong type.
 * @see NovaQueryBuilderParamNarrower for the narrowing rationale and mechanics.
 * @internal
 */
final class NovaResourceQueryMethodHandler implements AfterCodebasePopulatedInterface
{
    private const NOVA_RESOURCE = 'laravel\nova\resource';

    private const MODEL_PARENT_CLASS = 'illuminate\database\eloquent\model';

    private const MODEL_PROPERTY = 'model';

    private const QUERY_METHODS = ['indexquery', 'detailquery', 'relatablequery'];

    /** Resource base class whose TModel binding carries the concrete model. */
    private const TEMPLATE_HOLDER = 'Laravel\Nova\Resource';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract || !isset($storage->parent_classes[self::NOVA_RESOURCE])) {
                continue;
            }

            $model = NovaQueryBuilderParamNarrower::resolveTemplateModel($storage, self::TEMPLATE_HOLDER)
                ?? self::resolveModelFromStaticProperty($storage, $codebase);

            if ($model === null) {
                continue;
            }

            NovaQueryBuilderParamNarrower::narrowMethods($storage, $model, self::QUERY_METHODS);
        }
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model>|null */
    private static function resolveModelFromStaticProperty(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        $modelClass = StaticClassPropertyResolver::resolve($storage, $codebase, self::MODEL_PROPERTY);
        if ($modelClass === null || !$codebase->classlike_storage_provider->has($modelClass)) {
            return null;
        }

        $modelStorage = $codebase->classlike_storage_provider->get($modelClass);
        if (!isset($modelStorage->parent_classes[self::MODEL_PARENT_CLASS])) {
            return null;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> */
        return $modelClass;
    }
}
