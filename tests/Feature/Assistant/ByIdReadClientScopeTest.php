<?php

namespace Tests\Feature\Assistant;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Models\Client;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Services\Assistant\AssistantToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #4254 (card r9ORMtLW) — get_email_item and get_phone_call honour the executor's client scope. */
class ByIdReadClientScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmail(?int $clientId): Email
    {
        return Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'byid@example.test',
            'subject' => 'By-id scope',
            'received_at' => now()->subMinute(),
            'client_id' => $clientId,
            'body_text' => 'BYID-EMAIL-BODY',
        ]);
    }

    private function makeCall(?int $clientId): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => 'byid-'.uniqid(),
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550301',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinute(),
        ]);
        $call->client_id = $clientId;
        $call->transcription = 'BYID-CALL-TRANSCRIPT';
        $call->save();

        return $call;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function tools(): array
    {
        return [
            'get_email_item' => ['get_email_item', 'email_id', 'makeEmail', 'email_item'],
            'get_phone_call' => ['get_phone_call', 'phone_call_id', 'makeCall', 'phone_call'],
        ];
    }

    /** @dataProvider tools */
    public function test_cross_client_read_is_identical_to_an_unknown_id(string $tool, string $key, string $make, string $field): void
    {
        $owner = Client::factory()->create();
        $other = Client::factory()->create();
        $record = $this->{$make}($owner->id);

        $scoped = new AssistantToolExecutor(null, $other->id);
        $cross = $scoped->execute($tool, [$key => $record->id]);
        $unknown = $scoped->execute($tool, [$key => $record->id + 100000]);

        $this->assertSame($unknown, $cross);
        $this->assertArrayNotHasKey($field, $cross);
        $this->assertSame(json_encode($unknown), json_encode($cross));
    }

    /** @dataProvider tools */
    public function test_scoped_read_does_not_reach_a_row_with_no_client(string $tool, string $key, string $make, string $field): void
    {
        $other = Client::factory()->create();
        $record = $this->{$make}(null);

        $scoped = new AssistantToolExecutor(null, $other->id);
        $this->assertSame(
            $scoped->execute($tool, [$key => $record->id + 100000]),
            $scoped->execute($tool, [$key => $record->id]),
        );
    }

    /** @dataProvider tools */
    public function test_same_client_read_still_reaches_the_record(string $tool, string $key, string $make, string $field): void
    {
        $owner = Client::factory()->create();
        $record = $this->{$make}($owner->id);

        $out = (new AssistantToolExecutor(null, $owner->id))->execute($tool, [$key => $record->id]);

        $this->assertSame($record->id, $out[$field]['id'] ?? null);
        $this->assertSame($owner->id, $out[$field]['client_id']);
    }

    /** @dataProvider tools */
    public function test_unscoped_executor_keeps_its_cross_client_reach(string $tool, string $key, string $make, string $field): void
    {
        $owner = Client::factory()->create();
        $record = $this->{$make}($owner->id);
        $unowned = $this->{$make}(null);

        $unscoped = new AssistantToolExecutor(null, null);

        $this->assertSame($record->id, $unscoped->execute($tool, [$key => $record->id])[$field]['id'] ?? null);
        $this->assertSame($unowned->id, $unscoped->execute($tool, [$key => $unowned->id])[$field]['id'] ?? null);
    }

    /** @dataProvider tools */
    public function test_mcp_staff_client_id_scopes_the_by_id_read(string $tool, string $key, string $make, string $field): void
    {
        $owner = Client::factory()->create();
        $other = Client::factory()->create();
        $record = $this->{$make}($owner->id);
        $token = McpConfig::rotateStaffToken(allowedTools: [$tool], label: 'chet');

        $mcp = fn (array $arguments) => $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ])->json('result');

        $cross = $mcp([$key => $record->id, 'client_id' => $other->id]);
        $unknown = $mcp([$key => $record->id + 100000, 'client_id' => $other->id]);
        $this->assertSame($unknown, $cross);
        $this->assertStringNotContainsString('BYID-', json_encode($cross));

        $own = $mcp([$key => $record->id, 'client_id' => $owner->id]);
        $this->assertStringContainsString('BYID-', json_encode($own));
        $this->assertStringContainsString('BYID-', json_encode($mcp([$key => $record->id])));
    }

    /** @dataProvider tools */
    public function test_mcp_staff_malformed_client_id_is_refused_not_dropped(string $tool, string $key, string $make, string $field): void
    {
        $owner = Client::factory()->create();
        $record = $this->{$make}($owner->id);
        $token = McpConfig::rotateStaffToken(allowedTools: [$tool], label: 'chet');

        $mcp = fn (array $arguments) => $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ])->json('result');

        // Positive control: the record is reachable on this surface, so a refusal
        // below is the malformed scope being refused, not the row being absent.
        $this->assertStringContainsString('BYID-', json_encode($mcp([$key => $record->id])));

        foreach (['07', 7.0, 0, -3, ''] as $malformed) {
            $result = $mcp([$key => $record->id, 'client_id' => $malformed]);
            $text = (string) ($result['content'][0]['text'] ?? '');

            $this->assertTrue((bool) ($result['isError'] ?? false), $tool.' '.var_export($malformed, true).': '.$text);
            $this->assertStringContainsString('is not a positive integer', $text);
            $this->assertStringNotContainsString('BYID-', json_encode($result));
        }
    }
}
