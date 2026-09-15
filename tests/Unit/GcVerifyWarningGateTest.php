<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GcVerifyWarningGateTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/gc-warning-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        $git = new Process(['git', 'init', '--quiet', $this->fixture]);
        $git->mustRun();
    }

    protected function tearDown(): void
    {
        $remove = new Process(['rm', '-rf', '--', $this->fixture]);
        $remove->mustRun();
        parent::tearDown();
    }

    public static function outcomes(): array
    {
        return [
            'clean' => ['self::assertTrue(true);', '', 0, 'OK (1 test, 1 assertion)'],
            'warning' => ['trigger_error("deliberate gate warning", E_USER_WARNING); self::assertTrue(true);', '', 1, 'Warnings: 1'],
            'assertion failure' => ['self::assertTrue(false);', '', 1, 'Failures: 1'],
            // These policies are deliberately not tightened by the warnings fix.
            'risky' => ['', '', 0, 'Risky: 1'],
            'application deprecation' => ['trigger_error("deliberate deprecation", E_USER_DEPRECATED); self::assertTrue(true);', '', 0, 'Deprecations: 1'],
            'metadata deprecation' => ['self::assertTrue(true);', '/** @group legacy */', 0, 'PHPUnit Deprecations: 1'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_gate_uses_real_phpunit_exit_policy(string $body, string $metadata, int $exit, string $summary): void
    {
        $root = dirname(__DIR__, 2);
        file_put_contents($this->fixture.'/GateProbeTest.php', "<?php\nclass GateProbeTest extends \\PHPUnit\\Framework\\TestCase {\n$metadata\npublic function test_probe(): void { $body }\n}\n");
        // Tiny artisan adapter: config clear is inert, test runs the installed real
        // PHPUnit with ALL gate arguments forwarded. No nested application suite.
        $phpunit = var_export($root.'/vendor/bin/phpunit', true);
        file_put_contents($this->fixture.'/artisan', '<?php
if ($argv[1] === "config:clear") { exit(0); }
if ($argv[1] !== "test") { exit(99); }
$args = array_merge([PHP_BINARY, '.$phpunit.', "--no-configuration", "--colors=never", "GateProbeTest.php"], array_slice($argv, 2));
passthru(implode(" ", array_map("escapeshellarg", $args)), $status);
exit($status);
');
        $run = new Process(['bash', $root.'/scripts/gc-verify.sh'], $this->fixture);
        $run->setTimeout(60);
        $run->run();
        $output = $run->getOutput().$run->getErrorOutput();
        self::assertStringContainsString($summary, $output);
        self::assertSame($exit, $run->getExitCode(), $output);
        if ($exit !== 0) {
            self::assertStringContainsString('gc-verify: FAIL', $output);
            self::assertStringNotContainsString('gc-verify: PASS', $output);
            self::assertStringNotContainsString('[2/3]', $output);
        } else {
            self::assertStringContainsString('gc-verify: PASS', $output);
            self::assertStringNotContainsString('gc-verify: FAIL', $output);
        }
    }
}
