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
            'base_url' => 'https://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]);
    }

    private function configure(): void
    {
        Setting::setEncrypted('litsrmm_api_key', 'test-token-value');
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
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

        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
        $this->assertTrue(LitsrmmConfig::isConfigured());
    }

    public function test_the_enabled_switch_is_read_and_gates_availability(): void
    {
        $this->configure();
        $this->assertTrue(LitsrmmConfig::isAvailable(), 'configured and on by default');

        Setting::setValue('litsrmm_enabled', '0');

        // The assertion that matters: isEnabled() must READ the setting. A
        // vendor whose isEnabled() returns isConfigured() has a switch that
        // cannot turn anything off, and no test of the credentials can see it.
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

    /**
     * #3298 diff:2. The sibling control above proves OFF=OFF through the two
     * GATED methods. This one asks the question that finding actually raises:
     * the public get() reaches request() directly, so a future caller (stage 2
     * device sync is the obvious one) can send a credentialed request while an
     * operator has the vendor switched off.
     *
     * It is deliberately a SEPARATE test rather than an extra assertion above:
     * merged in, a reader cannot tell which path the guarantee rests on, and
     * that ambiguity is what let the gap survive stage 1's review.
     */
    public function test_a_disabled_integration_makes_no_outbound_request_through_the_public_get(): void
    {
        $this->configure();
        Setting::setValue('litsrmm_enabled', '0');

        // A 200 again, for the same reason: a leak must show up as a RECORDED
        // request, never as an exception the assertion could mistake for a
        // refusal.
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        try {
            $client->get('v1/devices');
        } catch (LitsrmmClientException) {
            // Refusing is the correct behaviour; what is forbidden is reaching
            // the network, which the history below is what actually decides.
        }

        $this->assertCount(0, $this->history,
            'the public get() must be gated too: OFF=OFF has to hold at the request layer, '
            .'not only in the two methods that happen to check it today');
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

    /**
     * #3337 diff:2. assertTransportIsSafe() is handed the CONFIGURED base_url,
     * but Guzzle sends the URI it resolves from base_uri + endpoint under RFC
     * 3986. An absolute endpoint replaces the whole authority, and a
     * protocol-relative one replaces the host while inheriting the scheme. So
     * the string the guard inspects and the URL the credential actually
     * crosses are two different things.
     *
     * Measured against the unfixed code, all three of these left the process
     * with the Bearer header attached, including to evil.example.
     */
    public static function escapingEndpoints(): array
    {
        return [
            'absolute http downgrades the scheme' => [
                'https://litsrmm.test', 'http://litsrmm.test/v1/clients',
            ],
            'absolute http to another host' => [
                'https://litsrmm.test', 'http://evil.example/v1/x',
            ],
            'protocol-relative replaces the host' => [
                'https://litsrmm.test', '//evil.example/v1/x',
            ],
            // The loopback exemption is what makes this one sharp: the base is
            // legitimately plain http, so the guard passes it, and the
            // endpoint then inherits http onto a REMOTE host.
            'protocol-relative off a loopback base inherits plain http' => [
                'http://127.0.0.1:8080', '//evil.example/v1/x',
            ],
            // These two exist because a mutant survived without them. Writing
            // the host check as str_contains($host, $baseHost) instead of an
            // equality passed every case above, and an attacker-controlled
            // subdomain or a longer registrable name is exactly how that
            // weaker test gets exploited. hostIsLoopback() already carries the
            // same lesson in its own docblock; this is that lesson applied one
            // layer up, as a control rather than as a comment.
            'a host that merely ENDS WITH the configured one is a different machine' => [
                'https://litsrmm.test', '//litsrmm.test.evil.example/v1/x',
            ],
            'a host that merely CONTAINS the configured one is a different machine' => [
                'https://litsrmm.test', '//evil-litsrmm.test.example/v1/x',
            ],
            // And these two for the SUFFIX form of the same wrong fix, which
            // survived the two cases above: str_ends_with is false for both of
            // them, so they proved nothing about it. 'xlitsrmm.test' is a
            // different registrable domain that ends with the configured name,
            // and a subdomain is a different machine we were never pointed at.
            'a different registrable domain ENDING WITH the configured name' => [
                'https://litsrmm.test', '//xlitsrmm.test/v1/x',
            ],
            'a subdomain of the configured host is still not the configured host' => [
                'https://litsrmm.test', '//evil.litsrmm.test/v1/x',
            ],
            // THE ORIGIN INCLUDES THE PORT (#3500). A different port on the
            // same machine is a different service, and Guzzle's own
            // UriComparator::isCrossOrigin compares host, scheme AND port. A
            // host-equality-only guard hands the Bearer to whatever answers on
            // 8443.
            'a different port on the configured host is a different service' => [
                'https://litsrmm.test', '//litsrmm.test:8443/v1/x',
            ],
            // THE ORIGIN INCLUDES THE SCHEME (#3500 diff:1). getPort() is null
            // for each scheme's OWN default, so a host:port key read these as
            // one origin; on a loopback host the plain-http transport rule then
            // admits the request and the Bearer lands on port 80 (or 443), a
            // different service. Only a loopback host reaches that path, which
            // is why the non-loopback downgrade row above could not catch it.
            'https loopback base to plain http on the same host is port 80' => [
                'https://127.0.0.1', 'http://127.0.0.1/v1/x',
            ],
            'plain http loopback base to https on the same host is port 443' => [
                'http://127.0.0.1', 'https://127.0.0.1/v1/x',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapingEndpoints')]
    public function test_an_endpoint_that_escapes_the_configured_host_is_refused_before_the_credential_exists(
        string $baseUrl,
        string $endpoint,
    ): void {
        $this->configure();

        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $client = new LitsrmmClient([
            'api_key' => 'test-token-value',
            'base_url' => $baseUrl,
            'handler' => $stack,
        ]);

        $threw = false;
        try {
            $client->get($endpoint);
        } catch (LitsrmmClientException) {
            $threw = true;
        }

        // The history is the load-bearing assertion. A thrown exception that
        // still let the request out would be a leak wearing a refusal's face.
        $this->assertCount(0, $this->history,
            "endpoint {$endpoint} escaped the guard and carried the API key off the configured host");
        $this->assertTrue($threw, 'and the caller must be told, not silently handed an empty result');
    }

    /**
     * The negative half of the pair. A guard that refuses everything would
     * pass every case above, so this pins that ordinary relative endpoints —
     * with and without a leading slash, with a query — still go out.
     */
    public function test_ordinary_relative_endpoints_still_reach_the_configured_host(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => []])),
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $client->get('v1/clients', ['limit' => 10]);
        $client->get('/v1/health');

        $this->assertCount(2, $this->history,
            'the fix must not turn into a refusal of the normal path');
        foreach ($this->history as $entry) {
            $this->assertSame('litsrmm.test', $entry['request']->getUri()->getHost());
            $this->assertSame('https', $entry['request']->getUri()->getScheme());
        }
    }

    /**
     * The other half of the port rule, and the reason the guard normalises on
     * getPort() rather than on the string. A base URL naming its own default
     * port is the SAME origin, so a rule written as strict string equality
     * would refuse the configured host for no reason.
     */
    public function test_an_explicit_default_port_is_the_same_origin(): void
    {
        $this->configure();

        $mock = new MockHandler([new Response(200, [], json_encode(['data' => []]))]);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $client = new LitsrmmClient([
            'api_key' => 'test-token-value',
            'base_url' => 'https://litsrmm.test:443',
            'handler' => $stack,
        ]);

        $client->get('v1/clients');

        $this->assertCount(1, $this->history,
            'https://host:443 and https://host are one origin: the guard must not refuse it');
    }

    /**
     * The client must not FOLLOW a redirect (#3500). Guzzle strips the
     * Authorization header on a cross-origin hop, so the credential is safe
     * either way — but following one still sends the request body to the other
     * host, and every other vendor client here refuses to.
     *
     * The control asserts on what LEFT the process: exactly ONE request, to
     * the configured host. A client that followed would record two, the second
     * to evil.example, and the caller would get the 200 instead of an error.
     */
    public function test_a_redirect_is_not_followed(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(302, ['Location' => 'https://evil.example/v1/clients']),
            new Response(200, [], json_encode(['data' => [['id' => 'x', 'name' => 'X']]])),
        ]);

        $threw = false;
        try {
            $client->get('v1/clients');
        } catch (LitsrmmClientException $e) {
            $threw = true;
            $this->assertStringContainsString('redirected', $e->getMessage());
        }

        $this->assertCount(1, $this->history,
            'the client must not follow a redirect: the second hop would carry the request body off-host');
        $this->assertSame('litsrmm.test', $this->history[0]['request']->getUri()->getHost());
        $this->assertTrue($threw,
            'an unfollowed 3xx must surface as an error, not decode its empty body into a successful []');
    }

    /**
     * #3500 diff:4. With allow_redirects false and http_errors only throwing
     * from 400, a 3xx with an EMPTY body decoded to []: isHealthy() reported
     * healthy and getClients() reported a vendor with no clients. The Location
     * here is same-origin on purpose: this is about the degraded read, not
     * about an escape.
     */
    public function test_a_redirect_is_not_read_as_a_healthy_vendor_with_no_clients(): void
    {
        $this->configure();
        $client = $this->clientWithResponses([
            new Response(301, ['Location' => 'https://litsrmm.test/v2/health']),
            new Response(301, ['Location' => 'https://litsrmm.test/v2/clients']),
        ]);

        $this->assertFalse($client->isHealthy(), 'a redirect is not a healthy answer');

        $this->expectException(LitsrmmClientException::class);
        $client->getClients();
    }

    /**
     * #3500 context:3, unanimous must-fix. The two credential checks in
     * request() read $this->config — the values CAPTURED when the object was
     * built. isConfigured() re-reads LIVE settings. The client is a container
     * singleton (AppServiceProvider), so a long-lived worker, or any client
     * built with explicit config, can pass the captured pair and fail the live
     * one WHILE THE SWITCH IS ON. A single isAvailable() check then reports
     * "switched off" on a path where nothing is switched off.
     *
     * This fixture is exactly that divergence: captured credentials present,
     * live settings empty, switch untouched. MEASURED before the split, the
     * message was 'LITSRMM integration is switched off'.
     */
    public function test_stale_credentials_are_not_reported_as_a_switched_off_integration(): void
    {
        // Deliberately NOT configure(): live settings stay empty while the
        // client below holds credentials of its own.
        $this->assertFalse(LitsrmmConfig::isConfigured(), 'precondition: live credentials are absent');
        $this->assertTrue(LitsrmmConfig::isEnabled(), 'precondition: the operator switch is ON');

        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        try {
            $client->get('v1/clients');
            $this->fail('a client whose live credentials are gone must refuse');
        } catch (LitsrmmClientException $e) {
            $this->assertStringNotContainsString('switched off', $e->getMessage(),
                'the switch is ON: naming it is a misdiagnosis an operator cannot act on');
            $this->assertStringContainsString('not currently configured', $e->getMessage(),
                'and the refusal must name the mechanism that is actually true');
        }

        $this->assertCount(0, $this->history, 'and it refuses before the network either way');
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
        $client = new LitsrmmClient(['base_url' => 'https://litsrmm.test']);

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
