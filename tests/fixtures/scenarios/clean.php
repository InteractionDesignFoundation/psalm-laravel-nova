<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Line;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

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

            // Fix 5: the resolve callback narrowed to the resource's model and a non-null attribute.
            DateTime::make(
                'Published',
                'published_at',
                static fn(mixed $value, Post $resource, string $attribute): ?\DateTimeInterface => $resource->published_at,
            ),
            Text::make('Headline', 'headline', static fn(mixed $value, Post $resource, string $attribute): string => $resource->headline),

            // Fix 5b: resolveUsing()/displayUsing() take the same callback shape.
            Text::make('Summary')
                ->resolveUsing(static fn(mixed $value, Post $resource, string $attribute): string => $resource->headline)
                ->displayUsing(static fn(mixed $value, Post $resource, string $attribute): string => $resource->headline),
            // A callback that ignores trailing params, or leaves its types wide, is still fine.
            Text::make('Teaser', 'teaser', static fn(mixed $value): mixed => $value),
            Text::make('Lead')->resolveUsing(static fn(mixed $value, mixed $resource, ?string $attribute): mixed => $value),
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

/** Nova hints ResolvedFields' constructor params as bare Collection; a non-literal value must still pass. */
final class BuildsActionFields
{
    public function build(int $targetId): ActionFields
    {
        return new ActionFields(new Collection(['target_id' => $targetId]), new Collection([]));
    }
}

/**
 * Fix 5: canSee() narrowed to NovaRequest on Action/Filter/Lens too. Nova only ever calls
 * authorizedToSee() on these with a NovaRequest (ResolvesActions/ResolvesFilters/ResolvesLenses,
 * ActionRequest, LensRequest; the menu items built from a lens get app(NovaRequest::class)).
 */
final class PublishPost extends Action {}

final class ActiveOnly extends Filter {}

final class TopPosts extends Lens {}

final class AuthorizesNonFieldElements
{
    public function authorize(): void
    {
        $callback = static fn(NovaRequest $request): bool => $request->isResourceIndexRequest();

        PublishPost::make()->canSee($callback);
        ActiveOnly::make()->canSee($callback);
        TopPosts::make()->canSee($callback);
    }
}
