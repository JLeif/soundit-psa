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
            // The measured a453 r3 / no-.env floor, verbatim in shape.
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
            'phpunit ok' => ['OK (1 test, 1 assertion)'],
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
