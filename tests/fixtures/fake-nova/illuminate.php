<?php declare(strict_types=1);

/**
 * Minimal Laravel stand-ins: just enough surface for the Nova fakes and the scenarios to
 * type-check against. Deliberately kept outside the fixture's <projectFiles>, because Psalm
 * silently refuses stub files for classes that live inside the analysed project.
 */

namespace Illuminate\Http {
    class Request {}
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
