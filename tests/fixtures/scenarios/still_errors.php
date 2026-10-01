<?php declare(strict_types=1);

namespace Scenarios;

use App\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionEvent;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Resource;
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

            // Wrong resource param type — a stricter check than Nova's own docblock: hideFromIndex()
            // (unlike showOnIndex()/showOnDetail()) has no @phpstan-param upstream, so it was bare
            // `mixed` and accepted anything before this plugin's bounded template.
            Text::make('G')->hideFromIndex(static fn(NovaRequest $request, int $post): bool => true),

            // Wrong param type on the authorisation callback.
            Text::make('C')->canSee(static fn(int $request): bool => true),

            // Wrong builder type on the filter callback.
            Text::make('D')->filterable(static function (NovaRequest $request, \stdClass $wrongBuilder, mixed $value, string $attribute): void {}),

            // Not a valid line: an int is neither class-string<Field>, callable, nor Field.
            Stack::make('E', [42]),

            // Wrong attribute type on the make() resolve callback: Nova always passes a string.
            Text::make('H', 'h', static fn(mixed $value, Post $resource, int $attribute): string => 'nope'),

            // Resource param outside the Model|Fluent|array|object bound.
            Text::make('I', 'i', static fn(mixed $value, int $resource, string $attribute): string => 'nope'),

            // Arity: Nova passes three arguments, a fourth required param can never be satisfied.
            Text::make('J', 'j', static fn(mixed $value, Post $resource, string $attribute, mixed $extra): string => 'nope'),

            // The same checks apply to resolveUsing()/displayUsing().
            Text::make('K')->resolveUsing(static fn(mixed $value, Post $resource, int $attribute): string => 'nope'),
            Text::make('L')->displayUsing(static fn(mixed $value, Post $resource, string $attribute, mixed $extra): string => 'nope'),

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

/**
 * Narrowing Action/Filter/Lens::canSee() to NovaRequest must not turn it into blanket `mixed`: a
 * callback whose param can't take a NovaRequest is still wrong.
 */
final class BrokenNonFieldElements extends Action
{
    public function register(Filter $filter, Lens $lens): void
    {
        $this->canSee(static fn(int $request): bool => true);
        $filter->canSee(static fn(\stdClass $request): bool => true);
        $lens->canSee(static fn(string $request): bool => true);
    }
}

/**
 * The action, action-event and dependent-field stubs bind types, they do not widen them: a callback
 * or collection that cannot match is still reported.
 */
final class BrokenActionsAndDependentFields
{
    public function register(Authenticatable $user): void
    {
        // Nova passes int-keyed collections, not string-keyed ones.
        Action::using('A', /** @param Collection<string, Post> $posts */ static fn(ActionFields $fields, Collection $posts): int => 1);
        // The first argument is the ActionFields, not a scalar.
        Action::using('B', /** @param Collection<int, Post> $posts */ static fn(int $fields, Collection $posts): int => 1);
        // The second argument is a collection of models, not a model.
        Action::using('C', static fn(ActionFields $fields, Post $post): int => 1);
        // Arity: Nova passes two arguments, a third required param can never be satisfied.
        (new Action())->handleUsing(/** @param Collection<int, Post> $posts */ static fn(ActionFields $fields, Collection $posts, int $extra): int => 1);

        // A collection is required, whatever it holds.
        ActionEvent::forResourceDelete($user, new \stdClass());

        // The form data is a FormData, nothing else.
        Text::make('F')->dependsOn('x', static function (Text $field, NovaRequest $request, \stdClass $formData): void {});
        // `static` is the concrete field: a Text is not an Email.
        Text::make('G')->dependsOn('x', static function (Email $field, NovaRequest $request, FormData $formData): void {});
        // Arity: a fourth required param can never be satisfied.
        Text::make('H')->dependsOnCreating('x', static function (Text $field, NovaRequest $request, FormData $formData, int $extra): void {});
        // The request is a NovaRequest.
        Text::make('I')->dependsOnUpdating('x', static function (Text $field, \stdClass $request, FormData $formData): void {});
    }
}

/**
 * Psalm emits ClassMustBeFinal outside its find_unused_code guard, and a class-level entry point
 * silences it. This config has findUnusedCode off, so the resource must not be marked and the issue
 * must survive — the plugin may not cost anything to a project that never asked for unused-code
 * analysis.
 *
 * @extends Resource<Post>
 */
class NonFinalResource extends Resource {}
