<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
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
            // Fix 1: the resource callback narrowed to the resource's own model.
            Text::make('Title')->showOnDetail(static fn(NovaRequest $request, Post $post): bool => $post->published),
            Text::make('Slug')->hideFromIndex(static fn(NovaRequest $request, mixed $post): bool => true),
            Text::make('Body')->showOnIndex(static fn(NovaRequest $request, Post $post): bool => $post->title !== ''),
            Text::make('Excerpt')->hideFromDetail(static fn(NovaRequest $request, Post $post): bool => false),
            Text::make('Author')->showOnUpdating(static fn(NovaRequest $request, Post $post): bool => true),
            Text::make('Notes')->hideWhenUpdating(static fn(NovaRequest $request, Post $post): bool => true),
            Text::make('State')->showOnDetail(true),

            // Fix 2: the filter callback typed with the concrete Eloquent builder.
            Text::make('Status')->filterable(
                static function (NovaRequest $request, Builder $query, mixed $value, string $attribute): void {
                    $query->where($attribute, '=', $value);
                },
            ),

            // Fix 3: canSee() narrowed to NovaRequest.
            Text::make('Secret')->canSee(static fn(NovaRequest $request): bool => true),

            // Fix 4: Stack built from already-instantiated Field lines.
            Stack::make('Details', [
                Line::make('Title'),
                Line::make('Author'),
            ]),
        ];
    }
}
