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
 * @see tests/fixtures/psalm.xml for why the fakes are stub files rather than project files.
 */
#[CoversNothing]
final class AcceptanceTest extends TestCase
{
    /** @var list<array{file_name: string, line_from: int, type: string, message: string}>|null */
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
                ['line' => 20, 'type' => 'InvalidArgument'],
                // Wrong return type.
                ['line' => 23, 'type' => 'InvalidArgument'],
                // Wrong param type on the authorisation callback.
                ['line' => 26, 'type' => 'InvalidArgument'],
            ],
            array_map(
                static fn(array $issue): array => ['line' => $issue['line_from'], 'type' => $issue['type']],
                $issues,
            ),
            "Unexpected Psalm issues in still_errors.php:\n".self::describe($issues),
        );
    }

    /** @return list<array{file_name: string, line_from: int, type: string, message: string}> */
    private function issuesIn(string $fixtureRelativePath): array
    {
        return array_values(array_filter(
            self::psalmIssues(),
            static fn(array $issue): bool => $issue['file_name'] === $fixtureRelativePath,
        ));
    }

    /** @return list<array{file_name: string, line_from: int, type: string, message: string}> */
    private static function psalmIssues(): array
    {
        if (self::$issues !== null) {
            return self::$issues;
        }

        $projectRoot = \dirname(__DIR__);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

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
            $descriptors,
            $pipes,
            $projectRoot,
        );
        self::assertIsResource($process, 'Could not start Psalm.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        array_map(fclose(...), $pipes);
        proc_close($process);

        /** @var list<array{file_name: string, line_from: int, type: string, message: string}>|null $issues */
        $issues = json_decode($stdout, associative: true);
        self::assertIsArray($issues, "Psalm did not return JSON.\nstdout: {$stdout}\nstderr: {$stderr}");

        return self::$issues = $issues;
    }

    /** @param list<array{file_name: string, line_from: int, type: string, message: string}> $issues */
    private static function describe(array $issues): string
    {
        return implode("\n", array_map(
            static fn(array $issue): string => "  {$issue['file_name']}:{$issue['line_from']} {$issue['type']}: {$issue['message']}",
            $issues,
        ));
    }
}
