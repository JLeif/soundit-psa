<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Gate-1 second belt: gc-verify.sh must prove `warnings == 0` by PARSING the
 * PHPUnit summary line, not merely by observing a zero exit code.
 *
 * Why this exists on top of GcVerifyWarningGateTest: that test exercises
 * PHPUnit's own --fail-on-warning exit policy, which was MEASURED to be
 * partial. A worktree with no .env produces
 *   Tests:  7286 warnings, 467 passed (48511 assertions)
 * with exit 0, and the unfixed gate printed `gc-verify: PASS` over it. These
 * cases drive the gate with a stub runner that reproduces that exact shape, so
 * the warning floor is what is under test rather than PHPUnit's exit code.
 */
class GcVerifySummaryWarningGateTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/gc-summary-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        (new Process(['git', 'init', '--quiet', $this->fixture]))->mustRun();
        // A provisioned environment, so these cases isolate summary parsing.
        file_put_contents($this->fixture.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
        file_put_contents($this->fixture.'/.env.example', "APP_KEY=\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->fixture]))->mustRun();
        parent::tearDown();
    }

    /**
     * Writes an artisan stub whose `test` step prints $summary and exits
     * $exit, regardless of the arguments the gate forwards. This is the point:
     * a runner can exit 0 while its summary reports warnings.
     */
    private function stubArtisan(string $summary, int $exit = 0): void
    {
        file_put_contents($this->fixture.'/artisan', '<?php
if ($argv[1] === "config:clear") { exit(0); }
if ($argv[1] === "key:generate") { exit(0); }
if ($argv[1] !== "test") { exit(99); }
fwrite(STDOUT, '.var_export($summary."\n", true).');
exit('.$exit.');
');
    }

    private function runGate(): Process
    {
        $run = new Process(['bash', dirname(__DIR__, 2).'/scripts/gc-verify.sh'], $this->fixture);
        $run->setTimeout(60);
        $run->run();

        return $run;
    }

    public static function warningFloors(): array
    {
        return [
            // The measured no-.env floor, verbatim in shape.
            'laravel no-env floor' => ['  Tests:    7286 warnings, 467 passed (48511 assertions)'],
            'laravel single warning' => ['  Tests:    1 warnings, 466 passed (100 assertions)'],
            // PHPUnit TextUI dialect, in case artisan is bypassed.
            'phpunit textui' => ['Tests: 1, Assertions: 1, Warnings: 1.'],
        ];
    }

    /**
     * THE RED CHECK. Against the unfixed gc-verify.sh every one of these
     * printed `gc-verify: PASS` and exited 0, because the runner exits 0.
     */
    #[DataProvider('warningFloors')]
    public function test_gate_fails_on_a_warning_floor_that_exits_zero(string $summary): void
    {
        $this->stubArtisan($summary, 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        self::assertStringNotContainsString('gc-verify: PASS', $output);
        // Must not proceed to later gates once gate 1 has failed.
        self::assertStringNotContainsString('[2/3]', $output);
    }

    public static function cleanSummaries(): array
    {
        return [
            'laravel green' => ['  Tests:    157 skipped, 7596 passed (48511 assertions)'],
            'laravel passed only' => ['  Tests:    15 passed (17 assertions)'],
            // A fully clean TextUI run prints ONLY this line — no `Tests:` line.
            'phpunit ok' => ['Tests: 21, Assertions: 77, Skipped: 2.'],
            'phpunit ok plural' => ['OK (7611 tests, 48564 assertions)'],
            // The "OK, but ..." variants do still carry a `Tests:` counts line.
            'phpunit ok but skipped' => ['OK, but some tests were skipped!'."\n".'Tests: 2, Assertions: 1, Skipped: 1.'],
            // Policies this change deliberately does NOT tighten.
            'risky still passes' => ['Tests: 1, Assertions: 0, Risky: 1.'],
            'deprecation still passes' => ['Tests: 1, Assertions: 1, Deprecations: 1.'],
        ];
    }

    /**
     * POSITIVE CONTROL for the detector: if the new parser simply failed
     * everything, these would fail too. A zero-warning summary must still PASS,
     * and risky/deprecation policy must be unchanged.
     */
    #[DataProvider('cleanSummaries')]
    public function test_gate_still_passes_a_zero_warning_summary(string $summary): void
    {
        $this->stubArtisan($summary, 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
        self::assertStringNotContainsString('gc-verify: FAIL', $output);
    }

    /**
     * Each row pairs a summary with the STDERR discriminator the gate must emit
     * for it. Asserting only "it failed" lets a row keep passing for the wrong
     * reason after a refactor -- a review panel's finding on this file, and a
     * fair one: rows added to pin different refusals were indistinguishable to
     * the old assertions.
     *
     * COUNT CORRECTED. An earlier version of this docblock said "four distinct
     * refusals". A round-2 review counted them and the real number is SEVEN,
     * measured in scripts/gc-verify.sh's assert_no_warnings():
     *   1. no PHPUnit summary line found
     *   2. unbalanced parentheses in PHPUnit summary        (added by #2532)
     *   3. PHPUnit summary line carries no counts           (added by #2532)
     *   4. unrecognised token in PHPUnit summary
     *   5. unknown count '<label>'
     *   6. PHPUnit summary line carries no readable counts
     *   7. reported N warning(s); gate 1 requires zero
     * Rows below pin 4, 5 and 7. Refusals 1, 2, 3 and 6 have NO discriminator
     * control -- including both guards #2532 added to carry its fail-closed
     * behaviour. That gap is filed as residual debt rather than papered over:
     * a docblock that undercounts the thing it documents is how a guard quietly
     * loses its coverage.
     */
    public static function unknownDialects(): array
    {
        return [
            // An earlier draft of this parser had a catch-all third
            // branch that declared warnings=0 for ANY count-bearing line, so a
            // renamed warning token silently restored the exact floor this
            // change exists to close. These are the realistic shapes; the old
            // digit-free fixture below only ever exercised the degenerate one.
            'renamed warning token' => ['  Tests:    7286 warned, 467 passed (48511 assertions)', "unknown count 'warned'"],
            'decorated warning token' => ['  Tests:    7286 warnings(!), 467 passed (48511 assertions)', 'unrecognised token'],
            'textui renamed token' => ['Tests: 21, Assertions: 77, Warned: 3.', "unknown count 'warned'"],
            'wholly unknown count' => ['  Tests:    5 gremlins, 467 passed (48511 assertions)', "unknown count 'gremlins'"],
            // GitHub #2612: the non-count metric branch skips `Duration:`/`Time:`/
            // `Memory:`. Its unit list is CLOSED precisely so that it cannot be
            // used to smuggle a warning count past the gate. An earlier draft
            // ended the value pattern with a bare `[A-Za-z]*`, which matched the
            // word `warnings` and accepted this line while printing warnings=0.
            'metric label carrying a warning count' => ['Tests: 21 passed (33 assertions), Duration: 5 warnings', 'unrecognised token'],
            'metric label carrying an unknown count' => ['Tests: 21 passed (33 assertions), Duration: 5 gremlins', 'unrecognised token'],
            // A near-miss label must not inherit the metric exemption.
            'near-miss metric label' => ['Tests: 21 passed (33 assertions), Timeouts: 3', "unknown count 'timeouts'"],
            'pluralised metric label' => ['Tests: 21 passed (33 assertions), Durations: 3', "unknown count 'durations'"],
            // A metric must not stand in as proof that a real count was read:
            // with no countable token this line is still unreadable. This is the
            // `seen` decrement's control, and it pins the SEEN==0 refusal
            // specifically -- not merely "some failure happened".
            'metric only, no real count' => ['Tests: Duration: 0.66s', 'carries no readable counts'],
            // Integer-valued twin of the row above. Without it, deleting the
            // metric branch's ordering AND its `seen` decrement could leave this
            // line passing with no count ever read: a two-edit hole the panel
            // spotted in the first round of review on this change.
            'metric only, integer value' => ['Tests: Duration: 1', 'carries no readable counts'],
            // The metric exemption must not suppress a real warnings count that
            // shares the line with it.
            'metric beside a real warning floor' => ['Tests: 467 passed (7286 warnings, 48511 assertions), Duration: 1.2s', 'reported 7286 warning'],
            // The value pattern is ENUMERATED, not a loose `[0-9][0-9.:]*`.
            // A measurement this gate cannot parse is refused like any other
            // unreadable token: an instrument that fails closed on what it
            // cannot read must not quietly accept a malformed number either.
            // (Terminal malformed values such as `1...` are normalised away by
            // the pre-existing end-of-line strip on the `body=` line, so these
            // are placed mid-line where that strip does not reach them.)
            'malformed metric value, colons' => ['Tests: 21 passed (33 assertions), Duration: 1:2:3:4:5', 'unrecognised token'],
            // The clock form stops at HH:MM:SS(.fff), which is php-timer's
            // widest output. A fourth field is a shape no runner emits and must
            // stay refused, so admitting the hours field cannot later drift into
            // a loose colon-separated value.
            'malformed metric value, four clock fields' => ['Tests: 21 passed (33 assertions), Duration: 01:02:03:04', 'unrecognised token'],
            'malformed metric value, multiple dots' => ['Tests: 21 passed (33 assertions), Duration: 1.2.3, 7 passed', 'unrecognised token'],
            'malformed metric value, leading dots' => ['Tests: 21 passed (33 assertions), Duration: ..5, 7 passed', 'unrecognised token'],
        ];
    }

    /**
     * GitHub #2612. Pest prints `Duration: 0.66s` and PHPUnit TextUI prints
     * `Time:` / `Memory:`. When such a metric shares the `Tests:` line it was
     * refused, in two different ways depending on the value: a decimal matched
     * no token pattern at all, while an integer parsed as the `Label: count`
     * dialect and then died on the label allow-list. Both were correct
     * fail-closed behaviour on an unreadable token, but they are measurements
     * rather than outcome counts and carry no warning information.
     *
     * These are the shapes that must now PASS. Note `Duration: 1`: the metric
     * branch has to be tested BEFORE the generic `Label: count` branch, or the
     * integer form is claimed by that branch and still fails -- so this case is
     * what keeps the two halves of the defect from drifting apart.
     */
    public static function nonCountMetrics(): array
    {
        return [
            'pest duration seconds' => ['Tests: 21 passed (33 assertions), Duration: 0.66s'],
            'pest duration integer' => ['Tests: 21 passed (33 assertions), Duration: 1'],
            'pest duration milliseconds' => ['Tests: 21 passed (33 assertions), Duration: 1.2 ms'],
            'textui time' => ['Tests: 21 passed (33 assertions), Time: 0.66'],
            'textui clock-formatted time' => ['Tests: 21 passed (33 assertions), Time: 00:02.729'],
            // php-timer's Duration::asString() prepends an `HH:` field once
            // hours > 0, so any run at or over an hour prints this shape -- and
            // this suite is 7714 tests. The value enumeration admitted only one
            // colon group, so it refused a legitimate zero-warning line as an
            // unrecognised token: the same false FAIL this change exists to
            // close, in the same vendor package the unit list was verified in.
            'textui clock-formatted time with hours' => ['Tests: 21 passed (33 assertions), Time: 01:02:03.456'],
            'textui clock-formatted time with hours, whole second' => ['Tests: 21 passed (33 assertions), Time: 01:02:03'],
            'textui memory' => ['Tests: 21 passed (33 assertions), Memory: 24.00 MB'],
            // PHPUnit's OWN formatter emits these: php-timer's
            // ResourceUsageFormatter::bytesToString() knows only GB/MB/KB and
            // falls through to `N byte(s)` for a peak under 1024. Omitting them
            // left the fix incomplete on its own premise -- found by review,
            // confirmed against the installed vendor source rather than assumed.
            'textui memory in bytes' => ['Tests: 21 passed (33 assertions), Memory: 512 bytes'],
            'textui memory singular byte' => ['Tests: 21 passed (33 assertions), Memory: 1 byte'],
            // Every remaining unit in the closed list, so that deleting one is a
            // red suite rather than a silent narrowing. The list is the branch's
            // only safety boundary; an unexercised boundary is not a boundary.
            'unit us' => ['Tests: 21 passed (33 assertions), Duration: 900 us'],
            'unit sec' => ['Tests: 21 passed (33 assertions), Duration: 3 sec'],
            'unit secs' => ['Tests: 21 passed (33 assertions), Duration: 3 secs'],
            'unit seconds' => ['Tests: 21 passed (33 assertions), Duration: 3 seconds'],
            'unit m' => ['Tests: 21 passed (33 assertions), Duration: 2 m'],
            'unit min' => ['Tests: 21 passed (33 assertions), Duration: 2 min'],
            'unit b' => ['Tests: 21 passed (33 assertions), Memory: 900 b'],
            'unit kb' => ['Tests: 21 passed (33 assertions), Memory: 64 kb'],
            'unit gb' => ['Tests: 21 passed (33 assertions), Memory: 2 gb'],
            // Case-insensitivity applies to the label AND the unit.
            'uppercase label and unit' => ['Tests: 21 passed (33 assertions), DURATION: 1 S'],
        ];
    }

    #[DataProvider('nonCountMetrics')]
    public function test_non_count_metric_on_the_tests_line_passes(string $summary): void
    {
        $this->stubArtisan($summary, 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
        self::assertStringContainsString('warnings=0', $output);
    }

    /**
     * A `Duration:` on its OWN line was never read by the summary parser and was
     * always harmless -- which is why the common Pest layout never tripped this.
     * Pinned so a future parser change that starts consuming following lines has
     * to confront this case deliberately.
     */
    public function test_metric_on_its_own_line_is_not_read(): void
    {
        $this->stubArtisan("  Tests:    21 passed (33 assertions)\n  Duration: 0.66s", 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
    }

    /**
     * An unrecognised count token must FAIL. The gate does not get to assume a
     * token it cannot read meant zero warnings.
     */
    #[DataProvider('unknownDialects')]
    public function test_unknown_summary_token_fails_closed(string $summary, string $becauseStderrSays): void
    {
        $this->stubArtisan($summary, 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        self::assertStringNotContainsString('gc-verify: PASS', $output);
        self::assertStringNotContainsString('warnings=0', $output);
        // WHY it failed, not merely that it failed. Without this a row can drift
        // onto a different refusal path and still look green.
        self::assertStringContainsString($becauseStderrSays, $output);
    }

    /**
     * Verified against PHPUnit at source: a fully clean TextUI run prints
     * "OK (n tests, m assertions)" INSTEAD of a `Tests:` line. That
     * legitimate zero-warning shape must PASS, not hit the fail-closed branch.
     */
    public function test_clean_textui_ok_footer_passes(): void
    {
        $this->stubArtisan("...\n\nTime: 00:02.729, Memory: 14.00 MB\n\nOK (7611 tests, 48564 assertions)", 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
        self::assertStringContainsString('warnings=0', $output);
    }

    /** An unreadable summary must FAIL, never PASS: the gate fails closed. */
    public function test_unparseable_summary_fails_closed(): void
    {
        $this->stubArtisan('Tests: an entirely new summary dialect', 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        self::assertStringNotContainsString('gc-verify: PASS', $output);
    }

    /** No summary line at all is equally unprovable, so equally a FAIL. */
    public function test_missing_summary_fails_closed(): void
    {
        $this->stubArtisan('the runner printed nothing useful', 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
    }

    /** A non-zero runner exit is still a FAIL; belt one is not weakened. */
    public function test_runner_failure_still_fails(): void
    {
        $this->stubArtisan('  Tests:    1 failed, 466 passed (100 assertions)', 1);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
    }
}
