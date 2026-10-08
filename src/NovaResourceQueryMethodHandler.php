<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use InteractionDesignFoundation\PsalmLaravelNova\Support\NovaQueryBuilderParamNarrower;
use InteractionDesignFoundation\PsalmLaravelNova\Support\NovaResourceModelResolver;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Narrows the query-builder parameter of Nova resource query methods to the resource's model.
 *
 * The model is resolved by {@see NovaResourceModelResolver}: the `@extends` template binding first,
 * the `public static $model` convention property as fallback.
 * @see NovaQueryBuilderParamNarrower for the narrowing rationale and mechanics.
 * @internal
 */
final class NovaResourceQueryMethodHandler implements AfterCodebasePopulatedInterface
{
    private const NOVA_RESOURCE = 'laravel\nova\resource';

    private const QUERY_METHODS = ['indexquery', 'detailquery', 'relatablequery'];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract || !isset($storage->parent_classes[self::NOVA_RESOURCE])) {
                continue;
            }

            $model = NovaResourceModelResolver::resolve($storage, $codebase);

            if ($model === null) {
                continue;
            }

            NovaQueryBuilderParamNarrower::narrowMethods($storage, $model, self::QUERY_METHODS);
        }
    }
}
