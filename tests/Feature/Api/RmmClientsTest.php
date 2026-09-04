<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/rmm/clients — the read surface Leif RMM consumes.
 *
 * This exists because the PSA previously had no machine-consumable way to answer
 * "who are the clients". Every `api/clients` route carries web+auth — session
 * cookies plus CSRF — so no credential a service could hold would work. The
 * consequence downstream was concrete: the RMM's coverage matrix had nothing to
 * attach devices to.
 *
 * The auth cases below matter as much as the payload ones. A shared bearer token
 * that can be probed, or an endpoint that answers when unconfigured, is a worse
 * outcome than the gap it was built to close.
 */
class RmmClientsTest extends TestCase
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
        // Dormant rather than open. Note no key is set at all here.
        Client::factory()->create();

        $this->getJson('/api/rmm/clients', ['Authorization' => 'Bearer anything'])
            ->assertStatus(401);
    }

    public function test_refuses_without_an_authorization_header(): void
    {
        $this->configure();

        $this->getJson('/api/rmm/clients')->assertStatus(401);
    }

    public function test_refuses_a_wrong_key(): void
    {
        $this->configure();

        $this->getJson('/api/rmm/clients', ['Authorization' => 'Bearer not-the-key'])
            ->assertStatus(401);
    }

    public function test_refuses_a_malformed_authorization_header(): void
    {
        $this->configure();

        foreach (['Bearer', 'Bearer   ', self::KEY, 'Basic '.self::KEY] as $header) {
            $this->getJson('/api/rmm/clients', ['Authorization' => $header])
                ->assertStatus(401);
        }
    }

    public function test_every_refusal_looks_the_same(): void
    {
        // Otherwise the endpoint tells an attacker whether a guessed key was
        // close, or whether the integration is configured at all.
        $this->configure();

        $wrong = $this->getJson('/api/rmm/clients', ['Authorization' => 'Bearer wrong']);
        $missing = $this->getJson('/api/rmm/clients');

        $this->assertSame($wrong->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($wrong->json(), $missing->json());
    }

    public function test_accepts_the_configured_key(): void
    {
        $this->configure();

        $this->getJson('/api/rmm/clients', $this->authed())->assertOk();
    }

    // -- payload --------------------------------------------------------------

    public function test_returns_every_client_with_a_count(): void
    {
        $this->configure();
        Client::factory()->count(3)->create();

        $response = $this->getJson('/api/rmm/clients', $this->authed())->assertOk();

        $this->assertCount(3, $response->json('clients'));
        // An explicit count so a short or truncated read is detectable rather
        // than silently passing as "that is all of them".
        $this->assertSame(3, $response->json('count'));
    }

    public function test_returns_the_integration_ids_the_rmm_joins_on(): void
    {
        $this->configure();
        $client = Client::factory()->create([
            'name' => 'Snohomish Community Food Bank',
            'huntress_organization_id' => '215391',
            'controld_org_id' => 'cd-77',
        ]);

        $body = $this->getJson('/api/rmm/clients', $this->authed())->assertOk()->json('clients.0');

        $this->assertSame($client->id, $body['id']);
        $this->assertSame('Snohomish Community Food Bank', $body['name']);
        $this->assertSame('215391', $body['huntress_organization_id']);
        $this->assertSame('cd-77', $body['controld_org_id']);
    }

    public function test_vendor_ids_are_strings_or_null_never_numbers(): void
    {
        // They land in the RMM's external_ref.external_id, which is TEXT. An id
        // that arrives as 1001 in one place and "1001" in another is a join that
        // fails for no visible reason.
        $this->configure();
        Client::factory()->create(['huntress_organization_id' => 215391]);

        $body = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.0');

        $this->assertIsString($body['huntress_organization_id']);
        $this->assertSame('215391', $body['huntress_organization_id']);
    }

    public function test_an_unmapped_client_reports_null_not_an_empty_string(): void
    {
        // "" would let the consumer write a mapping to an organisation that does
        // not exist. Absent must read as absent.
        $this->configure();
        Client::factory()->create([
            'huntress_organization_id' => null,
            'controld_org_id' => '',
        ]);

        $body = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.0');

        $this->assertNull($body['huntress_organization_id']);
        $this->assertNull($body['controld_org_id']);
    }

    public function test_a_zero_vendor_id_reports_null(): void
    {
        // huntress_organization_id is cast to integer on the model, so a blank
        // that ever reached the database arrives as 0 rather than "". Returning
        // "0" would produce a confident join to an organisation that does not
        // exist, which is worse than no mapping at all.
        $this->configure();
        Client::factory()->create(['huntress_organization_id' => 0]);

        $body = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.0');

        $this->assertNull($body['huntress_organization_id']);
    }

    public function test_inactive_clients_are_returned_and_marked(): void
    {
        // The RMM still has to account for their devices; dropping them would
        // make the estate silently smaller.
        $this->configure();
        Client::factory()->create(['is_active' => false]);

        $body = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.0');

        $this->assertFalse($body['is_active']);
    }

    public function test_soft_deleted_clients_are_excluded(): void
    {
        $this->configure();
        Client::factory()->create(['name' => 'Still here']);
        Client::factory()->create(['name' => 'Gone'])->delete();

        $response = $this->getJson('/api/rmm/clients', $this->authed())->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame('Still here', $response->json('clients.0.name'));
    }

    public function test_does_not_leak_columns_the_rmm_has_no_business_with(): void
    {
        // Minimal disclosure: this is a shared bearer token on a read surface,
        // so it returns identity and mappings and nothing else.
        $this->configure();
        Client::factory()->create();

        $body = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.0');

        $this->assertSame([
            'id',
            'name',
            'is_active',
            'huntress_organization_id',
            'controld_org_id',
            'tactical_site_id',
        ], array_keys($body));
    }

    public function test_is_ordered_stably_by_id(): void
    {
        // A consumer diffing successive reads needs the order not to wander.
        $this->configure();
        Client::factory()->count(4)->create();

        $ids = $this->getJson('/api/rmm/clients', $this->authed())->json('clients.*.id');

        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_answers_with_an_empty_list_rather_than_an_error_when_there_are_no_clients(): void
    {
        // Zero clients is a legitimate state, and it must be distinguishable
        // from a failure - the consumer treats an error as "do not change
        // anything" and an empty list as "the PSA asserts nothing".
        $this->configure();

        $response = $this->getJson('/api/rmm/clients', $this->authed())->assertOk();

        $this->assertSame([], $response->json('clients'));
        $this->assertSame(0, $response->json('count'));
    }

    // -- shape ----------------------------------------------------------------

    public function test_the_surface_is_read_only(): void
    {
        $this->configure();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/rmm/clients', [], $this->authed())
                ->assertStatus(405);
        }
    }
}
