<?php

namespace Tests\Feature\Tactical;

use App\Models\TacticalScript;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalScriptSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * psa-0pb9m R3 (A5): `shell` is a platform-compatibility signal consumed by
 * the check-creation guard and the platform_mismatch annotations. An upstream
 * getScripts row without one is DRIFT — it must be stored as the honest NULL
 * and logged loudly, never silently defaulted to 'powershell' (which turned a
 * degraded response into a usable "Windows-compatible" verdict downstream).
 */
class TacticalScriptSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function sync(array $upstreamRows): TacticalScriptSyncService
    {
        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->andReturn($upstreamRows);

        return new TacticalScriptSyncService($tactical);
    }

    public function test_a_present_shell_is_stored_verbatim(): void
    {
        $this->sync([
            ['id' => 7, 'name' => 'Disk check', 'shell' => 'shell', 'supported_platforms' => ['darwin']],
        ])->syncScripts();

        $row = TacticalScript::where('tactical_script_id', 7)->firstOrFail();
        $this->assertSame('shell', $row->shell);
        $this->assertSame(['darwin'], $row->supported_platforms);
    }

    public function test_a_missing_shell_key_is_stored_as_null_and_screams(): void
    {
        Log::spy();

        $this->sync([
            ['id' => 7, 'name' => 'Driftling'], // no shell key at all
        ])->syncScripts();

        $row = TacticalScript::where('tactical_script_id', 7)->firstOrFail();
        $this->assertNull($row->shell, 'absence must be stored as unknown, never manufactured into powershell');

        // One degradation dialect everywhere (R4 U5): the warning names the
        // script, says compatibility could not be verified, and gives the
        // recovery — the same wording as the guard refusal and the
        // read-surface annotation, not a parallel "stored as unknown" idiom.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'no shell')
                && str_contains($message, "'Driftling' (id 7)")
                && str_contains($message, 'could not be verified')
                && str_contains($message, 'verify the script in Tactical')
                && ($context['tactical_script_id'] ?? null) === 7
        );
    }

    public function test_a_blank_shell_value_is_stored_as_null_and_screams(): void
    {
        Log::spy();

        $this->sync([
            ['id' => 8, 'name' => 'Blankling', 'shell' => '  '],
        ])->syncScripts();

        $this->assertNull(TacticalScript::where('tactical_script_id', 8)->firstOrFail()->shell);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context = []): bool => ($context['tactical_script_id'] ?? null) === 8
        );
    }

    public function test_a_resync_replaces_a_previously_stored_shell_with_null_when_upstream_drops_it(): void
    {
        TacticalScript::create([
            'tactical_script_id' => 7,
            'name' => 'Disk check',
            'shell' => 'powershell',
            'synced_at' => now()->subDay(),
        ]);
        Log::spy();

        $this->sync([
            ['id' => 7, 'name' => 'Disk check'],
        ])->syncScripts();

        $this->assertNull(
            TacticalScript::where('tactical_script_id', 7)->firstOrFail()->shell,
            'a stale stored shell must not survive as counterfeit signal once upstream stops emitting one'
        );
    }
}
