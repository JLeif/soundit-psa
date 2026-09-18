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

    public static function unknownDialects(): array
    {
        return [
            // An earlier draft of this parser had a catch-all third
            // branch that declared warnings=0 for ANY count-bearing line, so a
            // renamed warning token silently restored the exact floor this
            // change exists to close. These are the realistic shapes; the old
            // digit-free fixture below only ever exercised the degenerate one.
            'renamed warning token' => ['  Tests:    7286 warned, 467 passed (48511 assertions)'],
            'decorated warning token' => ['  Tests:    7286 warnings(!), 467 passed (48511 assertions)'],
            'textui renamed token' => ['Tests: 21, Assertions: 77, Warned: 3.'],
            'wholly unknown count' => ['  Tests:    5 gremlins, 467 passed (48511 assertions)'],
            // GitHub #2612: the non-count metric branch skips `Duration:`/`Time:`/
            // `Memory:`. Its unit list is CLOSED precisely so that it cannot be
            // used to smuggle a warning count past the gate. An earlier draft
            // ended the value pattern with a bare `[A-Za-z]*`, which matched the
            // word `warnings` and accepted this line while printing warnings=0.
            'metric label carrying a warning count' => ['Tests: 21 passed (33 assertions), Duration: 5 warnings'],
            'metric label carrying an unknown count' => ['Tests: 21 passed (33 assertions), Duration: 5 gremlins'],
            // A near-miss label must not inherit the metric exemption.
            'near-miss metric label' => ['Tests: 21 passed (33 assertions), Timeouts: 3'],
            'pluralised metric label' => ['Tests: 21 passed (33 assertions), Durations: 3'],
            // A metric must not stand in as proof that a real count was read:
            // with no countable token this line is still unreadable.
            'metric only, no real count' => ['Tests: Duration: 0.66s'],
            // The metric exemption must not suppress a real warnings count that
            // shares the line with it.
            'metric beside a real warning floor' => ['Tests: 467 passed (7286 warnings, 48511 assertions), Duration: 1.2s'],
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
            'textui memory' => ['Tests: 21 passed (33 assertions), Memory: 24.00 MB'],
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
    public function test_unknown_summary_token_fails_closed(string $summary): void
    {
        $this->stubArtisan($summary, 0);
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        self::assertStringNotContainsString('gc-verify: PASS', $output);
        self::assertStringNotContainsString('warnings=0', $output);
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
