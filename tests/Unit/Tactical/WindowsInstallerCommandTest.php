<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\WindowsInstallerCommand;
use PHPUnit\Framework\TestCase;

class WindowsInstallerCommandTest extends TestCase
{
    private static function command(string $arch = 'amd64'): string
    {
        return 'tacticalagent-v2.9.1-windows-'.$arch.'.exe /VERYSILENT /SUPPRESSMSGBOXES'
            .' && ping 127.0.0.1 -n 7 && "C:\\Program Files\\TacticalAgent\\tacticalrmm.exe" -m install'
            .' --api https://rmm.example.test --client-id 3 --site-id 5 --agent-type workstation --auth synthetic-token';
    }

    /** @dataProvider architectures */
    public function test_download_then_validated_install_then_enrollment_with_safe_errors(string $arch): void
    {
        $filename = 'tacticalagent-v2.9.1-windows-'.$arch.'.exe';
        // Public release and signed download are chosen upstream, not guessed here.
        foreach (['https://github.com/amidaware/rmmagent/releases/download/v2.9.1/'.$filename, "https://downloads.example.test/agent?token=a'b&x=1"] as $url) {
            $result = WindowsInstallerCommand::build(self::command($arch), $url, $arch);
            $this->assertNotNull($result);
            $this->assertSame($filename, $result['filename']);
            $script = $result['script'];
            $this->assertStringContainsString(str_replace("'", "''", $url), $script);
            foreach (["\$ErrorActionPreference = 'Stop'", 'IsInRole', '[Guid]::NewGuid()', "Join-Path \$dir '".$filename."'", '-OutFile $file -ErrorAction Stop', 'Length -lt 1024', 'ReadByte() -ne 77', 'ReadByte() -ne 90', '-Wait -PassThru', '$process.ExitCode -ne 0', '$LASTEXITCODE -ne 0', "'--client-id','3','--site-id','5'", "'--auth','synthetic-token'", 'finally', 'Remove-Item -LiteralPath $file'] as $guard) {
                $this->assertStringContainsString($guard, $script);
            }
            $this->assertStringContainsString('Invoke-WebRequest -UseBasicParsing', $script);
            $this->assertNotFalse(strpos($script, 'Start-Process'));
            $this->assertNotFalse(strpos($script, 'Invoke-WebRequest'));
            $this->assertLessThan(strpos($script, 'Start-Process'), strpos($script, 'Invoke-WebRequest'));
            $this->assertLessThan(strpos($script, '& $agent'), strpos($script, '$process.ExitCode -ne 0'));
            $this->assertStringNotContainsString('&&', $script);
            $this->assertStringNotContainsString('Invoke-Expression', $script);
            $this->assertStringNotContainsString('Bypass', $script);
            $this->assertStringNotContainsString('Exception.Message', $script);
            $this->assertStringNotContainsString('Write-Error $_', $script);
        }
    }

    public static function architectures(): array
    {
        return [['amd64'], ['386']];
    }

    /** @dataProvider unsafeInputs */
    public function test_refuses_unknown_command_or_unsafe_url(string $suffix, mixed $url, string $arch = 'amd64'): void
    {
        $this->assertNull(WindowsInstallerCommand::build(self::command().$suffix, $url, $arch));
    }

    public static function unsafeInputs(): array
    {
        return [
            [' & calc.exe', 'https://example.test/a'],
            [' --insecure', 'https://example.test/a'],
            ["\n", 'https://example.test/a'],
            ['', 'http://example.test/a'],
            ['', 'file:///tmp/a'],
            ['', 'https://user:pass@example.test/a'],
            ['', "https://example.test/a\n"],
            ['', ['not-a-url']],
            ['', 'https://example.test/a', '386'],
            ['', 'https://example.test/a', 'arm64'],
        ];
    }

    public function test_feature_flags_are_arguments_not_shell_syntax(): void
    {
        $result = WindowsInstallerCommand::build(self::command().' --rdp --ping --power', 'https://example.test/a', 'amd64');
        $this->assertNotNull($result);
        $this->assertStringContainsString("'--rdp','--ping','--power'", $result['script']);
    }
}
