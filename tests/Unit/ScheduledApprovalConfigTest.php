<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ScheduledApprovalConfigTest extends TestCase
{
    public function test_retired_environment_key_cannot_supply_an_enabled_config_key(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
putenv('SCHEDULED_APPROVALS_ENABLED=true');
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['booted' => $app->isBooted(), 'enabled_key' => config()->has('scheduled_approvals.enabled')], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $root]);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['booted']);
        $this->assertFalse($result['enabled_key']);
    }
}
