<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\PhoneDirectoryListType;
use App\Enums\TranscriptionStatus;
use App\Models\Contract;
use App\Models\McpAuditLog;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PhoneDirectoryEntry;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The four agent-side call-log writes (card 6aac3226dfebbc36fd7ff4f9).
 *
 * The contract under test, in the order the card states it:
 *  1. all four are DEFAULT-UNGRANTED, legacy full-surface token included;
 *  2. set_call_billable / block_caller / allow_caller HOLD on a bare or
 *     `:staged` grant and execute only on an explicit `:immediate` grant;
 *  3. a held proposal REVALIDATES at approval and is consumed as stale rather
 *     than applied blind;
 *  4. every service-level refusal the web path makes is preserved;
 *  5. the audit row is written, and it never carries a transcript, a caller
 *     number, or the reason prose in the MCP audit log.
 */
class PhoneCallAgentActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MEASURED at this tip: `duration` and `recording_url` are NOT in
     * PhoneCall::$fillable, so PhoneCall::create() silently drops them (the same
     * mass-assignment class as answered_by on card 6aac2ffcf8e1ffa448e60539).
     * A fixture that passes them through create() produces a zero-duration,
     * recording-less call and would make the prepay and transcription
     * assertions below pass vacuously, so the non-fillable columns are set with
     * an explicit forceFill.
     *
     * @return array{0: PhoneCall, 1: Ticket}
     */
    private function fixture(array $callOverrides = [], array $nonFillable = ['duration' => 600]): array
    {
        Http::preventStrayRequests();
        Process::fake();
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = Ticket::factory()->create();
        $call = PhoneCall::create(array_merge([
            'call_uuid' => 'synthetic-call-action',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550142',
            'status' => CallStatus::Completed,
            'started_at' => now(),
            'ticket_id' => $ticket->id,
            'is_billable' => false,
            'transcription' => 'SYNTHETIC BODY MUST NOT ENTER AUDIT',
        ], $callOverrides));
        if ($nonFillable !== []) {
            $call->forceFill($nonFillable)->save();
        }
        $this->assertSame($nonFillable['duration'] ?? null, $call->fresh()->duration === null ? null : (int) $call->fresh()->duration);

        return [$call->fresh(), $ticket];
    }

    private function callTool(string $token, string $name, array $args): TestResponse
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

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function errorText(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue(
            $response->json('error') !== null || (bool) $response->json('result.isError'),
            'expected a refusal, got: '.json_encode($response->json()),
        );

        return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
    }

    private function listedNames(string $token): array
    {
        return array_column((array) $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools'), 'name');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /**
     * (1) DEFAULT-UNGRANTED. A legacy full-surface token (allowedTools === null)
     * and a token granted only the call READS can neither discover nor execute
     * any of the four. Red-check: drop the intake-manage grant requirement in
     * McpStaffController::toolAllowed() and this goes red on the legacy token.
     */
    public function test_all_four_tools_are_default_ungranted_including_legacy_tokens(): void
    {
        [$call] = $this->fixture();
        $tools = ['set_call_billable' => ['billable' => true], 'block_caller' => [], 'allow_caller' => [],
            'mark_call_followed_up' => [], 'retry_call_transcription' => []];

        foreach ([null, ['list_phone_calls', 'get_phone_call']] as $grants) {
            $token = McpConfig::rotateStaffToken(allowedTools: $grants);
            $listed = $this->listedNames($token);
            foreach (array_keys($tools) as $name) {
                $this->assertNotContains($name, $listed, $name.' must not be advertised to an ungranted token');
            }
            foreach ($tools as $name => $extra) {
                $text = $this->errorText($this->callTool($token, $name,
                    ['phone_call_id' => $call->id, 'reason' => 'synthetic ungranted probe'] + $extra));
                $this->assertStringContainsString('not allowed for this token', $text);
            }
        }

        $fresh = $call->fresh();
        $this->assertFalse((bool) $fresh->is_billable);
        $this->assertNull($fresh->followed_up_at);
        $this->assertDatabaseCount('phone_directory', 0);
        $this->assertDatabaseCount('phone_call_action_proposals', 0);
        $this->assertDatabaseCount('prepay_transactions', 0);
    }

    /**
     * (2) The grant grammar: bare and `:staged` grants resolve to the STAGED
     * mode for all three sensitive capabilities; only `:immediate` resolves to
     * immediate. Red-check: remove them from
     * McpToolModes::BARE_GRANT_DEFAULTS_STAGED and the bare assertions go red.
     */
    public function test_bare_grants_resolve_staged_for_the_money_and_phone_line_tools(): void
    {
        foreach (['set_call_billable', 'block_caller', 'allow_caller'] as $tool) {
            $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry($tool), $tool.' bare');
            $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry($tool.':staged'));
            $this->assertSame([$tool, 'immediate'], McpToolModes::parseGrantEntry($tool.':immediate'));
            $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry('stage_'.$tool));
            $this->assertSame([$tool.':staged'], McpToolModes::normalizeGrantEntries([$tool])['entries'], $tool.' normalized');
            $this->assertSame('staged', McpToolModes::defaultMode($tool), $tool.' default mode');
            $this->assertFalse(McpToolModes::isHeldOnly($tool), $tool.' keeps an immediate lane behind :immediate');
        }

        // The two lower-consequence verbs are NOT stageable and keep the
        // ordinary granted-immediate shape of their intake-manage siblings.
        foreach (['mark_call_followed_up', 'retry_call_transcription'] as $tool) {
            $this->assertFalse(McpToolModes::isStageable($tool), $tool.' is not stageable');
            $this->assertSame([$tool, null], McpToolModes::parseGrantEntry($tool));
        }
    }

    /**
     * (2) The staged HOLD, on the tool that moves money. A bare grant, a
     * `:staged` grant and the legacy `stage_set_call_billable` alias all hold
     * for approval with staged=false explicitly requested; no prepay moves and
     * is_billable does not change. Red-check: let a bare grant execute
     * immediately (drop the mode gate) and this goes red.
     */
    public function test_bare_and_staged_billable_grants_hold_for_approval_and_move_no_money(): void
    {
        [$call, $ticket] = $this->fixture();
        $contract = $this->prepayContract($ticket);
        $args = ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic billable proposal'];

        foreach (['set_call_billable', 'set_call_billable:staged', 'stage_set_call_billable'] as $grant) {
            $token = McpConfig::rotateStaffToken(allowedTools: [$grant], label: 'same-synthetic-actor');
            $result = $this->decoded($this->callTool($token, 'set_call_billable', $args + ['staged' => false]));
            $this->assertTrue($result['staged'], $grant.' must hold');
            $this->assertFalse((bool) $call->fresh()->is_billable);
            $this->assertDatabaseCount('prepay_transactions', 0);
            $this->assertSame(10.0, (float) $contract->fresh()->prepay_balance);
        }

        // One identical proposal from one actor, not three.
        $this->assertDatabaseCount('phone_call_action_proposals', 1);
        $this->assertSame('awaiting_approval', TechnicianActionLog::where('action_type', 'set_call_billable')->value('result_status'));

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $result['proposal_id']))
            ->assertOk()->assertJsonPath('is_billable', true);
        $this->assertTrue((bool) $call->fresh()->is_billable);
        // NOW the money moved: 600s = 0.1667h debited against the contract.
        $this->assertDatabaseCount('prepay_transactions', 1);
        $this->assertSame(0.1667, abs((float) PrepayTransaction::first()->hours));
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'set_call_billable',
            'approver_user_id' => $admin->id, 'result_status' => 'executed']);
        // A second approval of the same proposal is refused.
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $result['proposal_id']))->assertStatus(409);
        $this->assertDatabaseCount('prepay_transactions', 1);
    }

    /**
     * (2) The explicit `:immediate` grant is the ONLY lane that executes now,
     * and it does move prepay money. Also pins that billable is a desired
     * state, not a toggle, and that a repeat is a no-op.
     */
    public function test_explicit_immediate_grant_executes_and_moves_prepay(): void
    {
        [$call, $ticket] = $this->fixture();
        $contract = $this->prepayContract($ticket);
        $token = McpConfig::rotateStaffToken(allowedTools: ['set_call_billable:immediate']);
        $args = ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic immediate billable'];

        $first = $this->decoded($this->callTool($token, 'set_call_billable', $args));
        $this->assertFalse($first['staged']);
        $this->assertFalse($first['unchanged']);
        $this->assertTrue($first['is_billable']);
        $this->assertTrue((bool) $call->fresh()->is_billable);
        $this->assertDatabaseCount('prepay_transactions', 1);
        $this->assertSame(9.83, (float) $contract->fresh()->prepay_balance);

        $repeat = $this->decoded($this->callTool($token, 'set_call_billable', $args));
        $this->assertTrue($repeat['unchanged']);
        $this->assertSame(9.83, (float) $contract->fresh()->prepay_balance);

        // The inverse direction reverses the debit — the desired state is honoured.
        $off = $this->decoded($this->callTool($token, 'set_call_billable',
            ['phone_call_id' => $call->id, 'billable' => false, 'reason' => 'Synthetic non-billable']));
        $this->assertFalse($off['is_billable']);
        $this->assertDatabaseCount('prepay_transactions', 0);
        $this->assertSame(10.0, (float) $contract->fresh()->prepay_balance);
        $this->assertDatabaseCount('phone_call_action_proposals', 0);

        // A toggle-shaped call (no explicit boolean) is refused, never guessed.
        $this->assertStringContainsString('explicit boolean', $this->errorText($this->callTool($token,
            'set_call_billable', ['phone_call_id' => $call->id, 'reason' => 'no desired state'])));
    }

    /**
     * (3) REVALIDATE AT APPROVAL. A proposal staged against one state must not
     * apply blind: the ticket link can be gone (no prepay contract to resolve),
     * the billability can have been changed by a human, the duration can have
     * moved, or the number can have been blocked by someone else. Each is
     * consumed as stale with nothing applied. Red-check: skip the snapshot
     * comparison in PhoneCallActionService::approve() and this goes red.
     */
    public function test_staged_proposals_revalidate_call_ticket_and_directory_at_approval(): void
    {
        [$call, $ticket] = $this->fixture();
        $this->prepayContract($ticket);
        $admin = $this->admin();
        $token = McpConfig::rotateStaffToken(allowedTools: ['set_call_billable:staged', 'block_caller:staged']);
        $billArgs = ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic revalidation'];

        // (a) the ticket link disappears — the prepay contract is resolved
        // through it, so the precondition itself is gone.
        $r = $this->decoded($this->callTool($token, 'set_call_billable', $billArgs));
        $call->ticket_id = null;
        $call->save();
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertFalse((bool) $call->fresh()->is_billable);
        $this->assertDatabaseCount('prepay_transactions', 0);
        $call->ticket_id = $ticket->id;
        $call->save();

        // (b) a human already changed billability — the snapshot no longer matches.
        $r = $this->decoded($this->callTool($token, 'set_call_billable', $billArgs));
        $call->is_billable = true;
        $call->save();
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $r['proposal_id']))->assertStatus(409);
        $call->is_billable = false;
        $call->save();

        // (c) the billed duration moved under the proposal.
        $r = $this->decoded($this->callTool($token, 'set_call_billable', $billArgs));
        $call->duration = 1200;
        $call->save();
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertDatabaseCount('prepay_transactions', 0);

        // (d) a block proposal whose number somebody else already listed is
        // refused at approval, and the existing entry is left alone.
        $blocked = $this->decoded($this->callTool($token, 'block_caller',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic block proposal']));
        $existing = PhoneDirectoryEntry::create(['phone_number' => '+15555550142',
            'list_type' => PhoneDirectoryListType::Allowed, 'reason' => 'placed by a human']);
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $blocked['proposal_id']))->assertStatus(409);
        $this->assertSame(PhoneDirectoryListType::Allowed, $existing->fresh()->list_type);
        $this->assertDatabaseCount('phone_directory', 1);

        // (e) the ticket was reassigned to another client under the proposal.
        // The prepay contract is resolved THROUGH the ticket, so approving here
        // would debit a contract the approver never saw.
        $r = $this->decoded($this->callTool($token, 'set_call_billable', $billArgs));
        $otherClient = \App\Models\Client::factory()->create();
        $ticket->forceFill(['client_id' => $otherClient->id, 'contract_id' => null])->save();
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $r['proposal_id']))->assertStatus(409);
        $this->assertFalse((bool) $call->fresh()->is_billable);
        $this->assertDatabaseCount('prepay_transactions', 0);

        $this->assertSame(5, PhoneCallActionProposal::where('state', 'stale')->count());
        $this->assertSame(0, PhoneCallActionProposal::where('state', 'done')->count());
    }

    /**
     * The null-contract_id money-target drift (review r1 finding c1:v1:1).
     *
     * With ticket.contract_id NULL — the common intake case —
     * PrepayService::debitFromPhoneCall does NOT read the ticket for the money
     * target: it falls back to an unordered first() over the client's active
     * hours prepay contracts. So pinning ticket_id, ticket_client_id and
     * ticket_contract_id leaves the contract that is actually debited unpinned:
     * a prepay rollover between staging and approval (expire C1, activate C2)
     * leaves every one of those keys equal while the money lands somewhere the
     * approver never saw. The snapshot pins the RESOLVED contract id for that
     * reason, and this is the case that proves it.
     *
     * Red-check: drop 'resolved_contract_id' from snapshot() and this goes red
     * with a 200 where a 409 is required, and a debit against C2.
     */
    public function test_prepay_rollover_under_a_null_contract_ticket_is_caught_as_stale(): void
    {
        [$call, $ticket] = $this->fixture();
        $this->assertNull($ticket->fresh()->contract_id, 'this case is only meaningful on the fallback path');
        $c1 = $this->prepayContract($ticket);
        $admin = $this->admin();
        $token = McpConfig::rotateStaffToken(allowedTools: ['set_call_billable:staged']);

        $r = $this->decoded($this->callTool($token, 'set_call_billable',
            ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic rollover']));
        $this->assertTrue($r['staged']);
        $this->assertSame($c1->id, (int) PhoneCallActionProposal::find($r['proposal_id'])
            ->payload['snapshot']['resolved_contract_id'], 'the staged target is C1');

        // Billing rolls the client's prepay: C1 expires, renewal C2 goes active.
        // The ticket is untouched — same id, same client, contract_id still null.
        $c1->forceFill(['status' => 'expired'])->save();
        $c2 = $this->prepayContract($ticket);
        $ticket->refresh();
        $this->assertNull($ticket->contract_id);
        $this->assertSame($c2->id, app(\App\Services\PrepayService::class)
            ->resolveContractForPhoneCall($call->fresh())?->id, 'the money target moved to C2');

        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $r['proposal_id']))
            ->assertStatus(409)
            ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'prepay contract the debit resolves to'));

        // Nothing moved on EITHER contract, and the proposal is consumed.
        $this->assertDatabaseCount('prepay_transactions', 0);
        $this->assertSame(10.0, (float) $c1->fresh()->prepay_balance);
        $this->assertSame(10.0, (float) $c2->fresh()->prepay_balance);
        $this->assertFalse((bool) $call->fresh()->is_billable);
        $this->assertSame('stale', PhoneCallActionProposal::find($r['proposal_id'])->state);
    }

    /**
     * The cockpit approval card must not promise a recheck that approve() does
     * not perform for that action type (review r1 finding c1:v2:2). snapshot()
     * returns the ticket/client/contract keys only for set_call_billable, and
     * staleMessage() for block/allow is the caller number alone — so a shared
     * footer asserting ticket/client/contract revalidation is false on a
     * block/allow card, and a 'phone directory' claim is false on a billable
     * card. This is the one surface whose entire job is to inform the approval
     * decision, so the copy is part of the control.
     *
     * Red-check: restore the single shared <small> footer and this goes red.
     */
    public function test_approval_card_assurance_matches_what_each_action_actually_revalidates(): void
    {
        [$call, $ticket] = $this->fixture();
        $this->prepayContract($ticket);
        $admin = $this->admin();
        $token = McpConfig::rotateStaffToken(allowedTools: ['set_call_billable:staged', 'block_caller:staged']);

        $this->decoded($this->callTool($token, 'set_call_billable',
            ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic copy check']));
        $billableCard = $this->actingAs($admin)->get(route('cockpit.index'))->assertOk()->getContent();

        $billableSection = $this->actionSection($billableCard);
        $this->assertStringContainsString('the prepay contract the debit resolves to', $billableSection);
        $this->assertStringContainsString('It does not check the phone directory.', $billableSection);
        $this->assertStringNotContainsString('the phone directory still has no entry for it', $billableSection,
            'a billable approval rechecks no directory entry');

        PhoneCallActionProposal::query()->delete();
        $this->decoded($this->callTool($token, 'block_caller',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic copy check']));
        $blockCard = $this->actingAs($admin)->get(route('cockpit.index'))->assertOk()->getContent();

        $blockSection = $this->actionSection($blockCard);
        $this->assertStringContainsString('the phone directory still has no entry for it', $blockSection);
        // Scoped to the call-log action section: "contract" appears legitimately
        // elsewhere on the cockpit, and this card's own copy names it only to
        // DENY the recheck. What must be absent is any CLAIM of one.
        $this->assertStringContainsString('It does not check any ticket, client or contract.', $blockSection);
        $this->assertStringNotContainsString("that ticket's client", $blockSection,
            'a block approval rechecks no ticket, client or contract');
        $this->assertStringNotContainsString('the prepay contract the debit resolves to', $blockSection,
            'a block approval resolves no prepay contract');
    }

    /**
     * (2)+(4) block_caller holds on a bare grant, writes the same
     * PhoneDirectoryEntry the web path writes on approval, and refuses an
     * unparseable number. allow_caller cannot un-block: an existing entry is
     * reported with its list and never rewritten.
     */
    public function test_block_holds_then_writes_the_directory_and_allow_never_unblocks(): void
    {
        [$call] = $this->fixture();
        $admin = $this->admin();
        $token = McpConfig::rotateStaffToken(allowedTools: ['block_caller', 'allow_caller:immediate']);

        $held = $this->decoded($this->callTool($token, 'block_caller',
            ['phone_call_id' => $call->id, 'staged' => false, 'reason' => 'Synthetic spam caller']));
        $this->assertTrue($held['staged']);
        $this->assertDatabaseCount('phone_directory', 0);

        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $held['proposal_id']))
            ->assertOk()->assertJsonPath('list_type', 'blocked');
        $entry = PhoneDirectoryEntry::sole();
        $this->assertSame('+15555550142', $entry->phone_number);
        $this->assertSame(PhoneDirectoryListType::Blocked, $entry->list_type);
        $this->assertSame('Added from call #'.$call->id, $entry->reason);

        // allow_caller, even with the immediate grant, will not lift that block.
        $text = $this->errorText($this->callTool($token, 'allow_caller',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic allow attempt']));
        $this->assertStringContainsString('already on the Blocked list', $text);
        $this->assertStringContainsString('not changed', $text);
        $this->assertSame(PhoneDirectoryListType::Blocked, $entry->fresh()->list_type);
        $this->assertDatabaseCount('phone_directory', 1);

        // An unparseable caller number is refused for both verbs.
        [$junk] = $this->fixture(['call_uuid' => 'synthetic-junk-number', 'from_number' => 'anonymous'], []);
        foreach (['block_caller', 'allow_caller'] as $tool) {
            $this->assertStringContainsString('Could not parse the caller number',
                $this->errorText($this->callTool($token, $tool,
                    ['phone_call_id' => $junk->id, 'reason' => 'Synthetic unparseable'])));
        }
        $this->assertDatabaseCount('phone_directory', 1);

        // An OUTBOUND call is refused for both verbs: from_number there is the
        // number this PSA dialled, so the row would list the wrong party's line.
        // The staff call page offers these buttons for inbound calls only.
        [$outbound] = $this->fixture(['call_uuid' => 'synthetic-outbound',
            'direction' => CallDirection::Outbound, 'from_number' => '+15555550199'], []);
        foreach (['block_caller', 'allow_caller'] as $tool) {
            $this->assertStringContainsString('inbound call only',
                $this->errorText($this->callTool($token, $tool,
                    ['phone_call_id' => $outbound->id, 'reason' => 'Synthetic outbound probe'])));
        }
        $this->assertDatabaseCount('phone_directory', 1);
    }

    /**
     * (4) The preserved service-level refusals for the remaining two, plus the
     * granted-immediate shape of the lower-consequence pair. set_call_billable
     * refuses a call with no ticket link; retry_call_transcription refuses
     * Processing, a call with no recording, and an unconfigured instance;
     * mark_call_followed_up never re-stamps an existing follow-up.
     */
    public function test_preserved_refusals_and_the_granted_immediate_pair(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [
            'set_call_billable:immediate', 'mark_call_followed_up', 'retry_call_transcription',
        ]);

        // no ticket link -> refuse (the web path's own refusal).
        [$unlinked] = $this->fixture(['call_uuid' => 'synthetic-unlinked', 'ticket_id' => null, 'is_billable' => null], []);
        $this->assertStringContainsString('must be linked to a ticket', $this->errorText($this->callTool($token,
            'set_call_billable', ['phone_call_id' => $unlinked->id, 'billable' => true, 'reason' => 'Synthetic no ticket'])));
        $this->assertNull($unlinked->fresh()->is_billable);
        $this->assertDatabaseCount('prepay_transactions', 0);

        // transcription: no recording -> refuse.
        $this->assertStringContainsString('No recording available', $this->errorText($this->callTool($token,
            'retry_call_transcription', ['phone_call_id' => $unlinked->id, 'reason' => 'Synthetic no recording'])));

        Setting::setEncrypted('openai_api_key', 'synthetic-key');
        [$call] = $this->fixture(
            ['call_uuid' => 'synthetic-recorded', 'transcription_status' => TranscriptionStatus::Processing],
            ['duration' => 600, 'recording_url' => 'https://example.invalid/recording.mp3'],
        );

        // Processing -> refuse, and the status is not touched.
        $this->assertStringContainsString('already in progress', $this->errorText($this->callTool($token,
            'retry_call_transcription', ['phone_call_id' => $call->id, 'reason' => 'Synthetic in progress'])));
        $this->assertSame(TranscriptionStatus::Processing, $call->fresh()->transcription_status);

        // Not Processing -> re-queued immediately on an ordinary grant.
        $call->update(['transcription_status' => TranscriptionStatus::Failed]);
        $retry = $this->decoded($this->callTool($token, 'retry_call_transcription',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic retry']));
        $this->assertSame('pending', $retry['transcription_status']);
        $this->assertSame(TranscriptionStatus::Pending, $call->fresh()->transcription_status);
        Process::assertRan(fn ($process) => str_contains($process->command, 'calls:transcribe '.$call->id));
        $this->assertDatabaseCount('phone_call_action_proposals', 0);

        // followed up: set once, then reported unchanged with the actor preserved.
        $first = $this->decoded($this->callTool($token, 'mark_call_followed_up',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic follow-up']));
        $this->assertFalse($first['unchanged']);
        $stamp = $call->fresh()->followed_up_at;
        $actor = $call->fresh()->followed_up_by;
        $this->assertNotNull($stamp);
        $repeat = $this->decoded($this->callTool($token, 'mark_call_followed_up',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic follow-up again']));
        $this->assertTrue($repeat['unchanged']);
        $this->assertEquals($stamp, $call->fresh()->followed_up_at);
        $this->assertSame($actor, $call->fresh()->followed_up_by);

        // A missing call is refused by id for every verb, before any write.
        $this->assertStringContainsString('Phone call not found', $this->errorText($this->callTool($token,
            'mark_call_followed_up', ['phone_call_id' => 999999, 'reason' => 'Synthetic missing'])));
    }

    /**
     * (5) The audit trail. Every executed and held action writes the
     * TechnicianActionLog row the web path's service methods sit behind, and
     * neither that row nor the MCP audit log carries the transcript, the
     * caller's phone number, or the reason prose in the MCP log's arguments.
     * Red-check: delete the audit() call in the apply path and this goes red.
     */
    public function test_every_action_writes_its_audit_row_without_transcript_or_caller_number(): void
    {
        [$call, $ticket] = $this->fixture([],
            ['duration' => 600, 'recording_url' => 'https://example.invalid/r.mp3']);
        $this->prepayContract($ticket);
        Setting::setEncrypted('openai_api_key', 'synthetic-key');
        $admin = $this->admin();
        $token = McpConfig::rotateStaffToken(allowedTools: [
            'set_call_billable:immediate', 'block_caller:staged', 'mark_call_followed_up', 'retry_call_transcription',
        ]);

        $this->decoded($this->callTool($token, 'set_call_billable',
            ['phone_call_id' => $call->id, 'billable' => true, 'reason' => 'Synthetic audited billable']));
        $held = $this->decoded($this->callTool($token, 'block_caller',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic audited block']));
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $held['proposal_id']))->assertOk();
        $this->decoded($this->callTool($token, 'mark_call_followed_up',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic audited follow-up']));
        $this->decoded($this->callTool($token, 'retry_call_transcription',
            ['phone_call_id' => $call->id, 'reason' => 'Synthetic audited retry']));

        foreach (['set_call_billable' => 1, 'block_caller' => 2, 'mark_call_followed_up' => 1,
            'retry_call_transcription' => 1] as $action => $rows) {
            $logs = TechnicianActionLog::where('action_type', $action)->get();
            $this->assertCount($rows, $logs, $action.' audit rows');
            $this->assertSame($ticket->id, (int) $logs->last()->ticket_id, $action.' ticket id');
            $this->assertStringContainsString('Synthetic audited', (string) $logs->last()->summary);
        }
        // The held block wrote awaiting_approval first, then executed with the approver.
        $this->assertSame(['awaiting_approval', 'executed'],
            TechnicianActionLog::where('action_type', 'block_caller')->orderBy('id')->pluck('result_status')->all());
        $this->assertSame($admin->id, (int) TechnicianActionLog::where('action_type', 'block_caller')
            ->orderBy('id')->get()->last()->approver_user_id);

        $technicianJson = TechnicianActionLog::get()->toJson();
        $this->assertStringNotContainsString('SYNTHETIC BODY', $technicianJson);
        $this->assertStringNotContainsString('5555550142', $technicianJson);

        $mcpJson = McpAuditLog::whereIn('tool_name',
            ['set_call_billable', 'block_caller', 'mark_call_followed_up', 'retry_call_transcription'])->get()->toJson();
        $this->assertStringNotContainsString('SYNTHETIC BODY', $mcpJson);
        $this->assertStringNotContainsString('5555550142', $mcpJson);
        $this->assertStringNotContainsString('Synthetic audited', $mcpJson);
        $this->assertStringContainsString('reason_length', $mcpJson);
    }

    /**
     * Just the "Call log action approvals" <section> of a rendered cockpit page,
     * so copy assertions cannot be satisfied or broken by unrelated widgets.
     */
    private function actionSection(string $html): string
    {
        $start = strpos($html, 'Call log action approvals');
        $this->assertNotFalse($start, 'the call-log action card did not render');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /**
     * A client contract with prepay HOURS, so the money move is observable:
     * has_prepay is derived from a non-null prepay_balance, and
     * PrepayService::debitFromPhoneCall resolves exactly this shape.
     */
    private function prepayContract(Ticket $ticket): Contract
    {
        return Contract::create([
            'client_id' => $ticket->client_id,
            'name' => 'Synthetic prepay MSA',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 10,
            'prepay_used' => 0,
            'prepay_balance' => 10,
        ]);
    }
}
