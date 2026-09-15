<?php declare(strict_types=1);

namespace Scenarios\Unused;

use App\Models\Post;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/**
 * An abstract intermediate resource, which the class-level marking deliberately skips. Its
 * `relatable*()` hook is therefore kept alive only by the per-method marking, and nothing else in
 * this fixture would catch that marking being dropped.
 *
 * @extends Resource<Post>
 */
abstract class BaseAuthoredResource extends Resource
{
    /** @param Builder $query */
    public static function relatableEditors(NovaRequest $request, $query): Builder
    {
        return self::visibleOnly($query, WidgetResource::class);
    }

    /**
     * Only reachable from the reflection-dispatched `relatableEditors()`.
     *
     * @param class-string<Resource> $relatedResource anchors WidgetResource to an entry point that
     *        does not depend on class-level marking, so the silence of its members stays an
     *        independent assertion
     */
    private static function visibleOnly(Builder $query, string $relatedResource): Builder
    {
        return $query;
    }
}

/**
 * Nova discovers resources by scanning `app/Nova`, so nothing references this class, and its
 * `relatable*()` hooks are reached by reflection. The class, the hooks, and everything only the
 * hooks call must all stay alive.
 */
final class PostResource extends BaseAuthoredResource
{
    public static string $policy = PostPolicy::class;

    /** @return list<Action> */
    #[\Override]
    public function actions(NovaRequest $request): array
    {
        return [new PublishPost()];
    }

    /** @param Builder $query */
    public static function relatableAuthors(NovaRequest $request, $query): Builder
    {
        return self::excludeDrafts($query);
    }

    /** Only reachable from the reflection-dispatched `relatableAuthors()`. */
    private static function excludeDrafts(Builder $query): Builder
    {
        return $query;
    }

    /** Reachable from nothing at all: UnusedMethod must still be reported here. */
    private static function neverCalled(): void {}
}

/** `handle()` is dispatched by Nova through the container, so it has no visible call site. */
final class PublishPost extends Action
{
    /** @param iterable<int, Post> $models */
    public function handle(iterable $models): void
    {
        foreach ($models as $model) {
            self::publish($model);
        }
    }

    /** Only reachable from the container-dispatched `handle()`. */
    private static function publish(Post $post): void
    {
        $post->published = true;
    }
}

/** Gate methods are routed through `Resource::authorizedTo()`, never called directly. */
final class PostPolicy
{
    public function viewAny(): bool
    {
        return self::enabled();
    }

    public function update(Post $post): bool
    {
        return self::enabled() && $post->published;
    }

    /** Only reachable from the gate-dispatched policy methods. */
    private static function enabled(): bool
    {
        return true;
    }
}

/**
 * The price of marking a resource an entry point class-wide: Psalm stops checking its public
 * surface, and stops asking for the class to be final. This class is referenced (see
 * `BaseAuthoredResource::relatableEditors()`), so all three would be reported without that
 * marking — pinned here so the blast radius cannot widen or narrow unnoticed.
 *
 * @extends Resource<Post>
 */
class WidgetResource extends Resource
{
    /** Read nowhere: PossiblyUnusedProperty. */
    public string $unreadLabel = '';

    /** Called from nowhere: PossiblyUnusedMethod. */
    public function unusedHook(): void {}
}

/** Not a Nova type, so nothing marks it: UnusedClass must still be reported. */
final class OrphanHelper
{
    public function run(): void {}
}
