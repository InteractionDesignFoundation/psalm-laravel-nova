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
}

namespace Laravel\Nova\Contracts {
    interface Resolvable {}
}

namespace Laravel\Nova\Support {
    class Fluent {}
}

namespace Laravel\Nova\Metrics {
    trait HasHelpText {}
}

namespace Laravel\Nova\Http\Requests {
    class NovaRequest extends \Illuminate\Http\Request {}
}

namespace Laravel\Nova\Fields {
    trait DependentFields {}
    trait HandlesValidation {}
    trait MutableFields {}
    trait PeekableFields {}
    trait PreviewableFields {}
    trait SupportsFullWidthFields {}

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
         */
        public function __construct($name, $attribute = null, ?callable $resolveCallback = null) {}
    }

    class Line extends \Laravel\Nova\Fields\Field {}

    class Text extends \Laravel\Nova\Fields\Field
    {
        use \Laravel\Nova\Fields\Filterable;
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
