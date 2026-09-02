<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tool;

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

            // Wrong builder type on the filter callback.
            Text::make('D')->filterable(static function (NovaRequest $request, \stdClass $wrongBuilder, mixed $value, string $attribute): void {}),

            // Not a valid line: an int is neither class-string<Field>, callable, nor Field.
            Stack::make('E', [42]),

            // Not a valid line via $lines either: stdClass is neither class-string<Field>, callable, nor Field.
            Stack::make('F', 'a', [new \stdClass()]),
        ];
    }
}

/**
 * AuthorizedToSee::canSee() must stay wide on Tool: narrowing it to NovaRequest would be unsound,
 * since BootTools middleware hands Tool::canSee() a plain Request, not a NovaRequest.
 */
final class BrokenTool extends Tool
{
    public function register(): void
    {
        // Wrong: narrows to NovaRequest, but Tool can receive a plain Request.
        $this->canSee(static fn(NovaRequest $request): bool => true);
    }
}
