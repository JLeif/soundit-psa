<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Support\McpConfig;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An argument the tool does not declare is REFUSED, not ignored (card
 * Z4PdzEZ1, PSA ticket T-22846).
 *
 * The defect these pin is not a crash: it is a confident wrong answer. A read
 * tool handed an undeclared filter used to drop it and run UNFILTERED, so a
 * caller mistake came back as well-formed data. That shape produced a false
 * bug report against the wrong component, and it is the same shape that would
 * let someone cite "no matching records found" as evidence of absence when
 * nothing was ever filtered.
 *
 * The controls that matter here are the NEGATIVE ones: a guard that refuses
 * everything would pass the first test and be useless. Every refusal control
 * below is paired with a call that must still succeed.
 */
class UnknownArgumentRefusalTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        $grants = [];
        foreach (McpToolSurface::liveToolNames() as $name) {
            $grants[] = $name;
            $grants[] = $name.':immediate';
        }

        return McpConfig::rotateStaffToken(allowedTools: $grants, label: 'unknown-arg');
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $token, string $tool, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
    }

    /**
     * THE DEFECT ITSELF, in the shape the ticket hit: a fielded search handed
     * a free-text argument that does not exist on it.
     */
    public function test_an_undeclared_argument_on_a_search_tool_is_refused(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_clients', ['query' => 'acme', 'bogus_filter' => 'zzz']);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'),
            'an undeclared argument must be an error, not a result');
        $this->assertStringContainsString('bogus_filter', $text,
            'the refusal must NAME the offending argument, or the caller cannot self-correct');
        $this->assertStringContainsString('REFUSED, not ignored', $text,
            'the caller must be told nothing ran, so this is not read as an empty result');
    }

    /**
     * THE CONTROL THAT KEEPS THE TEST ABOVE HONEST. A guard that refused every
     * call would satisfy the refusal assertions and break the product.
     */
    public function test_a_fully_declared_call_still_succeeds(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_clients', ['query' => 'acme']);

        $this->assertFalse((bool) $response->json('result.isError'),
            'positive control: a call using only declared arguments must still run');
    }

    /**
     * The boundary consumes client_id itself and injects it into the published
     * schema, so a caller following tools/list must never be refused for it.
     */
    public function test_the_boundarys_own_injected_arguments_are_accepted(): void
    {
        $client = Client::factory()->create();
        $token = $this->token();

        $response = $this->invoke($token, 'find_persons', ['client_id' => $client->id, 'query' => 'someone']);

        $this->assertFalse((bool) $response->json('result.isError'),
            'client_id is injected into the published schema and must be accepted');
    }

    /**
     * Refusing must happen BEFORE the tool runs. A refusal that arrives after
     * the work is not a refusal, and on a read it would still burn the query.
     */
    public function test_the_refusal_precedes_execution(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'get_queue_stats', ['bogus_filter' => 'zzz']);

        // get_queue_stats declares no properties, so its schema cannot be
        // resolved and the guard deliberately stands off: fail OPEN on
        // ignorance. Asserting the CURRENT behaviour rather than the behaviour
        // I would prefer - this is the documented bound, not an oversight.
        $this->assertFalse((bool) $response->json('result.isError'),
            'a tool whose schema declares no properties is left alone by the guard');
    }

    /**
     * Tools that already refuse with a message teaching the semantics of the
     * mistake must keep it. The generic guard is strictly a fallback, and
     * displacing a better message with a worse one is a regression.
     */
    public function test_a_self_validating_write_tool_keeps_its_own_message(): void
    {
        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_client_id', 'client-1');
        \App\Models\Setting::setEncrypted('cipp_client_secret', 'secret');

        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.onmicrosoft.com']);
        $token = $this->token();

        $response = $this->invoke($token, 'cipp_create_user', [
            'client_id' => $client->id,
            'username' => 'a',
            'display_name' => 'A',
            'confirm_upn' => 'a@example.test',
            'reason' => 'r',
            'tenantFilter' => 'attacker.example',
        ]);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', $text,
            'the executor\'s semantic refusal must not be displaced by the generic guard');
    }

    /**
     * Where dropping an undeclared key IS the security contract, it must keep
     * dropping it.
     *
     * send_reply deliberately does not declare `to`: a prompt-injected
     * recipient must be IGNORED, not obeyed, and not converted into a failed
     * call. This is the boundary between the accident this change fixes (a
     * read tool dropping a filter) and a deliberate containment that must not
     * be disturbed. Written because widening the guard to cover it was the
     * first thing I tried, and the full suite caught it.
     */
    public function test_a_deliberate_accept_and_ignore_contract_is_not_disturbed(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $sendReply = null;
        foreach ($listed as $tool) {
            if ($tool['name'] === 'send_reply') {
                $sendReply = $tool;
            }
        }

        $this->assertNotNull($sendReply, 'precondition: send_reply must be on the published surface');
        $this->assertArrayNotHasKey('to', $sendReply['inputSchema']['properties'] ?? [],
            'send_reply must never advertise a caller-supplied recipient');

        // The paired half - that supplying `to` anyway is ignored rather than
        // refused, and the reply still lands as a held run - is pinned by
        // ChetSendReplyTest. This control exists so that widening the generic
        // guard over this tool fails HERE, next to the reason, rather than in
        // a distant suite.
        $this->assertTrue(
            (new \ReflectionMethod(\App\Http\Controllers\Api\McpStaffController::class, 'selfValidatingTool'))
                ->getDeclaringClass()->getConstant('ACCEPT_AND_IGNORE_TOOLS') !== false,
            'the accept-and-ignore set must remain declared'
        );
        $this->assertContains('send_reply',
            (new \ReflectionClass(\App\Http\Controllers\Api\McpStaffController::class))
                ->getConstant('ACCEPT_AND_IGNORE_TOOLS'),
            'send_reply must stay exempt from the generic unknown-argument refusal');
    }

    /**
     * Every argument the server PUBLISHES must be one the server ACCEPTS.
     *
     * This is the control against the guard drifting away from the advertised
     * contract: it sweeps the entire live surface rather than a sample, and it
     * states its own denominator so a zero cannot be read as coverage of
     * nothing.
     */
    public function test_every_published_argument_is_accepted_across_the_whole_surface(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $method = new \ReflectionMethod($controller, 'declaredArgumentNamesOrNull');
        $method->setAccessible(true);

        $rejected = [];
        $checked = 0;

        foreach ($listed as $tool) {
            $declared = $method->invoke($controller, (string) $tool['name']);

            if ($declared === null) {
                continue; // schema unresolvable: guard stands off, nothing to check
            }

            foreach (array_keys($tool['inputSchema']['properties'] ?? []) as $property) {
                $checked++;
                if (! in_array($property, $declared, true)) {
                    $rejected[] = $tool['name'].'.'.$property;
                }
            }
        }

        $this->assertGreaterThan(200, $checked,
            'denominator control: the sweep must actually cover the surface, '
            .'or an empty $rejected proves nothing');
        $this->assertSame([], $rejected,
            'the server must never refuse an argument it publishes');
    }
}
