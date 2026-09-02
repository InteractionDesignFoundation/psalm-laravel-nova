<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The narrowed stubs must not turn into blanket `mixed`: every callback below is genuinely wrong
 * and Psalm must keep reporting it. The expected issue on each line is asserted by AcceptanceTest.
 */
final class BrokenResource
{
    /** @return list<\Laravel\Nova\Fields\Field> */
    public function fields(): array
    {
        return [
            // Wrong request class.
            Text::make('A')->showOnDetail(static fn(\stdClass $request, Post $post): bool => true),

            // Wrong return type.
            Text::make('B')->showOnDetail(static fn(NovaRequest $request, Post $post): string => 'nope'),

            // Wrong param type on the authorisation callback.
            Text::make('C')->canSee(static fn(int $request): bool => true),
        ];
    }
}
