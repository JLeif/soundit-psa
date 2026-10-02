<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\MintsApiToken;
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
    use MintsApiToken;
    use RefreshDatabase;

    /** Plaintext of the token minted by configure(); '' until then. */
    private string $plain = '';

    /**
     * Ported from the shared rmm_api_key: mint an active ApiToken granted
     * every registry endpoint, as the lits-rmm token will be.
     */
    private function configure(): void
    {
        [, $this->plain] = $this->mintApiToken();
    }

    private function authed(): array
    {
        return $this->bearer($this->plain);
    }

    /** Every HTTP test runs on the canonical v1 path and the /api/rmm alias. */
    public static function paths(): array
    {
        return [
            'v1' => ['/api/v1/assets'],
            'alias /api/rmm' => ['/api/rmm/assets'],
        ];
    }

    // -- authentication -------------------------------------------------------

    #[DataProvider('paths')]
    public function test_refuses_when_no_key_is_configured(string $path): void
    {
        // Dormant rather than open. No key is set at all here.
        Asset::factory()->create();

        $this->getJson($path, ['Authorization' => 'Bearer anything'])
            ->assertStatus(401);
    }

    #[DataProvider('paths')]
    public function test_refuses_without_an_authorization_header(string $path): void
    {
        $this->configure();

        $this->getJson($path)->assertStatus(401);
    }

    #[DataProvider('paths')]
    public function test_refuses_a_wrong_key(string $path): void
    {
        $this->configure();

        $this->getJson($path, ['Authorization' => 'Bearer wrong-key'])
            ->assertStatus(401);
    }

    #[DataProvider('paths')]
    public function test_a_wrong_key_is_indistinguishable_from_a_missing_one(string $path): void
    {
        // The endpoint must not be usable to work out whether a guess was close,
        // or whether the integration is configured at all.
        $this->configure();

        $missing = $this->getJson($path);
        $wrong = $this->getJson($path, ['Authorization' => 'Bearer wrong-key']);

        $this->assertSame($missing->status(), $wrong->status());
        $this->assertSame($missing->json(), $wrong->json());
    }

    // -- payload --------------------------------------------------------------

    #[DataProvider('paths')]
    public function test_returns_the_fields_the_rmm_needs_to_build_a_device(string $path): void
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

        $body = $this->getJson($path, $this->authed())
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

    #[DataProvider('paths')]
    public function test_carries_an_explicit_count_so_a_short_read_is_detectable(string $path): void
    {
        // The consumer refuses a response whose count disagrees with what
        // arrived. A silently short list would import as "these are all the
        // assets" and orphan the rest of the estate.
        $this->configure();
        Asset::factory()->count(5)->create();

        $body = $this->getJson($path, $this->authed())->assertOk()->json();

        $this->assertSame(5, $body['count']);
        $this->assertCount(5, $body['assets']);
    }

    #[DataProvider('paths')]
    public function test_returns_inactive_assets_rather_than_hiding_them(string $path): void
    {
        // A decommissioned machine is still something the RMM has to account
        // for. Vanishing it from the estate is how a device stops being
        // anybody's problem.
        $this->configure();
        Asset::factory()->create(['is_active' => false]);

        $body = $this->getJson($path, $this->authed())->assertOk()->json();

        $this->assertSame(1, $body['count']);
        $this->assertFalse($body['assets'][0]['is_active']);
    }

    #[DataProvider('paths')]
    public function test_blank_hostname_and_serial_come_back_as_null_not_empty_string(string $path): void
    {
        // An empty hostname is not a hostname. Returning "" would let the RMM
        // create a device named nothing; device.hostname is NOT NULL precisely
        // so that cannot happen quietly.
        $this->configure();
        Asset::factory()->create(['hostname' => '   ', 'serial_number' => '']);

        $asset = $this->getJson($path, $this->authed())->assertOk()->json('assets.0');

        $this->assertNull($asset['hostname']);
        $this->assertNull($asset['serial_number']);
    }

    #[DataProvider('paths')]
    public function test_does_not_normalise_the_serial(string $path): void
    {
        // The RMM has ONE authority on what counts as a serial - its serial.ts,
        // which knows the placeholder family. A second opinion here would be a
        // second answer to the same question, and the two would drift.
        $this->configure();
        Asset::factory()->create(['serial_number' => 'Default string']);

        $asset = $this->getJson($path, $this->authed())->assertOk()->json('assets.0');

        $this->assertSame('Default string', $asset['serial_number']);
    }

    #[DataProvider('paths')]
    public function test_returns_an_empty_estate_without_complaint(string $path): void
    {
        $this->configure();

        $body = $this->getJson($path, $this->authed())->assertOk()->json();

        $this->assertSame(0, $body['count']);
        $this->assertSame([], $body['assets']);
    }
}
