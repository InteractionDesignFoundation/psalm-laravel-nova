<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Line;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/** Every shape here is idiomatic Nova and must analyse without a single issue. */
final class CleanResource
{
    /** @return list<\Laravel\Nova\Fields\Field> */
    public function fields(): array
    {
        return [
            // Fix 1: the resource callback narrowed to the resource's own model. All 6 visibility
            // setters share the same @template bound (see FieldElement.phpstub), so one show* and
            // one hide* case exercises the pattern without repeating it 6 times.
            Text::make('Title')->showOnDetail(static fn(NovaRequest $request, Post $post): bool => $post->published),
            Text::make('Slug')->hideFromIndex(static fn(NovaRequest $request, mixed $post): bool => true),
            Text::make('State')->showOnDetail(true),

            // Fix 2: the filter callback typed with the concrete Eloquent builder.
            Text::make('Status')->filterable(
                static function (NovaRequest $request, Builder $query, mixed $value, string $attribute): void {
                    $query->where($attribute, '=', $value);
                },
            ),

            // Fix 2b: the filter callback also accepts a Relation, since Nova passes one for
            // relationship-index requests (QueriesResources::newQuery()).
            Text::make('Category')->filterable(
                static function (NovaRequest $request, Relation $query, mixed $value, string $attribute): void {
                    $query->where($attribute, '=', $value);
                },
            ),

            // Fix 3: canSee() narrowed to NovaRequest on the Field hierarchy only.
            Text::make('Secret')->canSee(static fn(NovaRequest $request): bool => true),

            // Fix 4: Stack built from already-instantiated Field lines.
            Stack::make('Details', [
                Line::make('Title'),
                Line::make('Author'),
            ]),
        ];
    }
}

/** A user's own canSee() override must never be narrowed — only Nova's own trait method is. */
final class FieldWithOwnAuthorization extends Field
{
    /**
     * @param \Closure(Request): bool $callback
     * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
     */
    #[\Override]
    public function canSee(\Closure $callback)
    {
        return $this;
    }
}

final class UsesFieldWithOwnAuthorization
{
    /** @return list<\Laravel\Nova\Fields\Field> */
    public function fields(): array
    {
        return [
            (new FieldWithOwnAuthorization('Name'))->canSee(static fn(Request $request): bool => true),
        ];
    }
}
