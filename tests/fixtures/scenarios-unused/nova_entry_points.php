<?php declare(strict_types=1);

namespace Scenarios\Unused;

use App\Models\Post;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/**
 * Nova discovers resources by scanning `app/Nova`, so nothing references this class, and its
 * `relatable*()` hooks are reached by reflection. The class, the hooks, and everything only the
 * hooks call must all stay alive.
 *
 * @extends Resource<Post>
 */
final class PostResource extends Resource
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
