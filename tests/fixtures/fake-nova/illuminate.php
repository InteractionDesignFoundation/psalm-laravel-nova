<?php declare(strict_types=1);

/**
 * Minimal Laravel stand-ins: just enough surface for the Nova fakes and the scenarios to
 * type-check against. Deliberately kept outside the fixture's <projectFiles>, because Psalm
 * silently refuses stub files for classes that live inside the analysed project.
 */

namespace Illuminate\Http {
    class Request {}
}

namespace Illuminate\Http\Resources {
    class MissingValue {}
    trait ConditionallyLoadsAttributes {}
    /** Laravel satisfies Resource's `ArrayAccess` contract from this trait, not from Nova. */
    trait DelegatesToResource
    {
        public function offsetExists(mixed $offset): bool
        {
            return false;
        }

        public function offsetGet(mixed $offset): mixed
        {
            return null;
        }

        public function offsetSet(mixed $offset, mixed $value): void {}

        public function offsetUnset(mixed $offset): void {}
    }
}

namespace Illuminate\Contracts\Routing {
    interface UrlRoutable {}
}

namespace Illuminate\Support\Traits {
    trait Conditionable {}
    trait Macroable {}
    trait Tappable {}
}

namespace Illuminate\Contracts\Database\Eloquent {
    interface Builder {}
}

namespace Illuminate\Database\Eloquent {
    class Model {}

    class Builder implements \Illuminate\Contracts\Database\Eloquent\Builder
    {
        public function where(string $column, string $operator, mixed $value): static
        {
            return $this;
        }
    }
}

namespace Illuminate\Database\Eloquent\Relations {
    /**
     * `Relation` implements the query builder contract but does not extend the concrete `Builder`
     * class above — Nova's `QueriesResources::newQuery()` returns exactly this for relationship-index
     * requests and passes it straight into `Filterable::filterable()`'s callback.
     */
    abstract class Relation implements \Illuminate\Contracts\Database\Eloquent\Builder
    {
        public function where(string $column, string $operator, mixed $value): static
        {
            return $this;
        }
    }
}
