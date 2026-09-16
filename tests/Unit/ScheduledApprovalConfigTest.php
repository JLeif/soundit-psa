<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ScheduledApprovalConfigTest extends TestCase
{
    public static function enabledValues(): array
    {
        return [
            'absent defaults off' => [null, false],
            'explicit false stays off' => ['false', false],
            'off spelling stays off' => ['off', false],
            'no spelling stays off' => ['no', false],
            'disabled spelling stays off' => ['disabled', false],
            'unparseable value stays off' => ['fasle', false],
            'explicit true enables' => ['true', true],
            'numeric one enables' => ['1', true],
        ];
    }

    #[DataProvider('enabledValues')]
    public function test_flag_is_loaded_from_isolated_environment(?string $value, bool $expected): void
    {
        $root = dirname(__DIR__, 2);
        // A fresh process gets a fresh Illuminate Env repository. Do not boot the app:
        // neither .env nor bootstrap/cache/config.php may supply the answer.
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$key = 'SCHEDULED_APPROVALS_ENABLED';
unset($_ENV[$key], $_SERVER[$key]);
putenv($key);
$value = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
if ($value !== null) {
    putenv($key.'='.$value);
}
$config = require $argv[1].'/config/scheduled_approvals.php';
echo json_encode(['loaded' => array_key_exists('enabled', $config), 'enabled' => $config['enabled']], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $root, json_encode($value, JSON_THROW_ON_ERROR)]);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['loaded']);
        $this->assertSame($expected, $result['enabled']);
    }
}
