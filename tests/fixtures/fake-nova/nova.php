<?php declare(strict_types=1);

/**
 * Minimal Laravel Nova stand-ins. Only the shapes the scenarios exercise are modelled, and the
 * callback docblocks deliberately mirror real Nova's *wide* baseline: it is the plugin's stub
 * files that narrow them, so the fakes must reproduce the false positive, not the fix.
 *
 * Class/trait headers must match the corresponding .phpstub headers exactly, because Psalm resets
 * a stubbed class-like's interface/trait data to whatever the stub declares.
 */

namespace Laravel\Nova {
    trait AuthorizedToSee
    {
        /** @return bool */
        public function authorizedToSee(\Illuminate\Http\Request $request)
        {
            return true;
        }

        /**
         * @param \Closure(\Laravel\Nova\Http\Requests\NovaRequest|\Illuminate\Http\Request):bool $callback
         * @return $this
         */
        public function canSee(\Closure $callback)
        {
            return $this;
        }
    }

    trait Makeable
    {
        /** @return static */
        public static function make(mixed ...$arguments)
        {
            return new static(...$arguments);
        }
    }

    trait Metable {}
    trait ProxiesCanSeeToGate {}
    trait SupportsPolling {}
    trait WithComponent {}

    abstract class Element implements \JsonSerializable
    {
        use \Laravel\Nova\AuthorizedToSee;
        use \Illuminate\Support\Traits\Macroable;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\Metable;
        use \Laravel\Nova\ProxiesCanSeeToGate;
        use \Laravel\Nova\WithComponent;

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }

    /**
     * Unlike `Element`/`Field`, `Tool::canSee()` is resolved by `BootTools` middleware with a plain
     * `Illuminate\Http\Request`, not a `NovaRequest` — it must not inherit a `NovaRequest`-only
     * `canSee()` narrowing.
     */
    abstract class Tool
    {
        use \Laravel\Nova\AuthorizedToSee;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\ProxiesCanSeeToGate;
    }

    trait Authorizable {}
    trait FillsFields {}
    trait HasLifecycleMethods {}
    trait PerformsValidation {}
    trait ResolvesActions
    {
        /** @return list<\Laravel\Nova\Actions\Action> */
        public function actions(\Laravel\Nova\Http\Requests\NovaRequest $request): array
        {
            return [];
        }
    }
    trait ResolvesCards {}
    trait ResolvesFields {}
    trait ResolvesFilters {}
    trait ResolvesLenses {}

    trait PerformsQueries
    {
        /**
         * @param \Illuminate\Contracts\Database\Eloquent\Builder $query
         * @return \Illuminate\Contracts\Database\Eloquent\Builder
         */
        public static function relatableQuery(\Laravel\Nova\Http\Requests\NovaRequest $request, $query)
        {
            return $query;
        }
    }

    abstract class Resource implements \ArrayAccess, \JsonSerializable, \Illuminate\Contracts\Routing\UrlRoutable
    {
        use \Laravel\Nova\Authorizable;
        use \Illuminate\Http\Resources\ConditionallyLoadsAttributes;
        use \Illuminate\Http\Resources\DelegatesToResource;
        use \Laravel\Nova\FillsFields;
        use \Laravel\Nova\HasLifecycleMethods;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\PerformsQueries;
        use \Laravel\Nova\PerformsValidation;
        use \Laravel\Nova\ResolvesActions;
        use \Laravel\Nova\ResolvesCards;
        use \Laravel\Nova\ResolvesFields;
        use \Laravel\Nova\ResolvesFilters;
        use \Laravel\Nova\ResolvesLenses;
        use \Laravel\Nova\SupportsPolling;

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace Laravel\Nova\Actions {
    class Action implements \JsonSerializable
    {
        use \Laravel\Nova\AuthorizedToSee;
        use \Illuminate\Support\Traits\Macroable;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\Metable;
        use \Laravel\Nova\ProxiesCanSeeToGate;
        use \Illuminate\Support\Traits\Tappable;
        use \Laravel\Nova\WithComponent;

        /**
         * @param \Stringable|string $name
         * @param \Closure(\Laravel\Nova\Fields\ActionFields, \Illuminate\Support\Collection):(mixed) $handleUsing
         */
        public static function using($name, \Closure $handleUsing): static
        {
            return new static();
        }

        /**
         * @param \Closure(\Laravel\Nova\Fields\ActionFields, \Illuminate\Support\Collection):(mixed) $callback
         * @return $this
         */
        public function handleUsing(\Closure $callback)
        {
            return $this;
        }

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace Laravel\Nova\Actions {
    /**
     * Mirrors the Nova 5.11 signatures of the members the scenarios touch. The three soft-delete
     * factories take (and return) a bare `Collection`; the rest are never stubbed and only prove the
     * partial ActionEvent stub is merged with the real class instead of replacing it.
     */
    class ActionEvent extends \Illuminate\Database\Eloquent\Model
    {
        /**
         * @param \Illuminate\Contracts\Auth\Authenticatable $user
         * @param \Illuminate\Database\Eloquent\Model $model
         * @return static
         */
        public static function forResourceCreate($user, $model)
        {
            return new static();
        }

        /**
         * @param \Illuminate\Contracts\Auth\Authenticatable $user
         */
        public static function forResourceDelete($user, \Illuminate\Support\Collection $models): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection();
        }

        /**
         * @param \Illuminate\Contracts\Auth\Authenticatable $user
         */
        public static function forResourceRestore($user, \Illuminate\Support\Collection $models): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection();
        }

        /**
         * @param \Illuminate\Contracts\Auth\Authenticatable $user
         */
        public static function forSoftDeleteAction(string $action, $user, \Illuminate\Support\Collection $models): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection();
        }

        public static function markBatchAsRunning(string $batchId): int
        {
            return 0;
        }
    }

    class ActionResponse implements \ArrayAccess, \JsonSerializable
    {
        use \Laravel\Nova\Makeable;

        /**
         * Create a new response using `message`.
         *
         * @return static
         */
        public static function message(\Stringable|string $message)
        {
            return new static();
        }

        /**
         * @param string $offset
         */
        public function offsetExists($offset): bool
        {
            return false;
        }

        /**
         * @param string $offset
         */
        public function offsetGet($offset): mixed
        {
            return null;
        }

        /**
         * @param string $offset
         */
        public function offsetSet($offset, $value): void {}

        /**
         * @param string $offset
         */
        public function offsetUnset($offset): void {}

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace Laravel\Nova\Filters {
    trait Searchable {}

    abstract class Filter implements \Laravel\Nova\Contracts\Filter, \JsonSerializable
    {
        use \Laravel\Nova\AuthorizedToSee;
        use \Illuminate\Support\Traits\Macroable;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\Metable;
        use \Laravel\Nova\ProxiesCanSeeToGate;
        use \Laravel\Nova\Filters\Searchable;
        use \Laravel\Nova\WithComponent;

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace Laravel\Nova\Lenses {
    abstract class Lens implements \ArrayAccess, \JsonSerializable, \Illuminate\Contracts\Routing\UrlRoutable
    {
        use \Laravel\Nova\AuthorizedToSee;
        use \Illuminate\Http\Resources\ConditionallyLoadsAttributes;
        use \Illuminate\Http\Resources\DelegatesToResource;
        use \Laravel\Nova\Makeable;
        use \Laravel\Nova\ProxiesCanSeeToGate;
        use \Laravel\Nova\ResolvesActions;
        use \Laravel\Nova\ResolvesCards;
        use \Laravel\Nova\ResolvesFilters;
        use \Laravel\Nova\SupportsPolling;

        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace Laravel\Nova\Contracts {
    interface Resolvable {}

    interface Filter
    {
        /** @return bool */
        public function authorizedToSee(\Illuminate\Http\Request $request);
    }
}

namespace Laravel\Nova\Support {
    class Fluent {}

    /**
     * @template TKey of array-key
     * @template TValue
     */
    abstract class FluentDecorator {}
}

namespace Laravel\Nova\Metrics {
    trait HasHelpText {}
}

namespace Laravel\Nova\Http\Requests {
    class NovaRequest extends \Illuminate\Http\Request
    {
        /** Determine if this request is a resource index request. */
        public function isResourceIndexRequest(): bool
        {
            return false;
        }
    }
}

namespace Laravel\Nova\Fields {
    /** @extends \Illuminate\Support\Fluent<array-key, mixed> */
    class ResolvedFields extends \Illuminate\Support\Fluent
    {
        public function __construct(\Illuminate\Support\Collection $attributes, \Illuminate\Support\Collection $callbacks) {}
    }

    class ActionFields extends \Laravel\Nova\Fields\ResolvedFields {}

    trait DependentFields {}
    trait HandlesValidation {}
    trait MutableFields {}
    trait PeekableFields {}
    trait PreviewableFields {}
    trait SupportsFullWidthFields {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @extends \Laravel\Nova\Support\FluentDecorator<TKey, TValue>
     */
    class FormData extends \Laravel\Nova\Support\FluentDecorator {}

    /** Nova's own docblocks type the callback's form data as a bare `FormData`. */
    trait SupportsDependentFields
    {
        /**
         * @param \Laravel\Nova\Fields\Field|array<int, string|\Laravel\Nova\Fields\Field>|string $attributes
         * @param (callable(static, \Laravel\Nova\Http\Requests\NovaRequest, \Laravel\Nova\Fields\FormData):(void))|class-string $mixin
         * @return $this
         */
        public function dependsOn(Field|array|string $attributes, callable|string $mixin)
        {
            return $this;
        }

        /**
         * @param \Laravel\Nova\Fields\Field|array<int, string|\Laravel\Nova\Fields\Field>|string $attributes
         * @param (callable(static, \Laravel\Nova\Http\Requests\NovaRequest, \Laravel\Nova\Fields\FormData):(void))|class-string $mixin
         * @return $this
         */
        public function dependsOnCreating(Field|array|string $attributes, callable|string $mixin)
        {
            return $this;
        }

        /**
         * @param string|\Laravel\Nova\Fields\Field|array<int, string|\Laravel\Nova\Fields\Field> $attributes
         * @param (callable(static, \Laravel\Nova\Http\Requests\NovaRequest, \Laravel\Nova\Fields\FormData):(void))|class-string $mixin
         * @return $this
         */
        public function dependsOnUpdating($attributes, $mixin)
        {
            return $this;
        }
    }

    trait Filterable
    {
        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, \Illuminate\Contracts\Database\Eloquent\Builder, mixed, string):(void))|null $filterableCallback
         * @return $this
         */
        public function filterable(?callable $filterableCallback = null)
        {
            return $this;
        }
    }

    /** @phpstan-type TMixedResource \Illuminate\Database\Eloquent\Model|\Laravel\Nova\Support\Fluent|object|array */
    abstract class FieldElement extends \Laravel\Nova\Element
    {
        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         * @return $this
         */
        public function hideFromIndex(callable|bool $callback = true)
        {
            return $this;
        }

        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         * @return $this
         */
        public function hideFromDetail(callable|bool $callback = true)
        {
            return $this;
        }

        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         * @return $this
         */
        public function hideWhenUpdating(callable|bool $callback = true)
        {
            return $this;
        }

        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         *
         * @phpstan-param (callable(\Laravel\Nova\Http\Requests\NovaRequest, TMixedResource):(bool))|bool $callback
         *
         * @return $this
         */
        public function showOnIndex(callable|bool $callback = true)
        {
            return $this;
        }

        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         *
         * @phpstan-param (callable(\Laravel\Nova\Http\Requests\NovaRequest, TMixedResource):(bool))|bool $callback
         *
         * @return $this
         */
        public function showOnDetail(callable|bool $callback = true)
        {
            return $this;
        }

        /**
         * @param (callable(\Laravel\Nova\Http\Requests\NovaRequest, mixed):(bool))|bool $callback
         * @return $this
         */
        public function showOnUpdating(callable|bool $callback = true)
        {
            return $this;
        }
    }

    abstract class Field extends \Laravel\Nova\Fields\FieldElement implements \JsonSerializable, \Laravel\Nova\Contracts\Resolvable
    {
        use \Illuminate\Support\Traits\Conditionable;
        use \Laravel\Nova\Fields\DependentFields;
        use \Laravel\Nova\Fields\HandlesValidation;
        use \Laravel\Nova\Metrics\HasHelpText;
        use \Laravel\Nova\Fields\MutableFields;
        use \Laravel\Nova\Fields\PeekableFields;
        use \Laravel\Nova\Fields\PreviewableFields;
        use \Laravel\Nova\Fields\SupportsFullWidthFields;
        use \Illuminate\Support\Traits\Tappable;

        /**
         * @param \Stringable|string $name
         * @param string|callable|object|null $attribute
         * @param (callable(mixed, mixed, ?string):(mixed))|null $resolveCallback
         */
        public function __construct($name, $attribute = null, ?callable $resolveCallback = null) {}

        /**
         * @param callable(mixed, mixed, string):mixed $displayCallback
         * @return $this
         */
        public function displayUsing(callable $displayCallback)
        {
            return $this;
        }

        /**
         * @param callable(mixed, mixed, ?string):mixed $resolveCallback
         * @return $this
         */
        public function resolveUsing(callable $resolveCallback)
        {
            return $this;
        }
    }

    class Line extends \Laravel\Nova\Fields\Field {}

    class DateTime extends \Laravel\Nova\Fields\Field
    {
        /**
         * @param \Stringable|string $name
         * @param string|callable|object|null $attribute
         * @param (callable(mixed, mixed, ?string):(mixed))|null $resolveCallback
         */
        public function __construct($name, mixed $attribute = null, ?callable $resolveCallback = null) {}
    }

    class Text extends \Laravel\Nova\Fields\Field
    {
        use \Laravel\Nova\Fields\Filterable;
        use \Laravel\Nova\Fields\SupportsDependentFields;
    }

    class Email extends \Laravel\Nova\Fields\Text
    {
        use \Laravel\Nova\Fields\SupportsDependentFields;

        /**
         * Create a new field.
         *
         * @param  \Stringable|string|null  $name
         * @param  string|callable|object|null  $attribute
         * @param  (callable(mixed, mixed, ?string):(mixed))|null  $resolveCallback
         */
        public function __construct($name = null, mixed $attribute = 'email', ?callable $resolveCallback = null) {}
    }

    class Stack extends \Laravel\Nova\Fields\Field
    {
        /**
         * @param \Stringable|string $name
         * @param string|array<int, class-string<\Laravel\Nova\Fields\Field>|callable>|null $attribute
         * @param iterable<int, class-string<\Laravel\Nova\Fields\Field>|callable> $lines
         */
        public function __construct($name, mixed $attribute = null, iterable $lines = []) {}
    }
}
