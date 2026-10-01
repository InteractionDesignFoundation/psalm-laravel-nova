<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionEvent;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FormData;
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

/** An action's callbacks typed against the application's own models (Nova passes int-keyed collections). */
final class ActionsOnPosts
{
    public function inline(): Action
    {
        return Action::using(
            'Publish',
            /** @param Collection<int, Post> $posts */
            static fn(ActionFields $fields, Collection $posts): int => $posts->count(),
        );
    }

    public function configured(): Action
    {
        return PublishPost::make()->handleUsing(
            /** @param Collection<int, Post> $posts */
            static fn(ActionFields $fields, Collection $posts): int => $posts->count(),
        );
    }

    /** Compatibility guard: the shape from Nova's docs, a bare `Collection`, was clean before the stubs. */
    public function bare(): Action
    {
        return Action::using('Archive', static fn(ActionFields $fields, Collection $models): int => $models->count());
    }

    /**
     * Compatibility guard: a callback with no generic annotation keeps Nova's own `Collection<array-key, mixed>`,
     * so it can be forwarded to a helper typed that way (the Collection stub is invariant, so a collection of
     * models would not match). Clean before the stubs, and must stay so.
     */
    public function untyped(): Action
    {
        return Action::using('Bare', static function (ActionFields $fields, Collection $models): void {
            self::forward($models);
        })->handleUsing(static function (ActionFields $fields, $models): void {
            self::forward($models);
        })->handleUsing(static function ($fields, $models): void {
            self::forward($models);
        });
    }

    /** @param Collection<array-key, mixed> $models */
    private static function forward(Collection $models): int
    {
        return $models->count();
    }

    /**
     * `Collection<array-key, mixed>` is a compatibility guard (clean before the stubs). `Collection<array-key, Post>`
     * is removal-sensitive: the invariant Collection stub rejects it against Nova's `Collection<array-key, mixed>`
     * without the stubs.
     */
    public function anyKey(): Action
    {
        return Action::using(
            'Export',
            /** @param Collection<array-key, mixed> $models */
            static fn(ActionFields $fields, Collection $models): int => $models->count(),
        )->handleUsing(
            /** @param Collection<array-key, Post> $posts */
            static fn(ActionFields $fields, Collection $posts): int => $posts->count(),
        );
    }
}

/**
 * The action-event factories accept any typed collection of models (removal-sensitive: the invariant
 * Collection stub rejects `Collection<int, Post>` against Nova's bare `Collection` without the stub), and
 * return Nova's own `Collection<array-key, mixed>`, so the result can be forwarded to a helper typed that way
 * whatever went in (compatibility guards: all of these were clean before the stubs, except the typed inputs).
 * The rest of ActionEvent stays Nova's own.
 */
final class LogsActionEvents
{
    /** @param Collection<int, Post> $posts */
    public function typed(Authenticatable $user, Collection $posts): int
    {
        return self::count(ActionEvent::forResourceDelete($user, $posts))
            + self::count(ActionEvent::forResourceRestore($user, $posts))
            + self::count(ActionEvent::forSoftDeleteAction('Archive', $user, $posts));
    }

    /** @param Collection<array-key, Model> $models */
    public function models(Authenticatable $user, Collection $models): int
    {
        return self::count(ActionEvent::forResourceDelete($user, $models))
            + self::count(ActionEvent::forResourceRestore($user, $models))
            + self::count(ActionEvent::forSoftDeleteAction('Archive', $user, $models));
    }

    /** @param Collection<array-key, mixed> $models */
    public function bare(Authenticatable $user, Collection $models): int
    {
        return self::count(ActionEvent::forResourceDelete($user, $models))
            + self::count(ActionEvent::forResourceRestore($user, $models))
            + self::count(ActionEvent::forSoftDeleteAction('Archive', $user, $models));
    }

    public function empty(Authenticatable $user): int
    {
        return self::count(ActionEvent::forResourceDelete($user, new Collection()))
            + self::count(ActionEvent::forResourceRestore($user, new Collection()))
            + self::count(ActionEvent::forSoftDeleteAction('Archive', $user, new Collection()));
    }

    public function merged(Authenticatable $user): int
    {
        // Not declared by the ActionEvent stub: merged with it, not replaced by it.
        $created = ActionEvent::forResourceCreate($user, new Post());

        return \strlen($created::class) + ActionEvent::markBatchAsRunning('batch');
    }

    /** @param Collection<array-key, mixed> $events */
    private static function count(Collection $events): int
    {
        return $events->count();
    }
}

/** An action response is an ArrayAccess<array-key, mixed>, as assertion helpers expect. */
final class ChecksActionResponse
{
    public function check(): bool
    {
        return self::has(ActionResponse::message('Done'), 'message');
    }

    /** @param \ArrayAccess<array-key, mixed> $response */
    private static function has(\ArrayAccess $response, string $key): bool
    {
        return isset($response[$key]);
    }
}

/**
 * dependsOn*() callbacks get a FormData keyed by field attribute (a string, or an int for a numeric
 * attribute), and `static` is the concrete field.
 */
final class DependentFields
{
    /** @return list<\Laravel\Nova\Fields\Field> */
    public function fields(): array
    {
        return [
            Text::make('Slug')
                ->dependsOn(['title'], static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::sync($field, $formData);
                })
                ->dependsOnCreating('title', static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::sync($field, $formData);
                })
                ->dependsOnUpdating('title', static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::sync($field, $formData);
                }),
            // A subclass hands its own type to the callback, and a parent type is accepted too.
            Email::make('Contact')
                ->dependsOn('name', static function (Email $field, NovaRequest $request, FormData $formData): void {})
                ->dependsOn('name', static function (Text $field, NovaRequest $request, FormData $formData): void {}),
            // The key may be typed as wide as Nova's own bare `FormData` or as narrow as the project likes,
            // through an inline callback, a function string, an invokable object or a first-class callable.
            Text::make('Title')
                ->dependsOn('slug', /** @param FormData<array-key, mixed> $formData */ static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::sync($field, $formData);
                })
                ->dependsOn('slug', /** @param FormData<string, mixed> $formData */ static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::syncStringKeyed($field, $formData);
                })
                ->dependsOnCreating('slug', /** @param FormData<string, mixed> $formData */ static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::syncStringKeyed($field, $formData);
                })
                ->dependsOnUpdating('slug', /** @param FormData<string, mixed> $formData */ static function (Text $field, NovaRequest $request, FormData $formData): void {
                    self::syncStringKeyed($field, $formData);
                })
                ->dependsOnCreating('slug', 'Scenarios\\syncDependentField')
                ->dependsOnCreating('slug', new SyncsDependentField())
                ->dependsOnUpdating('slug', self::syncCallback(...)),
        ];
    }

    /**
     * @param FormData<array-key, mixed> $formData
     * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
     */
    private static function sync(Text $field, FormData $formData): void {}

    /**
     * @param FormData<string, mixed> $formData
     * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
     */
    private static function syncStringKeyed(Text $field, FormData $formData): void {}

    /**
     * @param FormData<array-key, mixed> $formData
     * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
     */
    private static function syncCallback(Text $field, NovaRequest $request, FormData $formData): void {}
}

/**
 * @param FormData<array-key, mixed> $formData
 * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
 */
function syncDependentField(Text $field, NovaRequest $request, FormData $formData): void {}

/** @psalm-suppress MissingImmutableAnnotation — irrelevant to what this fixture tests */
final class SyncsDependentField
{
    /**
     * @param FormData<array-key, mixed> $formData
     * @psalm-suppress MissingPureAnnotation, UnusedParam — irrelevant to what this fixture tests
     */
    public function __invoke(Text $field, NovaRequest $request, FormData $formData): void {}
}
