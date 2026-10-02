<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\MintsApiToken;
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
            'v1' => ['/api/v1/clients'],
            'alias /api/rmm' => ['/api/rmm/clients'],
        ];
    }

    // -- authentication -------------------------------------------------------

    #[DataProvider('paths')]
    public function test_refuses_when_no_key_is_configured(string $path): void
    {
        // Dormant rather than open. Note no key is set at all here.
        Client::factory()->create();

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

        $this->getJson($path, ['Authorization' => 'Bearer not-the-key'])
            ->assertStatus(401);
    }

    #[DataProvider('paths')]
    public function test_refuses_a_malformed_authorization_header(string $path): void
    {
        $this->configure();

        foreach (['Bearer', 'Bearer   ', $this->plain, 'Basic '.$this->plain] as $header) {
            $this->getJson($path, ['Authorization' => $header])
                ->assertStatus(401);
        }
    }

    #[DataProvider('paths')]
    public function test_every_refusal_looks_the_same(string $path): void
    {
        // Otherwise the endpoint tells an attacker whether a guessed key was
        // close, or whether the integration is configured at all.
        $this->configure();

        $wrong = $this->getJson($path, ['Authorization' => 'Bearer wrong']);
        $missing = $this->getJson($path);

        $this->assertSame($wrong->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($wrong->json(), $missing->json());
    }

    #[DataProvider('paths')]
    public function test_accepts_the_configured_key(string $path): void
    {
        $this->configure();

        $this->getJson($path, $this->authed())->assertOk();
    }

    // -- payload --------------------------------------------------------------

    #[DataProvider('paths')]
    public function test_returns_every_client_with_a_count(string $path): void
    {
        $this->configure();
        Client::factory()->count(3)->create();

        $response = $this->getJson($path, $this->authed())->assertOk();

        $this->assertCount(3, $response->json('clients'));
        // An explicit count so a short or truncated read is detectable rather
        // than silently passing as "that is all of them".
        $this->assertSame(3, $response->json('count'));
    }

    #[DataProvider('paths')]
    public function test_returns_the_integration_ids_the_rmm_joins_on(string $path): void
    {
        $this->configure();
        $client = Client::factory()->create([
            'name' => 'Example Client Co',
            'huntress_organization_id' => '900001',
            'controld_org_id' => 'cd-77',
        ]);

        $body = $this->getJson($path, $this->authed())->assertOk()->json('clients.0');

        $this->assertSame($client->id, $body['id']);
        $this->assertSame('Example Client Co', $body['name']);
        $this->assertSame('900001', $body['huntress_organization_id']);
        $this->assertSame('cd-77', $body['controld_org_id']);
    }

    #[DataProvider('paths')]
    public function test_vendor_ids_are_strings_or_null_never_numbers(string $path): void
    {
        // They land in the RMM's external_ref.external_id, which is TEXT. An id
        // that arrives as 1001 in one place and "1001" in another is a join that
        // fails for no visible reason.
        $this->configure();
        Client::factory()->create(['huntress_organization_id' => 900001]);

        $body = $this->getJson($path, $this->authed())->json('clients.0');

        $this->assertIsString($body['huntress_organization_id']);
        $this->assertSame('900001', $body['huntress_organization_id']);
    }

    #[DataProvider('paths')]
    public function test_an_unmapped_client_reports_null_not_an_empty_string(string $path): void
    {
        // "" would let the consumer write a mapping to an organisation that does
        // not exist. Absent must read as absent.
        $this->configure();
        Client::factory()->create([
            'huntress_organization_id' => null,
            'controld_org_id' => '',
        ]);

        $body = $this->getJson($path, $this->authed())->json('clients.0');

        $this->assertNull($body['huntress_organization_id']);
        $this->assertNull($body['controld_org_id']);
    }

    #[DataProvider('paths')]
    public function test_a_zero_vendor_id_reports_null(string $path): void
    {
        // huntress_organization_id is cast to integer on the model, so a blank
        // that ever reached the database arrives as 0 rather than "". Returning
        // "0" would produce a confident join to an organisation that does not
        // exist, which is worse than no mapping at all.
        $this->configure();
        Client::factory()->create(['huntress_organization_id' => 0]);

        $body = $this->getJson($path, $this->authed())->json('clients.0');

        $this->assertNull($body['huntress_organization_id']);
    }

    #[DataProvider('paths')]
    public function test_inactive_clients_are_returned_and_marked(string $path): void
    {
        // The RMM still has to account for their devices; dropping them would
        // make the estate silently smaller.
        $this->configure();
        Client::factory()->create(['is_active' => false]);

        $body = $this->getJson($path, $this->authed())->json('clients.0');

        $this->assertFalse($body['is_active']);
    }

    #[DataProvider('paths')]
    public function test_soft_deleted_clients_are_excluded(string $path): void
    {
        $this->configure();
        Client::factory()->create(['name' => 'Still here']);
        Client::factory()->create(['name' => 'Gone'])->delete();

        $response = $this->getJson($path, $this->authed())->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame('Still here', $response->json('clients.0.name'));
    }

    #[DataProvider('paths')]
    public function test_does_not_leak_columns_the_rmm_has_no_business_with(string $path): void
    {
        // Minimal disclosure: this is a shared bearer token on a read surface,
        // so it returns identity and mappings and nothing else.
        $this->configure();
        Client::factory()->create();

        $body = $this->getJson($path, $this->authed())->json('clients.0');

        $this->assertSame([
            'id',
            'name',
            'is_active',
            'huntress_organization_id',
            'controld_org_id',
            'tactical_site_id',
        ], array_keys($body));
    }

    #[DataProvider('paths')]
    public function test_is_ordered_stably_by_id(string $path): void
    {
        // A consumer diffing successive reads needs the order not to wander.
        $this->configure();
        Client::factory()->count(4)->create();

        $ids = $this->getJson($path, $this->authed())->json('clients.*.id');

        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    #[DataProvider('paths')]
    public function test_answers_with_an_empty_list_rather_than_an_error_when_there_are_no_clients(string $path): void
    {
        // Zero clients is a legitimate state, and it must be distinguishable
        // from a failure - the consumer treats an error as "do not change
        // anything" and an empty list as "the PSA asserts nothing".
        $this->configure();

        $response = $this->getJson($path, $this->authed())->assertOk();

        $this->assertSame([], $response->json('clients'));
        $this->assertSame(0, $response->json('count'));
    }

    // -- shape ----------------------------------------------------------------

    #[DataProvider('paths')]
    public function test_the_surface_is_read_only(string $path): void
    {
        $this->configure();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json($method, $path, [], $this->authed())
                ->assertStatus(405);
        }
    }
}
