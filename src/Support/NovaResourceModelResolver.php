<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova\Support;

use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;

/**
 * Resolves the concrete Eloquent model a Nova resource wraps, template binding preferred, static
 * `$model` property as fallback:
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
 * before use: this plugin's guiding principle is silence over false positives, so a garbage
 * `$model` value (typo, non-Model class) must never produce a confidently wrong type.
 * @internal
 */
final class NovaResourceModelResolver
{
    private const MODEL_PARENT_CLASS = 'illuminate\database\eloquent\model';

    private const MODEL_PROPERTY = 'model';

    /** Resource base class whose TModel binding carries the concrete model. */
    private const TEMPLATE_HOLDER = 'Laravel\Nova\Resource';

    /** @return class-string<\Illuminate\Database\Eloquent\Model>|null */
    public static function resolve(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        return NovaQueryBuilderParamNarrower::resolveTemplateModel($storage, self::TEMPLATE_HOLDER)
            ?? self::resolveFromStaticProperty($storage, $codebase);
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model>|null */
    private static function resolveFromStaticProperty(ClassLikeStorage $storage, Codebase $codebase): ?string
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
