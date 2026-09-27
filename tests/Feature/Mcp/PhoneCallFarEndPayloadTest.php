<?php

namespace Tests\Feature\Mcp;

use App\Services\PhoneCallService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card mfmgS3XO: list_phone_calls / get_phone_call carry far_end_number and
 * far_end_provenance across the MCP boundary, on BOTH directions, over rows
 * written by the production writers (outbound: from_number = dialled far end,
 * to_number = our DID). The raw from_number/to_number pair is unchanged.
 * Synthetic numbers only.
 */
class PhoneCallFarEndPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const DID = '+15550100000';

    private const FAR_IN = '+15550103333';

    private const FAR_OUT = '+15550104444';

    /** @return array<string, mixed> */
    private function mcpCall(string $tool, array $arguments): array
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['list_phone_calls', 'get_phone_call'], label: 'chet');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @return array{0: int, 1: int} [inbound id, outbound id] */
    private function seedBothDirections(): array
    {
        Queue::fake();
        config(['services.plivo.did_number' => self::DID]);
        $svc = app(PhoneCallService::class);

        $in = $svc->logIncomingCall(['CallUUID' => 'fe-mcp-in', 'From' => self::FAR_IN, 'To' => self::DID]);
        $out = $svc->logOutboundCall(['CallUUID' => 'fe-mcp-out', 'From' => 'sip:synthetic-tech@phone.plivo.com', 'To' => self::FAR_OUT]);

        // Production layout, pinned so the assertions below cannot go vacuous.
        $this->assertSame([self::FAR_OUT, self::DID], [$out->from_number, $out->to_number]);
        $this->assertSame([self::FAR_IN, self::DID], [$in->from_number, $in->to_number]);

        return [$in->id, $out->id];
    }

    public function test_list_phone_calls_carries_far_end_on_both_directions(): void
    {
        [$in, $out] = $this->seedBothDirections();

        $rows = collect($this->mcpCall('list_phone_calls', [])['phone_calls'])->keyBy('id');

        $this->assertSame(self::FAR_OUT, $rows[$out]['far_end_number']);
        $this->assertSame('dialled', $rows[$out]['far_end_provenance']);
        $this->assertSame(self::FAR_IN, $rows[$in]['far_end_number']);
        $this->assertSame('caller_id', $rows[$in]['far_end_provenance']);

        // Additive: the raw columns are still passed through unchanged.
        $this->assertSame([self::FAR_OUT, self::DID], [$rows[$out]['from_number'], $rows[$out]['to_number']]);
    }

    public function test_get_phone_call_carries_far_end_on_both_directions(): void
    {
        [$in, $out] = $this->seedBothDirections();

        $o = $this->mcpCall('get_phone_call', ['phone_call_id' => $out])['phone_call'];
        $this->assertSame([self::FAR_OUT, 'dialled'], [$o['far_end_number'], $o['far_end_provenance']]);

        $i = $this->mcpCall('get_phone_call', ['phone_call_id' => $in])['phone_call'];
        $this->assertSame([self::FAR_IN, 'caller_id'], [$i['far_end_number'], $i['far_end_provenance']]);
    }

    /**
     * The tool description is the contract an agent reads. Pin only the facts
     * that would have prevented the misreading on this card: that from/to are not
     * literal, that outbound to_number is our own number, and the two provenance
     * values.
     */
    public function test_list_phone_calls_description_states_the_convention(): void
    {
        $d = McpToolRegistry::listPhoneCallsTool()['description'];

        $this->assertStringContainsString('NOT literal from/to', $d);
        $this->assertStringContainsString('on outbound to_number is our own main number', $d);
        $this->assertStringContainsString('caller_id', $d);
        $this->assertStringContainsString('dialled', $d);
    }
}
