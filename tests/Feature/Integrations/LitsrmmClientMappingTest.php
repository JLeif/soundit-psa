<?php

namespace Tests\Feature\Integrations;

use App\Models\Client;
use App\Models\Setting;
use App\Services\ClientIntegrationService;
use App\Services\Litsrmm\LitsrmmClient;
use App\Services\Litsrmm\LitsrmmClientException;
use App\Support\LitsrmmConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1 of the LITSRMM integration: client mapping only.
 *
 * WHAT THESE CONTROLS PIN, AND WHAT THEY CANNOT
 * ---------------------------------------------
 * Every response here is a FIXTURE built from the vendor proposal's own table
 * (card 6ab46339), which specifies `/v1/clients` returning `id` and `name` and
 * nothing more. No live call is made from this suite or from CI.
 *
 * So these tests pin OUR side of the contract: that a twelfth vendor is
 * registered, that the toggle gates it, that the client sends Bearer auth to
 * the configured host and no other, and that it never invents a field. They do
 * NOT establish that the vendor's real response looks like the fixture. The
 * envelope, the pagination signal, and every device field below
 * hostname/OS/online/last-seen are UNDOCUMENTED, and a redacted real
 * `/v1/devices` response is a precondition of the NEXT stage, not this one.
 */
class LitsrmmClientMappingTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function clientWithResponses(array $responses): LitsrmmClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        // The handler seam is the only way to observe what left the process.
        // Asserting on a fake return value would prove nothing about the
        // request that was actually composed.
        return new LitsrmmClient([
            'api_key' => 'test-token-value',
            'base_url' => 'http://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]);
    }

    private function configure(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'test-token-value');
        Setting::setValue('litsrmm_base_url', 'http://litsrmm.test');
    }

    public function test_litsrmm_is_registered_as_a_vendor(): void
    {
        $this->assertContains('litsrmm', ClientIntegrationService::VENDORS,
            'litsrmm must be in VENDORS or the per-client link/unlink routes refuse it');
    }

    public function test_the_registry_entry_names_the_mapping_column_and_label(): void
    {
        $this->configure();

        $data = app(ClientIntegrationService::class)->buildIntegrationsData(Client::factory()->create());

        $this->assertArrayHasKey('litsrmm', $data,
            'buildIntegrationsData must surface litsrmm so the client page can render it');
        $this->assertSame('Leif IT Solutions RMM', $data['litsrmm']['label']);
    }

    public function test_a_client_can_hold_a_litsrmm_mapping(): void
    {
        $client = Client::factory()->create(['litsrmm_client_id' => 'lits-c-42']);

        $this->assertSame('lits-c-42', $client->fresh()->litsrmm_client_id,
            'litsrmm_client_id must be fillable and persisted, or mapping writes are silently dropped');
    }

    public function test_the_mapped_entity_is_reported_against_the_client(): void
    {
        $this->configure();
        $client = Client::factory()->create(['litsrmm_client_id' => 'lits-c-42']);

        $data = app(ClientIntegrationService::class)->buildIntegrationsData($client);

        $this->assertTrue($data['litsrmm']['mapped'],
            'a client carrying litsrmm_client_id must read as mapped');
        $this->assertSame('lits-c-42', (string) $data['litsrmm']['entity_id']);
    }

    // ---- the toggle actually gates the vendor (the Tactical defect class) ----

    public function test_configured_requires_both_credentials(): void
    {
        $this->assertFalse(LitsrmmConfig::isConfigured(), 'nothing configured yet');

        Setting::setEncrypted('litsrmm_api_key', 'test-token-value');
        $this->assertFalse(LitsrmmConfig::isConfigured(),
            'a self-hosted vendor with no base_url is NOT configured: there is no default host to fall back to');

        Setting::setValue('litsrmm_base_url', 'http://litsrmm.test');
        $this->assertTrue(LitsrmmConfig::isConfigured());
    }

    public function test_the_enabled_switch_is_read_and_gates_availability(): void
    {
        $this->configure();
        $this->assertTrue(LitsrmmConfig::isAvailable(), 'configured and on by default');

        Setting::setValue('litsrmm_enabled', '0');

        // This is the assertion the Tactical switch cannot make: isEnabled()
        // there returns isConfigured(), so turning it off changes nothing.
        $this->assertFalse(LitsrmmConfig::isEnabled(), 'the switch must be READ, not aliased to isConfigured()');
        $this->assertFalse(LitsrmmConfig::isAvailable(), 'off means unavailable even while configured');
        $this->assertTrue(LitsrmmConfig::isConfigured(),
            'and the credentials stay readable, so Settings can still show them and switch it back on');
    }

    public function test_a_disabled_integration_makes_no_outbound_request(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');

        // A 200 is queued deliberately: if the guard leaks, the call SUCCEEDS
        // and the history records it, so this control fails loudly rather than
        // passing because the fixture happened to error.
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => [['id' => 'x', 'name' => 'X']]])),
        ]);

        $this->assertSame([], $client->getClients(), 'a disabled vendor returns nothing');
        $this->assertFalse($client->isHealthy(), 'and reports unhealthy without asking');
        $this->assertCount(0, $this->history,
            'a disabled integration must not reach the network at all');
    }

    // ---- what leaves the process ----

    public function test_the_request_carries_bearer_auth_to_the_configured_host(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $client->getClients();

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        // The proposal specifies Bearer. LevelClient sends the key raw, so
        // mirroring Level here would contradict the spec's one auth statement.
        $this->assertSame('Bearer test-token-value', $request->getHeaderLine('Authorization'));
        $this->assertSame('litsrmm.test', $request->getUri()->getHost(),
            'requests go to the configured host and nowhere else');
        $this->assertStringEndsWith('/v1/clients', $request->getUri()->getPath());
    }

    public function test_a_missing_base_url_refuses_instead_of_guessing_a_host(): void
    {
        $client = new LitsrmmClient(['api_key' => 'test-token-value']);

        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('base URL not configured');

        $client->get('v1/health');
    }

    public function test_a_missing_api_key_refuses(): void
    {
        $client = new LitsrmmClient(['base_url' => 'http://litsrmm.test']);

        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('API key not configured');

        $client->get('v1/health');
    }

    // ---- reading only what the contract documents ----

    public function test_only_id_and_name_are_read_from_a_client_row(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'c1', 'name' => 'Acme', 'undocumented_field' => 'ignore me'],
            ]])),
        ]);

        $clients = $client->getClients();

        $this->assertSame([['id' => 'c1', 'name' => 'Acme']], $clients,
            'only the two documented keys are carried forward; an undocumented field must not leak into our shape');
    }

    public function test_a_bare_array_envelope_is_accepted(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode([['id' => 'c1', 'name' => 'Acme']])),
        ]);

        // The envelope is NOT documented, so both conventions are handled
        // rather than one being asserted as the contract.
        $this->assertSame([['id' => 'c1', 'name' => 'Acme']], $client->getClients());
    }

    public function test_an_unrecognised_envelope_is_reported_not_read_as_no_clients(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Log::spy();

        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['clients' => [['id' => 'c1', 'name' => 'Acme']]])),
        ]);

        // A wrapper we do not recognise must not be mapped over as though its
        // values were rows: that yields [] silently, which reads as "no clients".
        $this->assertSame([], $client->getClients());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'did not return a list'))
            ->once();
    }

    public function test_a_list_with_no_mappable_row_is_reported(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Log::spy();

        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => [['client_id' => 'c1', 'name' => 'Acme']]])),
        ]);

        $this->assertSame([], $client->getClients());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'none were mappable'))
            ->once();
    }

    public function test_a_row_without_an_id_is_dropped(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => [
                ['name' => 'No id here'],
                ['id' => 'c2', 'name' => 'Real'],
            ]])),
        ]);

        $this->assertSame([['id' => 'c2', 'name' => 'Real']], $client->getClients(),
            'an unmappable row must be dropped, not carried with an empty id a technician could select');
    }

    public function test_an_undocumented_pagination_signal_is_reported_not_swallowed(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Log::spy();

        $client = $this->clientWithResponses([
            new Response(200, [], json_encode([
                'data' => [['id' => 'c1', 'name' => 'Acme']],
                'has_more' => true,
            ])),
        ]);

        $this->assertCount(1, $client->getClients(), 'the page we got is still returned');

        // A silently truncated entity list is a mapping screen that omits
        // clients without admitting it.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'signalled more results'))
            ->once();
    }

    public function test_health_is_false_when_the_api_errors_rather_than_throwing(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(500, [], 'upstream exploded'),
        ]);

        $this->assertFalse($client->isHealthy(),
            'Test Connection must report a failure, not raise a 500 on the settings screen');
    }

    public function test_health_hits_the_documented_endpoint(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([new Response(200, [], json_encode(['ok' => true]))]);

        $this->assertTrue($client->isHealthy());
        $this->assertStringEndsWith('/v1/health', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_a_non_json_body_refuses_rather_than_returning_an_empty_list(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([new Response(200, [], '<html>not json</html>')]);

        // An empty list and a broken endpoint must not look the same: the first
        // reads as "this client has no entities to map".
        $this->expectException(LitsrmmClientException::class);
        $this->expectExceptionMessage('non-JSON body');

        $client->get('v1/clients');
    }

    public function test_a_failed_request_does_not_log_the_credential(): void
    {
        $this->configure();
        \Illuminate\Support\Facades\Log::spy();

        $client = $this->clientWithResponses([new Response(403, [], 'denied')]);

        try {
            $client->get('v1/clients');
        } catch (LitsrmmClientException) {
            // expected
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $blob = $message.' '.json_encode($context);

                return ! str_contains($blob, 'test-token-value');
            })
            ->once();
    }
}
