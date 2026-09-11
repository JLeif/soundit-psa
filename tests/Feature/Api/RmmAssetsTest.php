<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/rmm/assets — how devices first come into existence in the RMM.
 *
 * D-004 makes the RMM authoritative for devices, and it has no way to be: the
 * agent that will report device truth does not exist, so the RMM holds zero
 * devices and coverage, reconciliation and the Huntress join are all no-ops
 * over an empty table. This endpoint is the BOOTSTRAP that ends that, and
 * nothing more — once the RMM has its own agent, it stops being how devices
 * arrive.
 *
 * The auth cases matter as much as the payload ones, for the same reason they do
 * on the clients endpoint: a shared bearer token that can be probed, or a
 * surface that answers when unconfigured, is worse than the gap it closes.
 */
class RmmAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rmm-test-key-that-is-suitably-long';

    private function configure(): void
    {
        Setting::setEncrypted('rmm_api_key', self::KEY);
    }

    private function authed(): array
    {
        return ['Authorization' => 'Bearer '.self::KEY];
    }

    // -- authentication -------------------------------------------------------

    public function test_refuses_when_no_key_is_configured(): void
    {
        // Dormant rather than open. No key is set at all here.
        Asset::factory()->create();

        $this->getJson('/api/rmm/assets', ['Authorization' => 'Bearer anything'])
            ->assertStatus(401);
    }

    public function test_refuses_without_an_authorization_header(): void
    {
        $this->configure();

        $this->getJson('/api/rmm/assets')->assertStatus(401);
    }

    public function test_refuses_a_wrong_key(): void
    {
        $this->configure();

        $this->getJson('/api/rmm/assets', ['Authorization' => 'Bearer wrong-key'])
            ->assertStatus(401);
    }

    public function test_a_wrong_key_is_indistinguishable_from_a_missing_one(): void
    {
        // The endpoint must not be usable to work out whether a guess was close,
        // or whether the integration is configured at all.
        $this->configure();

        $missing = $this->getJson('/api/rmm/assets');
        $wrong = $this->getJson('/api/rmm/assets', ['Authorization' => 'Bearer wrong-key']);

        $this->assertSame($missing->status(), $wrong->status());
        $this->assertSame($missing->json(), $wrong->json());
    }

    // -- payload --------------------------------------------------------------

    public function test_returns_the_fields_the_rmm_needs_to_build_a_device(): void
    {
        $this->configure();
        $client = Client::factory()->create();
        Asset::factory()->for($client)->create([
            'hostname' => 'NODE-0001',
            'serial_number' => 'ABC-1234567',
            'asset_type' => 'Windows Workstation',
            'os' => 'Windows 11 Pro',
            'is_active' => true,
        ]);

        $body = $this->getJson('/api/rmm/assets', $this->authed())
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['count']);
        $asset = $body['assets'][0];
        $this->assertSame($client->id, $asset['client_id']);
        $this->assertSame('NODE-0001', $asset['hostname']);
        $this->assertSame('ABC-1234567', $asset['serial_number']);
        $this->assertSame('Windows Workstation', $asset['asset_type']);
        $this->assertSame('Windows 11 Pro', $asset['os']);
        $this->assertTrue($asset['is_active']);
    }

    public function test_carries_an_explicit_count_so_a_short_read_is_detectable(): void
    {
        // The consumer refuses a response whose count disagrees with what
        // arrived. A silently short list would import as "these are all the
        // assets" and orphan the rest of the estate.
        $this->configure();
        Asset::factory()->count(5)->create();

        $body = $this->getJson('/api/rmm/assets', $this->authed())->assertOk()->json();

        $this->assertSame(5, $body['count']);
        $this->assertCount(5, $body['assets']);
    }

    public function test_returns_inactive_assets_rather_than_hiding_them(): void
    {
        // A decommissioned machine is still something the RMM has to account
        // for. Vanishing it from the estate is how a device stops being
        // anybody's problem.
        $this->configure();
        Asset::factory()->create(['is_active' => false]);

        $body = $this->getJson('/api/rmm/assets', $this->authed())->assertOk()->json();

        $this->assertSame(1, $body['count']);
        $this->assertFalse($body['assets'][0]['is_active']);
    }

    public function test_blank_hostname_and_serial_come_back_as_null_not_empty_string(): void
    {
        // An empty hostname is not a hostname. Returning "" would let the RMM
        // create a device named nothing; device.hostname is NOT NULL precisely
        // so that cannot happen quietly.
        $this->configure();
        Asset::factory()->create(['hostname' => '   ', 'serial_number' => '']);

        $asset = $this->getJson('/api/rmm/assets', $this->authed())->assertOk()->json('assets.0');

        $this->assertNull($asset['hostname']);
        $this->assertNull($asset['serial_number']);
    }

    public function test_does_not_normalise_the_serial(): void
    {
        // The RMM has ONE authority on what counts as a serial - its serial.ts,
        // which knows the placeholder family. A second opinion here would be a
        // second answer to the same question, and the two would drift.
        $this->configure();
        Asset::factory()->create(['serial_number' => 'Default string']);

        $asset = $this->getJson('/api/rmm/assets', $this->authed())->assertOk()->json('assets.0');

        $this->assertSame('Default string', $asset['serial_number']);
    }

    public function test_returns_an_empty_estate_without_complaint(): void
    {
        $this->configure();

        $body = $this->getJson('/api/rmm/assets', $this->authed())->assertOk()->json();

        $this->assertSame(0, $body['count']);
        $this->assertSame([], $body['assets']);
    }
}
