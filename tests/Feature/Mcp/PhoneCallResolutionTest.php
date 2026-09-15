<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\PhoneCallResolutionProposal;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PhoneCallResolutionService;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhoneCallResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = Ticket::factory()->create();
        $contact = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Synthetic', 'last_name' => 'Caller', 'is_active' => true]);
        $call = PhoneCall::create(['call_uuid' => 'synthetic-resolution', 'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100', 'status' => CallStatus::Completed, 'started_at' => now(),
            'ticket_id' => $ticket->id, 'is_billable' => false, 'transcription' => 'SYNTHETIC BODY MUST NOT ENTER AUDIT']);

        return [$call, $contact, $ticket, ['phone_call_id' => $call->id, 'client_id' => $ticket->client_id,
            'contact_id' => $contact->id, 'reason' => 'Synthetic explicit caller selection']];
    }

    private function callTool(string $token, array $args, string $name = 'resolve_phone_call'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]);
    }

    private function decoded(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertNull($response->json('error'), json_encode($response->json()));
        $this->assertFalse((bool) $response->json('result.isError'), json_encode($response->json()));

        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_immediate_already_linked_resolution_is_audited_repeat_safe_and_readable(): void
    {
        [$call, $contact, $ticket, $args] = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:immediate', 'get_phone_call']);
        $before = $call->fresh()->getAttributes();
        $first = $this->decoded($this->callTool($token, $args));
        $this->assertFalse($first['unchanged']);
        $this->assertSame($ticket->client_id, $first['client_id']);
        $this->assertSame($contact->id, $first['person_id']);
        $this->assertSame($ticket->id, $first['ticket_id']);
        $after = $call->fresh()->getAttributes();
        foreach ($before as $key => $value) {
            if (! in_array($key, ['client_id', 'person_id', 'person_confirmed', 'updated_at'])) {
                $this->assertSame($value, $after[$key], $key);
            }
        }
        $repeat = $this->decoded($this->callTool($token, $args));
        $this->assertTrue($repeat['unchanged']);
        $this->assertSame($after, $call->fresh()->getAttributes());
        $read = $this->decoded($this->callTool($token, ['phone_call_id' => $call->id], 'get_phone_call'))['phone_call'];
        $this->assertSame($contact->id, $read['person_id']);
        $this->assertSame($ticket->client_id, $read['client_id']);
        $this->assertSame($ticket->id, $read['ticket_id']);
        $this->assertTrue($call->fresh()->person_confirmed);
        $logs = TechnicianActionLog::where('action_type', 'resolve_phone_call')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['executed', 'no_op'], $logs->pluck('result_status')->all());
        $this->assertStringContainsString($args['reason'], $logs->first()->summary);
        $this->assertStringNotContainsString('SYNTHETIC BODY', $logs->toJson());
        $this->assertStringNotContainsString('SYNTHETIC BODY', McpAuditLog::where('tool_name', 'resolve_phone_call')->get()->toJson());
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('prepay_transactions', 0);
        $this->assertDatabaseCount('phone_call_resolution_proposals', 0);
    }

    public function test_bare_and_staged_grants_hold_and_approval_reuses_guards(): void
    {
        [$call, $contact, $ticket, $args] = $this->fixture();
        $this->assertSame(['resolve_phone_call', 'staged'], McpToolModes::parseGrantEntry('resolve_phone_call'));
        $this->assertSame(['resolve_phone_call:staged'], McpToolModes::normalizeGrantEntries(['resolve_phone_call'])['entries']);
        $this->assertSame('staged', McpToolModes::defaultMode('resolve_phone_call'));
        $this->assertSame(['resolve_phone_call', 'immediate'], McpToolModes::parseGrantEntry('resolve_phone_call:immediate'));
        $this->assertSame(['resolve_phone_call', 'staged'], McpToolModes::parseGrantEntry('resolve_phone_call:staged'));
        $this->assertSame(['resolve_phone_call', 'staged'], McpToolModes::parseGrantEntry('stage_resolve_phone_call'));
        $this->assertSame(['merge_ticket', 'immediate'], McpToolModes::parseGrantEntry('merge_ticket'));
        foreach (['resolve_phone_call', 'resolve_phone_call:staged', 'stage_resolve_phone_call'] as $grant) {
            $token = McpConfig::rotateStaffToken(allowedTools: [$grant], label: 'same-synthetic-actor');
            $r = $this->decoded($this->callTool($token, $args + ['staged' => false]));
            $this->assertTrue($r['staged']);
            $this->assertNull($call->fresh()->client_id);
            $this->assertNull($call->fresh()->person_id);
        }
        $this->assertDatabaseCount('phone_call_resolution_proposals', 1);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertOk()->assertJsonPath('person_id', $contact->id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'resolve_phone_call', 'approver_user_id' => $admin->id, 'result_status' => 'executed']);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
    }

    public function test_legacy_and_ungranted_tokens_cannot_discover_or_execute_resolver(): void
    {
        [$call, , , $args] = $this->fixture();
        foreach ([null, ['get_phone_call']] as $grants) {
            $token = McpConfig::rotateStaffToken(allowedTools: $grants);
            $list = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools');
            $this->assertNotContains('resolve_phone_call', array_column($list, 'name'));
            $r = $this->callTool($token, $args);
            $this->assertTrue($r->json('error') !== null || (bool) $r->json('result.isError'));
        }
        $this->assertNull($call->fresh()->client_id);
    }

    public function test_stale_proposals_revalidate_call_ticket_and_contact_at_approval(): void
    {
        [$call, $person, $ticket, $args] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:staged']);
        $r = $this->decoded($this->callTool($token, $args));
        $person->update(['is_active' => false]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertNull($call->fresh()->person_id);
        $person->update(['is_active' => true]);
        $r = $this->decoded($this->callTool($token, $args));
        $call->ticket_id = null;
        $call->save();
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertNull($call->fresh()->person_id);
        $call->ticket_id = $ticket->id;
        $call->save();
        $r = $this->decoded($this->callTool($token, $args));
        $ticket->update(['client_id' => Client::factory()->create()->id]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertNull($call->fresh()->client_id);
        $this->assertSame(3, PhoneCallResolutionProposal::where('state', 'stale')->count());
    }

    public function test_other_identity_and_confirmed_unresolved_state_are_not_overwritten(): void
    {
        [$call, $person, $ticket, $args] = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:immediate']);
        $other = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Other', 'is_active' => true]);
        $call->person_id = $other->id;
        $call->save();
        $this->assertTrue((bool) $this->callTool($token, $args)->json('result.isError'));
        $this->assertSame($other->id, $call->fresh()->person_id);
        $call->person_id = null;
        $call->person_confirmed = true;
        $call->save();
        $this->assertTrue((bool) $this->callTool($token, $args)->json('result.isError'));
        $this->assertNull($call->fresh()->person_id);
    }

    public function test_audit_failure_rolls_back_identity_and_proposal_completion(): void
    {
        [$call, , , $args] = $this->fixture();
        $service = app(PhoneCallResolutionService::class);
        $payload = $args;
        unset($payload['client_id']);
        $proposal = $service->execute($payload, $args['client_id'], 'synthetic', true);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER refuse_resolution_audit BEFORE INSERT ON technician_action_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
        try {
            foreach ([false, true] as $approval) {
                try {
                    $approval ? $service->approve($proposal['proposal_id'], $admin) : $service->execute($payload, $args['client_id'], 'synthetic', false);
                    $this->fail('Audit refusal must abort mutation');
                } catch (\Illuminate\Database\QueryException $e) {
                    $this->assertStringContainsString('synthetic audit failure', $e->getMessage());
                }
                $this->assertNull($call->fresh()->client_id);
                $this->assertNull($call->fresh()->person_id);
                $this->assertDatabaseHas('phone_call_resolution_proposals', ['id' => $proposal['proposal_id'], 'state' => 'pending']);
            }
        } finally {
            \Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER refuse_resolution_audit');
        }
    }

    public function test_denial_authorization_kill_switch_and_escaped_cockpit(): void
    {
        [$call, , , $args] = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:immediate']);
        $args['reason'] = '<script>synthetic</script>';
        $r = $this->decoded($this->callTool($token, $args + ['staged' => true]));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $html = view('cockpit.partials.phone-call-resolutions', [
            'phoneCallResolutions' => PhoneCallResolutionProposal::all(), 'canApprovePhoneCallResolution' => true,
        ])->render();
        $this->assertStringNotContainsString('<script>synthetic</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->assertArrayHasKey('error', app(PhoneCallResolutionService::class)->approve($r['proposal_id'], $inactive));
        Setting::setValue('technician_kill_switch', true);
        $this->assertTrue((bool) $this->callTool($token, $args)->json('result.isError'));
        $this->assertArrayHasKey('error', app(PhoneCallResolutionService::class)->approve($r['proposal_id'], $admin));
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.deny', $r['proposal_id']))->assertOk();
        $this->assertNull($call->fresh()->client_id);
        $this->assertDatabaseHas('phone_call_resolution_proposals', ['id' => $r['proposal_id'], 'state' => 'denied']);
    }

    public function test_approval_refuses_changed_identity_contact_ownership_and_binding(): void
    {
        [$call, $person, $ticket, $args] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call']);
        $r = $this->decoded($this->callTool($token, $args));
        $other = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Other', 'is_active' => true]);
        $call->person_id = $other->id;
        $call->person_confirmed = true;
        $call->save();
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertSame($other->id, $call->fresh()->person_id);
        $call->person_id = null;
        $call->person_confirmed = false;
        $call->save();
        $r = $this->decoded($this->callTool($token, $args));
        $person->update(['client_id' => Client::factory()->create()->id]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertNull($call->fresh()->person_id);
        $person->update(['client_id' => $ticket->client_id]);
        $r = $this->decoded($this->callTool($token, $args));
        $proposal = PhoneCallResolutionProposal::findOrFail($r['proposal_id']);
        $payload = $proposal->payload;
        $payload['contact_id'] = $other->id;
        $proposal->update(['payload' => $payload]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertNull($call->fresh()->person_id);
        $this->assertDatabaseMissing('technician_action_logs', ['result_status' => 'executed']);
    }

    public function test_missing_trashed_inactive_targets_and_unlinked_call(): void
    {
        [$call, $person, $ticket, $args] = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:immediate']);
        $client = Client::findOrFail($ticket->client_id);
        foreach (['inactive_client', 'trashed_client', 'trashed_contact', 'missing_ticket'] as $case) {
            if ($case === 'inactive_client') {
                $client->update(['is_active' => false]);
            } elseif ($case === 'trashed_client') {
                $client->delete();
            } elseif ($case === 'trashed_contact') {
                $person->delete();
            } else {
                $ticket->delete();
            }
            $this->assertTrue((bool) $this->callTool($token, $args)->json('result.isError'), $case);
            $this->assertNull($call->fresh()->person_id);
            $client->restore();
            $client->update(['is_active' => true]);
            $person->restore();
        }
        $call->update(['ticket_id' => null]);
        $result = $this->decoded($this->callTool($token, $args));
        $this->assertSame($person->id, $result['person_id']);
        $this->assertNull($result['ticket_id']);
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('prepay_transactions', 0);
    }

    public function test_refuses_mismatched_and_invalid_targets_without_mutation(): void
    {
        [$call, $person, $ticket, $args] = $this->fixture();
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:immediate']);
        $foreign = Client::factory()->create();
        $badSets = [
            ['client_id' => $foreign->id], ['contact_id' => 999999], ['phone_call_id' => 999999],
            ['phone_call_id' => []], ['phone_call_id' => true], ['contact_id' => '1'],
            ['client_id' => (string) $ticket->client_id], ['reason' => []], ['reason' => ' '],
            ['reason' => str_repeat('x', 801)], ['staged' => 'false'], ['unexpected' => 'SYNTHETIC BODY'],
        ];
        foreach ($badSets as $bad) {
            $r = $this->callTool($token, array_replace($args, $bad));
            $this->assertTrue($r->json('error') !== null || (bool) $r->json('result.isError'), json_encode($bad));
            $this->assertNull($call->fresh()->person_id);
        }
        $person->update(['is_active' => false]);
        $r = $this->callTool($token, $args);
        $this->assertTrue((bool) $r->json('result.isError'));
        $person->update(['is_active' => true, 'client_id' => $foreign->id]);
        $r = $this->callTool($token, array_replace($args, ['client_id' => $foreign->id]));
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertDatabaseCount('technician_action_logs', 0);
        $this->assertNull($call->fresh()->client_id);
    }

    public function test_a_proposal_staged_under_the_old_snapshot_shape_still_approves(): void
    {
        [$call, $contact, $ticket, $args] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $token = McpConfig::rotateStaffToken(allowedTools: ['resolve_phone_call:staged']);
        $r = $this->decoded($this->callTool($token, $args));
        $proposal = PhoneCallResolutionProposal::findOrFail($r['proposal_id']);
        $payload = $proposal->payload;
        // Pre-deploy shape: the stored snapshot also carried updated_at.
        $payload['snapshot']['updated_at'] = (string) $call->fresh()->updated_at;
        $proposal->update(['payload' => $payload, 'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $proposal->id))
            ->assertOk()->assertJsonPath('person_id', $contact->id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);
        $this->assertDatabaseHas('phone_call_resolution_proposals', ['id' => $proposal->id, 'state' => 'done']);
    }
}
