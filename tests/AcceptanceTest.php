<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs Psalm over tests/fixtures with this plugin enabled and asserts on the issues it reports.
 *
 * The fixture project stands in for a Nova application: `scenarios/` is the analysed code and
 * `fake-nova/` holds minimal Laravel/Nova declarations that reproduce the vendor docblocks the
 * plugin's stubs override. Both scenario files are analysed in a single Psalm run.
 *
 * @see tests/fixtures/psalm.xml for why the fakes are reached through the composer classmap.
 */
#[CoversNothing]
final class AcceptanceTest extends TestCase
{
    /** @var list<array{file_name: string, line_from: int, selected_text: string, type: string, message: string}>|null */
    private static ?array $issues = null;

    #[Test]
    public function idiomatic_nova_callbacks_are_accepted(): void
    {
        $issues = $this->issuesIn('scenarios/clean.php');

        self::assertSame([], $issues, "Expected no Psalm issues in clean.php, got:\n".self::describe($issues));
    }

    #[Test]
    public function genuinely_wrong_callbacks_are_still_reported(): void
    {
        $issues = $this->issuesIn('scenarios/still_errors.php');

        self::assertSame(
            [
                // Wrong request class.
                ['text' => 'static fn(\stdClass $request, Post $post): bool => true', 'type' => 'InvalidArgument'],
                // Wrong return type.
                ['text' => "static fn(NovaRequest \$request, Post \$post): string => 'nope'", 'type' => 'InvalidArgument'],
                // Wrong resource param type on a setter with no upstream @phpstan-param (stricter
                // than pre-plugin Nova, which left this bare `mixed`).
                ['text' => 'static fn(NovaRequest $request, int $post): bool => true', 'type' => 'InvalidArgument'],
                // Wrong param type on the authorisation callback.
                ['text' => 'static fn(int $request): bool => true', 'type' => 'InvalidArgument'],
                // Wrong builder type on the filter callback.
                [
                    'text' => 'static function (NovaRequest $request, \stdClass $wrongBuilder, mixed $value, string $attribute): void {}',
                    'type' => 'InvalidArgument',
                ],
                // Stack line is not a valid class-string<Field>|callable|Field.
                ['text' => '[42]', 'type' => 'InvalidArgument'],
                // Stack line via $lines is not a valid class-string<Field>|callable|Field either.
                ['text' => '[new \stdClass()]', 'type' => 'InvalidArgument'],
                // Tool::canSee() narrowed to NovaRequest would be unsound: Tool can receive a plain Request.
                ['text' => 'static fn(NovaRequest $request): bool => true', 'type' => 'ArgumentTypeCoercion'],
            ],
            array_map(
                static fn(array $issue): array => ['text' => $issue['selected_text'], 'type' => $issue['type']],
                $issues,
            ),
            "Unexpected Psalm issues in still_errors.php:\n".self::describe($issues),
        );
    }

    /** @return list<array{file_name: string, line_from: int, selected_text: string, type: string, message: string}> */
    private function issuesIn(string $fixtureRelativePath): array
    {
        return array_values(array_filter(
            self::psalmIssues(),
            static fn(array $issue): bool => $issue['file_name'] === $fixtureRelativePath,
        ));
    }

    /** @return list<array{file_name: string, line_from: int, selected_text: string, type: string, message: string}> */
    private static function psalmIssues(): array
    {
        if (self::$issues !== null) {
            return self::$issues;
        }

        $projectRoot = \dirname(__DIR__);

        // stderr goes to a temp file, not a second pipe: draining stdout and stderr from two live
        // pipes sequentially can deadlock if Psalm fills the undrained one while blocked writing to
        // the other. A file has no such buffer limit.
        $stderrFile = tempnam(sys_get_temp_dir(), 'psalm-plugin-nova-stderr-');
        self::assertIsString($stderrFile, 'Could not create a temp file for stderr.');

        try {
            $process = proc_open(
                [
                    \PHP_BINARY,
                    $projectRoot.'/vendor/bin/psalm',
                    '--no-cache',
                    '--no-progress',
                    '--output-format=json',
                    '-c',
                    'tests/fixtures/psalm.xml',
                ],
                [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
                $projectRoot,
            );
            self::assertIsResource($process, 'Could not start Psalm.');

            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exitCode = proc_close($process);
            $stderr = (string) file_get_contents($stderrFile);
        } finally {
            unlink($stderrFile);
        }

        // 0 = no issues, 2 = issues were found (expected — still_errors.php is meant to raise some;
        // see IssueBuffer::finish()). Anything else is Psalm itself failing to run, not an issue.
        self::assertContains(
            $exitCode,
            [0, 2],
            "Psalm exited with code {$exitCode}.\nstdout: {$stdout}\nstderr: {$stderr}",
        );

        /** @var list<array{file_name: string, line_from: int, selected_text: string, type: string, message: string}>|null $issues */
        $issues = json_decode($stdout, associative: true);
        self::assertIsArray($issues, "Psalm did not return JSON.\nstdout: {$stdout}\nstderr: {$stderr}");

        return self::$issues = $issues;
    }

    /** @param list<array{file_name: string, line_from: int, selected_text: string, type: string, message: string}> $issues */
    private static function describe(array $issues): string
    {
        return implode("\n", array_map(
            static fn(array $issue): string => "  {$issue['file_name']}:{$issue['line_from']} {$issue['type']}: {$issue['message']}",
            $issues,
        ));
    }
}
