<?php declare(strict_types=1);

namespace Scenarios\Unused;

use App\Models\Post;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/** Sharing a `relatable*()` hook across resources through a trait is ordinary Nova code. */
trait FindsTags
{
    /** @param Builder $query */
    public static function relatableTags(NovaRequest $request, $query): Builder
    {
        return self::publishedOnly($query);
    }

    /** Only reachable from the reflection-dispatched `relatableTags()`. */
    private static function publishedOnly(Builder $query): Builder
    {
        return $query;
    }
}

/** Likewise for an action's `handle()`, shared by every action that does the same work. */
trait ArchivesPosts
{
    /** @param iterable<int, Post> $models */
    public function handle(iterable $models): void
    {
        foreach ($models as $model) {
            self::archive($model);
        }
    }

    /** Only reachable from the container-dispatched `handle()`. */
    private static function archive(Post $post): void
    {
        $post->published = false;
    }
}

/**
 * An abstract intermediate resource, which the class-level marking deliberately skips. Its
 * `relatable*()` hooks are therefore kept alive only by the per-method marking, and nothing else in
 * this fixture would catch that marking being dropped. A trait-provided hook is here too: its
 * storage lives on the trait, so marking the using class would be a no-op.
 *
 * @extends Resource<Post>
 */
abstract class BaseAuthoredResource extends Resource
{
    use FindsTags;

    /** Not a Nova hook and not marked by anything: an abstract resource's members are still checked. */
    public function unmarkedHook(): void {}

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
        return [new PublishPost(), new ArchivePost(), new ReviewPost()];
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

/** The same hook, reached through a trait: the flag has to land on the trait's storage. */
final class ArchivePost extends Action
{
    use ArchivesPosts;
}

/**
 * Nova dispatches `handle()` on an instance, so a non-public one is never reached and is a real
 * bug. Marking must skip it, leaving UnusedMethod to report.
 */
final class ReviewPost extends Action
{
    private function handle(): void {}
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
