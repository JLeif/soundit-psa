<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** psa-0pb9m — operator surface for the macOS check provisioner. */
class TacticalProvisionMacosCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_tactical_fails_fast(): void
    {
        $this->artisan('tactical:provision-macos-check')
            ->expectsOutputToContain('not configured')
            ->assertFailed();
    }

    public function test_dry_run_prints_plan_and_makes_no_writes(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');

        $client = Client::factory()->create(['name' => 'Acme']);
        $asset = Asset::factory()->create(['client_id' => $client->id, 'hostname' => 'MAC-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'agent-mac-1',
            'hostname' => 'MAC-01',
            'plat' => 'darwin',
            'os' => 'Darwin 23.6.0 arm64',
            'status' => 'online',
            'synced_at' => now(),
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getScripts')->once()->with(true, true)->andReturn([]);
        $tactical->shouldReceive('getAgentChecks')->once()->with('agent-mac-1')->andReturn([]);
        $tactical->shouldNotReceive('createScript');
        $tactical->shouldNotReceive('createCheck');
        $this->app->instance(TacticalClient::class, $tactical);

        $this->artisan('tactical:provision-macos-check')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('MAC-01')
            ->assertSuccessful();
    }
}
