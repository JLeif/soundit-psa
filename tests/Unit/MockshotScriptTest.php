<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs scripts/tests/mockshot.test.sh inside the suite so CI executes it.
 *
 * That script covers scripts/mockshot's argument parsing (exit 1), its
 * playwright-core resolution failure (exit 2 with the install hint) and the
 * render path against a stub playwright-core. It never launches Chromium, so it needs node but no browser. Skipped, not passed,
 * where node is absent.
 */
class MockshotScriptTest extends TestCase
{
    public function test_mockshot_shell_suite_passes(): void
    {
        if ((new ExecutableFinder)->find('node') === null) {
            $this->markTestSkipped('node is not installed; scripts/mockshot cannot run here');
        }

        $script = dirname(__DIR__, 2).'/scripts/tests/mockshot.test.sh';
        $run = new Process(['bash', $script]);
        $run->setTimeout(120);
        $run->run();

        $out = $run->getOutput().$run->getErrorOutput();
        $this->assertSame(0, $run->getExitCode(), "mockshot.test.sh failed:\n".$out);
        $this->assertMatchesRegularExpression('/mockshot tests: [1-9][0-9]* passed, 0 failed/', $out);
    }
}
