<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova\Support;

use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Shared logic for narrowing a Nova-supplied query-builder parameter to the resource's model.
 *
 * Nova types the query parameter of resource query methods (indexQuery, detailQuery,
 * relatableQuery) as the bare contract builder (\Illuminate\Contracts\Database\Eloquent\Builder),
 * so inside the body model scopes are unknown (UndefinedInterfaceMethod) and a concrete
 * `@return Builder<Model>` triggers MoreSpecificReturnType. The parameter type cannot be narrowed
 * in PHP (parameters are contravariant, Nova's signature wins), so we rewrite it in Psalm's storage
 * to \Illuminate\Database\Eloquent\Builder<ConcreteModel>. Body analysis then resolves the builder
 * chain against the concrete model and psalm-plugin-laravel resolves the scopes.
 *
 * Must run post-populate so the parent chain (and the template binding) is resolved; storage
 * mutated here is read during the later analysis phase.
 * @internal
 */
final class NovaQueryBuilderParamNarrower
{
    private const ELOQUENT_BUILDER = 'Illuminate\Database\Eloquent\Builder';
    private const CONTRACT_BUILDER = 'illuminate\contracts\database\eloquent\builder';
    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    /**
     * Rewrite the contract-builder parameter of each named method to Builder<$modelFqcn>.
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelFqcn
     * @param list<lowercase-string> $methodNames
     */
    public static function narrowMethods(ClassLikeStorage $storage, string $modelFqcn, array $methodNames): void
    {
        $builderType = new Union([
            new TGenericObject(self::ELOQUENT_BUILDER, [new Union([new TNamedObject($modelFqcn)])]),
        ]);

        foreach ($methodNames as $methodName) {
            $methodStorage = $storage->methods[$methodName] ?? null;
            if ($methodStorage === null) {
                continue;
            }

            $narrowed = false;
            foreach ($methodStorage->params as $param) {
                if (self::isBareBuilderParam($param->type)) {
                    $param->type = $builderType;
                    $narrowed = true;
                } elseif (self::isGenericBuilderParam($param->type)) {
                    // The user already narrowed the param themselves (an explicit
                    // `@param Builder<SomeModel> $query` docblock). Leave their type alone, but
                    // still suppress below: their narrowing conflicts with Nova's contract-builder
                    // signature exactly the way ours does, and is just as sound.
                    $narrowed = true;
                }
            }

            if ($narrowed && $methodStorage->location !== null) {
                // The narrowed Builder<Model> param is now more specific than Nova's contract-builder
                // parameter on the parent, which Psalm reports as MoreSpecificImplementedParamType.
                // The narrowing is sound: Nova always passes the resource's own Eloquent builder.
                // Suppress just that issue, just on this method. The key must be an int file-offset
                // (Psalm tracks it for unused-suppression detection); narrowing always provokes the
                // issue, so the suppression is always used.
                $methodStorage->suppressed_issues[$methodStorage->location->raw_file_start]
                    = 'MoreSpecificImplementedParamType';
            }
        }
    }

    /**
     * Resolve the concrete model from the class's `@extends $templateHolder<ConcreteModel>` binding.
     * Returns null when no binding resolves to a Model subclass (bare Model is treated as no binding).
     * @param non-empty-string $templateHolder FQCN of the base class whose TModel binding carries the model
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     * @psalm-mutation-free
     */
    public static function resolveTemplateModel(ClassLikeStorage $storage, string $templateHolder): ?string
    {
        $binding = $storage->template_extended_params[$templateHolder]['TModel'] ?? null;
        if (!$binding instanceof Union) {
            return null;
        }

        foreach ($binding->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && $atomic->value !== self::MODEL) {
                /** @var class-string<\Illuminate\Database\Eloquent\Model> */
                return $atomic->value;
            }
        }

        return null;
    }

    /**
     * An un-parameterized contract or Eloquent builder param: safe to rewrite to Builder<Model>.
     * @psalm-mutation-free
     */
    private static function isBareBuilderParam(?Union $type): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            // TGenericObject extends TNamedObject, so an already-parameterized `Builder<SomeModel>`
            // param would otherwise match below too. An explicit generic is user-declared intent
            // (e.g. a `@param Builder<SomeModel> $query` override) and must not be clobbered —
            // see isGenericBuilderParam().
            if ($atomic instanceof TGenericObject && \count($atomic->type_params) > 0) {
                continue;
            }

            if (self::isBuilderName($atomic->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An already-parameterized `Builder<SomeModel>` param: user-narrowed, only needs the suppression.
     * @psalm-mutation-free
     */
    private static function isGenericBuilderParam(?Union $type): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject
                && \count($atomic->type_params) > 0
                && self::isBuilderName($atomic->value)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @psalm-pure */
    private static function isBuilderName(string $fqcn): bool
    {
        $value = \mb_strtolower($fqcn);

        return $value === self::CONTRACT_BUILDER || $value === \mb_strtolower(self::ELOQUENT_BUILDER);
    }
}
