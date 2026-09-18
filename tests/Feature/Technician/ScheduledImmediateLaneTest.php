<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\McpToken;
use App\Models\TechnicianRun;
use App\Models\User;
use App\Services\Technician\Scheduled\ApprovalEnvelope;
use App\Services\Technician\Scheduled\TacticalDispatch;
use Illuminate\Support\Facades\DB;

/**
 * Ruled design point 3 — the `:immediate` no-cockpit lane, and the #2093 guard.
 *
 * A token holding `<tool>:immediate` that passes `execute_at` is queued DIRECTLY into
 * scheduled_authorizations: no cockpit proposal, `approver_user_id` NULL, and NO
 * `downgraded_to_staged` key, because the token was not downgraded — it exercised the
 * grant it holds. A token holding only `<tool>:staged` gets the cockpit lane AND the
 * unmistakable `downgraded_to_staged: true` signal (#2093). The two notices are mutually
 * exclusive by construction and this suite asserts both halves.
 *
 * Fixture is inherited from ScheduledExecuteAtTest: every Guzzle request terminates in
 * the handler, no live socket and no vendor. $this->wire is the count of requests that
 * actually reached the transport — the only honest proof that nothing executed.
 */
class ScheduledImmediateLaneTest extends \Tests\TestCase
{
    use ScheduledExecuteAtFixture;

    private function immediateCall(array $extra = [], array $grant = ['tactical_set_maintenance:immediate']): array
    {
        return $this->mcp($this->bearer($grant), 'tactical_set_maintenance',
            $this->maintenanceArgs(array_merge(['execute_at' => self::AT, 'staged' => false], $extra)));
    }

    private function token(): McpToken
    {
        return McpToken::where('label', 'synthetic-execute-at')->sole();
    }

    // ── the lane itself ──────────────────────────────────────────────────────

    public function test_immediate_grant_with_execute_at_is_queued_directly_with_no_cockpit_proposal(): void
    {
        $result = $this->immediateCall();
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue($result['scheduled'] ?? false, json_encode($result));
        // The #2093 sibling: this lane must NEVER carry the downgrade key. A client
        // branching on it would read "held for a human" where nobody is holding it.
        $this->assertArrayNotHasKey('downgraded_to_staged', $result);
        $this->assertStringContainsString('no cockpit approval is required', $result['message']);

        $row = DB::table('scheduled_authorizations')->sole();
        $this->assertSame('waiting', $row->state);
        $this->assertNull($row->approver_user_id, 'a token-queued row must record NO human approver');
        $this->assertSame($this->token()->id, (int) $row->originating_mcp_token_id);
        $this->assertSame((int) $result['authorization_id'], (int) $row->id);
        $this->assertSame(['2026-09-16 03:30:00', '2026-09-16 04:30:00'], [$row->local_start, $row->local_end]);
        $this->assertSame('UTC', $row->display_timezone);

        // No proposal is left awaiting a human anywhere in the cockpit.
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->assertSame(TechnicianRunState::Scheduled, $run->state);
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count());
        $this->actingAs($this->user)->get(route('cockpit.index'))->assertOk()
            ->assertSee('Queued by an MCP token under its own standing permission', false);
        $this->assertCount(0, $this->wire, 'the direct lane executed now');
    }

    public function test_the_sealed_envelope_records_the_absent_approver_and_never_forges_one(): void
    {
        $result = $this->immediateCall();
        $row = DB::table('scheduled_authorizations')->sole();
        $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $this->assertArrayHasKey('approver_user_id', $sealed);
        $this->assertNull($sealed['approver_user_id'], 'the envelope forged a human approver');
        $this->assertSame($this->token()->id, $sealed['originating_mcp_token_id']);
        // Not the system user, not the AI actor, not zero — no live user id at all.
        foreach (User::pluck('id') as $id) {
            $this->assertNotSame((int) $id, $sealed['approver_user_id']);
        }
        $this->assertSame([], $sealed['human_inputs'], 'no human typed anything on this lane');
        $this->assertCount(0, $this->wire);
        $this->assertNotNull($result['authorization_id']);
    }

    public function test_the_direct_row_fires_in_its_window_and_not_before(): void
    {
        $result = $this->immediateCall();
        $id = (int) $result['authorization_id'];
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire, 'dispatched before the window opened');
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame('completed', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_a_confirmation_bearing_tool_seals_the_ai_inputs_on_the_direct_lane(): void
    {
        $result = $this->mcp($this->bearer(['tactical_reboot_device:immediate']), 'tactical_reboot_device',
            $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false, 'confirm_hostname' => 'fixture-device']));
        $this->assertTrue($result['scheduled'] ?? false, json_encode($result));
        $row = DB::table('scheduled_authorizations')->sole();
        $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $this->assertSame(['confirm_hostname' => 'fixture-device'], $sealed['human_inputs']);
        $this->assertNull($row->approver_user_id);
        $this->assertCount(0, $this->wire);
    }

    public function test_a_wrong_confirmation_refuses_the_direct_call_and_leaves_no_cockpit_proposal(): void
    {
        // A refused direct admission must not silently become an approval request: the
        // caller asked to schedule under its own permission, not to queue work for a tech.
        $result = $this->mcp($this->bearer(['tactical_reboot_device:immediate']), 'tactical_reboot_device',
            $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false, 'confirm_hostname' => 'other-device']));
        $this->assertStringStartsWith('execute_at_admission_refused', (string) ($result['error'] ?? ''), json_encode($result));
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count());
        $this->assertSame(TechnicianRunState::Withdrawn, TechnicianRun::sole()->state);
        $this->assertCount(0, $this->wire);
    }

    // ── point 3: lineage is STRICTER for a token-approved row ────────────────

    public function test_a_downgrade_to_staged_before_fire_time_refuses_the_token_row(): void
    {
        $result = $this->immediateCall();
        $id = (int) $result['authorization_id'];
        // The compensating control for a lane with no human in it: withdrawing the
        // immediate grant stops a queued token action. `:staged` is still a real grant of
        // the capability, so this is the case a looser check would wave through.
        $this->token()->update(['tools' => ['tactical_set_maintenance:staged']]);
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire, 'a downgraded token still fired its scheduled action');
        $row = DB::table('scheduled_authorizations')->find($id);
        $this->assertSame('blocked', $row->state);
        $this->assertSame('authorization_changed', $row->reason);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenLifecycleChanges')]
    public function test_a_paused_or_revoked_token_refuses_the_token_row(string $column): void
    {
        $result = $this->immediateCall();
        $id = (int) $result['authorization_id'];
        $this->token()->update([$column => now()]);
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run($id);
        $this->assertCount(0, $this->wire, $column.' still fired its scheduled action');
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->find($id)->state);
    }

    public static function tokenLifecycleChanges(): array
    {
        return ['paused' => ['paused_at'], 'revoked' => ['revoked_at']];
    }

    public function test_a_human_approved_row_still_accepts_either_grant_mode(): void
    {
        // The tightening is for token rows ONLY. A human-approved row whose token later
        // holds just `:staged` must still fire — `:immediate` implies staged in the grant
        // grammar and a human already authorised this one.
        $run = $this->stageWithExecuteAt(['tactical_set_maintenance:staged']);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $row = DB::table('scheduled_authorizations')->sole();
        $this->assertSame((int) $this->user->id, (int) $row->approver_user_id);
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run((int) $row->id);
        $this->assertCount(1, $this->wire);
    }

    // ── point 4: cancel, which could not work at all for these rows ──────────

    public function test_any_active_technician_can_cancel_a_token_queued_row(): void
    {
        $result = $this->immediateCall();
        $other = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->actingAs($other)->post(route('cockpit.schedule.cancel', $run))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('cancelled', DB::table('scheduled_authorizations')->value('state'));
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run((int) $result['authorization_id']);
        $this->assertCount(0, $this->wire);
    }

    public function test_a_non_technician_still_cannot_cancel_a_token_queued_row(): void
    {
        $result = $this->immediateCall();
        $outsider = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $run = TechnicianRun::findOrFail($result['run_id']);
        $this->actingAs($outsider)->post(route('cockpit.schedule.cancel', $run))->assertForbidden();
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->value('state'));
        $this->assertNotNull($result['authorization_id']);
    }

    public function test_a_human_approved_row_stays_cancellable_only_by_its_approver(): void
    {
        $run = $this->stageWithExecuteAt();
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $other = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $this->actingAs($other)->post(route('cockpit.schedule.cancel', $run))->assertForbidden();
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->value('state'));
    }

    // ── the repeat-admission conflict check is NULL-exact ────────────────────

    public function test_a_repeat_direct_call_never_admits_a_second_authorization(): void
    {
        // The direct lane inherits the executor's existing repeat rails unchanged: the
        // per-ticket staging cooldown refuses the second call before any admission runs.
        // What matters here is the invariant, not which rail catches it — one call, one
        // authorization, and the refusal never runs the action now.
        $first = $this->immediateCall();
        $this->assertTrue($first['scheduled'] ?? false, json_encode($first));
        $again = $this->immediateCall();
        $this->assertArrayHasKey('error', $again, json_encode($again));
        $this->assertArrayNotHasKey('downgraded_to_staged', $again);
        $this->assertDatabaseCount('scheduled_authorizations', 1);
        $this->assertSame((int) $first['authorization_id'], (int) DB::table('scheduled_authorizations')->value('id'));
        $this->assertCount(0, $this->wire);
    }

    public function test_a_token_row_arriving_later_with_a_human_approver_is_refused_not_returned(): void
    {
        // The conflict check used `!=`, under which NULL == 0 and NULL == null are BOTH
        // true: a human approver arriving on a token row (or the reverse) would have been
        // returned as an idempotent success sealed against the other party's authority.
        $result = $this->immediateCall();
        $run = TechnicianRun::findOrFail($result['run_id']);
        $admission = app(\App\Services\Technician\Scheduled\ScheduledAdmission::class);
        $evidence = app(\App\Services\Technician\Scheduled\TacticalEvidence::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('existing_authorization_conflict');
        $admission->admit($run->id, \App\Services\Technician\Scheduled\ScheduledApprover::human($this->user->id),
            (string) $run->content_hash, $this->token()->id, '2026-09-16 03:30:00', '2026-09-16 04:30:00', 'UTC', [], $evidence);
    }

    public function test_a_token_row_is_refused_a_second_admission_under_a_different_token(): void
    {
        // The approver carries the token id, and lineage() cross-checks it against the
        // provenance. Without that check a row queued by token A could be re-admitted
        // naming token B, and the NULL-exact approver comparison would not catch it:
        // both approver ids are NULL.
        $result = $this->immediateCall();
        $run = TechnicianRun::findOrFail($result['run_id']);
        $other = McpToken::create(['label' => 'other-token', 'token_hash' => str_repeat('b', 64),
            'token_prefix' => 'psa_other', 'tools' => ['tactical_set_maintenance:immediate'], 'activated_at' => now()]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lineage_mismatch');
        app(\App\Services\Technician\Scheduled\ScheduledAdmission::class)->admit(
            $run->id, \App\Services\Technician\Scheduled\ScheduledApprover::token($other->id),
            (string) $run->content_hash, $this->token()->id,
            '2026-09-16 03:30:00', '2026-09-16 04:30:00', 'UTC', [], app(\App\Services\Technician\Scheduled\TacticalEvidence::class));
    }

    public function test_the_fire_time_dispatcher_never_substitutes_a_user_for_the_absent_approver(): void
    {
        // A token row has no approver, and the destructive-action confirm token binds the
        // actor id. Filling the absence with any live user would put a real technician's id
        // on an action they never authorised, and the audit trail would name them.
        $result = $this->mcp($this->bearer(['tactical_reboot_device:immediate']), 'tactical_reboot_device',
            $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false, 'confirm_hostname' => 'fixture-device']));
        $this->assertTrue($result['scheduled'] ?? false, json_encode($result));
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run((int) $result['authorization_id']);
        $this->assertCount(1, $this->wire);
        // The action log is the record. It must credit the scheduled authorization, and no
        // user row, for a run nobody approved.
        $audit = \App\Models\TacticalActionLog::orderByDesc('id')->first();
        $this->assertNotNull($audit, 'the scheduled dispatch wrote no action log row');
        $this->assertNull($audit->actor_id, 'the dispatcher credited a user for a token-queued action');
        $this->assertStringStartsWith('scheduled:', (string) $audit->actor_label);
    }

    public function test_a_human_row_arriving_later_as_a_token_row_is_refused_not_returned(): void
    {
        $run = $this->stageWithExecuteAt(['tactical_set_maintenance:immediate']);
        $this->actingAs($this->user)->post(route('cockpit.approve', $run))->assertRedirect()->assertSessionHas('success');
        $admission = app(\App\Services\Technician\Scheduled\ScheduledAdmission::class);
        $evidence = app(\App\Services\Technician\Scheduled\TacticalEvidence::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('existing_authorization_conflict');
        $admission->admit($run->id, \App\Services\Technician\Scheduled\ScheduledApprover::token($this->token()->id),
            (string) $run->content_hash, $this->token()->id, '2026-09-16 03:30:00', '2026-09-16 04:30:00', 'UTC', [], $evidence);
    }

    // ── the fire-time safety checks are unchanged (point 5) ──────────────────

    public function test_the_kill_switch_still_stops_a_token_queued_row(): void
    {
        $result = $this->immediateCall();
        \App\Models\Setting::setValue('technician_kill_switch', '1');
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run((int) $result['authorization_id']);
        $this->assertCount(0, $this->wire, 'the kill switch did not stop the immediate lane');
        $this->assertSame('waiting', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_a_retargeted_ticket_still_refuses_a_token_queued_row_at_fire_time(): void
    {
        $result = $this->immediateCall();
        $elsewhere = \App\Models\Client::factory()->create();
        $this->ticket->update(['client_id' => $elsewhere->id]);
        $this->time = $this->time->setTime(3, 30);
        app(TacticalDispatch::class)->run((int) $result['authorization_id']);
        $this->assertCount(0, $this->wire, 'ticket binding was not rechecked on the immediate lane');
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->value('state'));
    }

    // ── #2093: the committed guard for the downgrade signal ──────────────────

    public function test_a_staged_only_token_asking_for_immediate_execution_with_execute_at_is_told_it_was_downgraded(): void
    {
        // #2093, the defect this guard exists for: with execute_at present, forcing
        // $staged = true BEFORE computing the downgrade left downgraded_to_staged unset,
        // so a staged-only token got {success: true, message: 'Staged for cockpit
        // approval…'} — indistinguishable, to a client branching on the documented flag,
        // from having executed under its own authority.
        $result = $this->immediateCall(grant: ['tactical_set_maintenance:staged']);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue($result['downgraded_to_staged'] ?? false,
            'REGRESSION #2093: a staged-only token got no downgraded_to_staged key on an execute_at call');
        $this->assertStringContainsString('Immediate execution is not granted for this token', $result['message']);
        // And it really is held for a human: no authorization, a live proposal, nothing sent.
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::findOrFail($result['run_id'])->state);
        $this->assertCount(0, $this->wire);
    }

    public function test_the_downgrade_signal_is_identical_with_and_without_execute_at(): void
    {
        // The contract #2093 protects, stated as the equivalence it actually is: adding
        // execute_at must not change WHETHER the caller is told it was downgraded.
        // Two DIFFERENT tools so neither call meets the other's per-ticket staging
        // cooldown or idempotency rail: the comparison is of the signal, not of a repeat.
        $without = $this->mcp($this->bearer(['tactical_set_maintenance:staged']), 'tactical_set_maintenance',
            $this->maintenanceArgs(['staged' => false]));
        $this->assertTrue($without['downgraded_to_staged'] ?? false, json_encode($without));
        $with = $this->mcp($this->bearer(['tactical_reboot_device:staged']), 'tactical_reboot_device',
            $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false, 'confirm_hostname' => 'fixture-device']));
        $this->assertTrue($with['success'] ?? false, json_encode($with));
        $this->assertSame($without['downgraded_to_staged'], $with['downgraded_to_staged'] ?? null);
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertCount(0, $this->wire);
    }

    public function test_the_two_notices_are_mutually_exclusive(): void
    {
        // Exactly one of the two lanes fires for any given call, and each carries only its
        // own notice. A result bearing both would mean the token was simultaneously
        // trusted and refused.
        $direct = $this->immediateCall();
        $this->assertArrayNotHasKey('downgraded_to_staged', $direct);
        $this->assertTrue($direct['scheduled'] ?? false);

        // A different tool, so this is a fresh call and not a repeat of the one above.
        $downgraded = $this->mcp($this->bearer(['tactical_reboot_device:staged']), 'tactical_reboot_device',
            $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false, 'confirm_hostname' => 'fixture-device']));
        $this->assertTrue($downgraded['downgraded_to_staged'] ?? false, json_encode($downgraded));
        $this->assertArrayNotHasKey('scheduled', $downgraded);
        $this->assertArrayNotHasKey('authorization_id', $downgraded);
    }

    // ── the idempotent guard must not strand the caller's OWN proposal ───────

    public function test_a_retry_after_a_death_between_staging_and_admission_admits_the_callers_own_proposal(): void
    {
        // The crash window the guard has to survive: stageAction() COMMITS the proposal and
        // the request dies before admission. The run is left AwaitingApproval with no
        // authorization, so the retry's stage result comes back idempotent naming it. A guard
        // that refuses EVERY idempotent result refuses that retry forever, and the caller's
        // own destructive proposal sits in the cockpit waiting for a technician — the lane
        // the caller never chose.
        $bearer = $this->bearer(['tactical_set_maintenance:immediate']);
        $arguments = $this->maintenanceArgs(['execute_at' => self::AT, 'staged' => false]);
        $this->app->bind(\App\Services\Technician\Scheduled\ScheduledDirectAdmission::class,
            fn () => throw new \RuntimeException('synthetic death between staging and admission'));
        $died = $this->mcp($bearer, 'tactical_set_maintenance', $arguments);
        $this->assertStringContainsString('synthetic death', (string) ($died['error'] ?? ''), json_encode($died));
        $orphan = TechnicianRun::sole();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $orphan->state);
        $this->assertDatabaseCount('scheduled_authorizations', 0);

        $this->app->bind(\App\Services\Technician\Scheduled\ScheduledDirectAdmission::class,
            fn ($app) => new \App\Services\Technician\Scheduled\ScheduledDirectAdmission(
                $app->make(\App\Services\Technician\Scheduled\ScheduledAdmission::class)));
        $retry = $this->mcp($bearer, 'tactical_set_maintenance', $arguments);
        $this->assertTrue($retry['scheduled'] ?? false, json_encode($retry));
        $this->assertSame($orphan->id, (int) $retry['run_id'], 'the retry admitted some other run');
        $row = DB::table('scheduled_authorizations')->sole();
        $this->assertSame($orphan->id, (int) $row->run_id);
        $this->assertNull($row->approver_user_id, 'the recovered row recorded a human approver');
        $this->assertSame($this->token()->id, (int) $row->originating_mcp_token_id);
        $this->assertSame(TechnicianRunState::Scheduled, $orphan->fresh()->state);
        $this->assertSame(0, TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count(),
            'the caller\'s own proposal was left stranded in the cockpit');
        $this->assertCount(0, $this->wire);
    }

    public function test_a_live_proposal_from_another_token_is_still_refused_by_name_and_left_untouched(): void
    {
        // The case the guard exists for, and the one the provenance check must not weaken:
        // an identical proposal already live in the cockpit under ANOTHER token's lineage.
        // Admitting it would put this token's authority on someone else's row, and a refused
        // admission would WITHDRAW their live decision.
        $foreign = $this->mcp($this->bearer(['tactical_set_maintenance:staged'], 'synthetic-other-token'),
            'tactical_set_maintenance', $this->maintenanceArgs(['execute_at' => self::AT]));
        $this->assertTrue($foreign['success'] ?? false, json_encode($foreign));
        $result = $this->immediateCall();
        $this->assertSame('execute_at_conflicts_with_existing_run', $result['error'] ?? null, json_encode($result));
        $this->assertDatabaseCount('scheduled_authorizations', 0);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::findOrFail($foreign['run_id'])->state,
            'a refusal that should have touched nothing withdrew the other token\'s proposal');
        $this->assertCount(0, $this->wire);
    }
}
