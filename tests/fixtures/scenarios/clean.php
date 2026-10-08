<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Line;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Resource;

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

/**
 * Nova's `DelegatesToResource::__get()` forwards unknown property reads to the model, so a model's
 * `@property` set is readable straight off the resource — from inside it and, once it declares
 * `$policy` (Nova then hands the policy the resource, not the model), from the policy as well.
 *
 * @extends Resource<Post>
 */
final class PostResource extends Resource
{
    public static string $model = Post::class;

    public static string $policy = PostPolicy::class;

    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function subtitle(): string
    {
        return $this->headline;
    }

    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function subtitleViaResource(): string
    {
        return $this->resource->headline;
    }
}

/** @psalm-suppress MissingImmutableAnnotation — irrelevant to what this fixture tests */
final class PostPolicy
{
    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function view(User $user, PostResource $post): bool
    {
        return $post->headline !== '';
    }
}

/**
 * The model binding may sit on an abstract base, however many levels up, and `$model` alone is enough
 * when the binding is the bare `Model` bound.
 *
 * @extends Resource<Post>
 */
abstract class BasePostResource extends Resource
{
    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function baseSubtitle(): string
    {
        return $this->headline;
    }
}

final class ConcretePostResource extends BasePostResource
{
    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function subtitle(): string
    {
        return $this->headline;
    }
}

/** @extends Resource<Model> */
final class ModelPropertyOnlyResource extends Resource
{
    public static string $model = Post::class;

    /** @psalm-suppress MissingPureAnnotation — irrelevant to what this fixture tests */
    public function subtitle(): string
    {
        return $this->headline;
    }
}
