<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Gate 0: gc-verify.sh must provision .env the way CI does, or refuse to start.
 *
 * A worktree created without .env does not fail loudly — phpdotenv's
 * @-suppressed read at Store/File/Reader.php:73 is surfaced per test process,
 * PHPUnit relabels passes as `warnings`, and the gate still exits 0. Depending
 * on someone remembering wt/README.md:35 ("Copy .env from psa-work") is not a
 * safeguard, so the gate provisions it itself.
 *
 * Provisioning source is .env.example (tracked in the repo, what CI uses), NOT
 * a populated .env copied from another checkout: a gate path has no business
 * holding real credentials.
 */
class GcVerifyEnvProvisionTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/gc-env-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        (new Process(['git', 'init', '--quiet', $this->fixture]))->mustRun();
        // A clean, zero-warning runner, so these cases isolate .env handling.
        file_put_contents($this->fixture.'/artisan', '<?php
if ($argv[1] === "config:clear") { exit(0); }
if ($argv[1] === "key:generate") {
    $env = getcwd()."/.env";
    file_put_contents($env, preg_replace("/^APP_KEY=.*$/m", "APP_KEY=base64:GENERATED", file_get_contents($env)));
    exit(0);
}
if ($argv[1] !== "test") { exit(99); }
fwrite(STDOUT, "  Tests:    15 passed (17 assertions)\n");
exit(0);
');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->fixture]))->mustRun();
        parent::tearDown();
    }

    private function runGate(): Process
    {
        $run = new Process(['bash', dirname(__DIR__, 2).'/scripts/gc-verify.sh'], $this->fixture);
        $run->setTimeout(60);
        $run->run();

        return $run;
    }

    /** RED: the unfixed gate ran happily with no .env at all. */
    public function test_missing_env_is_provisioned_from_example(): void
    {
        file_put_contents($this->fixture.'/.env.example', "APP_NAME=\"PSA\"\nAPP_KEY=\n");
        self::assertFileDoesNotExist($this->fixture.'/.env');

        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertFileExists($this->fixture.'/.env');
        self::assertStringContainsString('provisioning from .env.example', $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
        // Provisioned from the example, and keyed.
        $env = file_get_contents($this->fixture.'/.env');
        self::assertStringContainsString('APP_NAME="PSA"', $env);
        self::assertStringContainsString('APP_KEY=base64:', $env);
    }

    /** No .env and no .env.example: refuse to start rather than guess. */
    public function test_no_env_and_no_example_refuses_to_start(): void
    {
        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        self::assertStringNotContainsString('gc-verify: PASS', $output);
        self::assertStringNotContainsString('[2/3]', $output);
    }

    /** An existing but unkeyed .env is a refusal, not a file to rewrite. */
    public function test_existing_env_without_app_key_refuses_rather_than_rewriting(): void
    {
        file_put_contents($this->fixture.'/.env', "APP_NAME=\"PSA\"\nAPP_KEY=\n");
        file_put_contents($this->fixture.'/.env.example', "APP_KEY=\n");

        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(1, $run->getExitCode(), $output);
        self::assertStringContainsString('APP_KEY', $output);
        self::assertStringContainsString('gc-verify: FAIL', $output);
        // The gate did not touch a .env it did not create.
        self::assertStringContainsString("APP_KEY=\n", file_get_contents($this->fixture.'/.env'));
    }

    /** POSITIVE CONTROL: an already-provisioned .env is left exactly alone. */
    public function test_existing_keyed_env_is_not_modified(): void
    {
        $original = "APP_NAME=\"PSA\"\nAPP_KEY=base64:ORIGINALKEYVALUE\n";
        file_put_contents($this->fixture.'/.env', $original);
        file_put_contents($this->fixture.'/.env.example', "APP_KEY=\n");

        $run = $this->runGate();
        $output = $run->getOutput().$run->getErrorOutput();

        self::assertSame(0, $run->getExitCode(), $output);
        self::assertStringContainsString('gc-verify: PASS', $output);
        self::assertSame($original, file_get_contents($this->fixture.'/.env'));
        self::assertStringNotContainsString('provisioning from .env.example', $output);
    }
}
