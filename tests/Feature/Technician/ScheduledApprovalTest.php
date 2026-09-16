<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledClock;
use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledEvidence;
use App\Services\Technician\Scheduled\ScheduledOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ScheduledApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected TechnicianRun $run;

    protected User $user;

    protected ScheduledEvidence $evidence;

    protected CarbonImmutable $time;

    protected bool $healthy = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduled_approvals.enabled' => true]);
        $this->time = CarbonImmutable::parse('2026-09-15 00:00:00', 'UTC');
        $clock = Mockery::mock(ScheduledClock::class);
        $clock->shouldReceive('now')->andReturnUsing(fn () => $this->time);
        $clock->shouldReceive('healthy')->andReturnUsing(fn () => $this->healthy);
        $this->app->instance(ScheduledClock::class, $clock);
        $this->user = User::factory()->create(['role' => 'tech', 'is_active' => true]);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->run = TechnicianRun::create(['ticket_id' => $ticket->id, 'client_id' => $client->id,
            'action_type' => 'cipp_stage_set_mailbox_forwarding', 'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_meta' => ['scheduled_provenance' => ['version' => 1, 'kind' => 'native_human', 'user_id' => $this->user->id]],
        ]);
        $this->evidence = new class implements ScheduledEvidence
        {
            public function approve(TechnicianRun $run, User $approver, array $humanInputs): array
            {
                return ['human_inputs' => $humanInputs, 'payload' => ['forward' => 'synthetic@example.test'], 'target' => ['tenant_id' => 'synthetic-tenant', 'object_id' => 'synthetic-object']];
            }

            public function revalidate(TechnicianRun $run, User $approver, array $approved): array
            {
                return $approved;
            }
        };
    }

    protected function admit(): int
    {
        return app(ScheduledAdmission::class)->admit($this->run->id, $this->user->id, $this->run->content_hash, null,
            '2026-09-15 01:00:00', '2026-09-15 02:00:00', 'UTC', [], $this->evidence);
    }

    public function test_admission_double_submit_and_note_delivery_are_idempotent(): void
    {
        $id = $this->admit();
        $this->assertSame($id, $this->admit());
        $this->assertDatabaseCount('scheduled_authorizations', 1);
        $this->assertDatabaseCount('scheduled_note_outbox', 1);
        $this->assertSame(TechnicianRunState::Scheduled, $this->run->fresh()->state);
        $this->assertFalse($this->run->fresh()->claimForExecution());
        $item = DB::table('scheduled_note_outbox')->value('id');
        $outbox = app(ScheduledOutbox::class);
        $this->assertTrue($outbox->deliver($item));
        $this->assertTrue($outbox->deliver($item));
        $note = TicketNote::where('ticket_id', $this->run->ticket_id)->sole();
        $this->assertTrue($note->is_private);
        $this->assertSame('system', $note->note_type->value);
        $this->assertStringNotContainsString('synthetic@example.test', $note->body);
    }

    public function test_not_before_end_and_clock_health_are_enforced(): void
    {
        $id = $this->admit();
        $c = app(ScheduledCoordinator::class);
        $this->time = $this->time->setTime(0, 59, 59);
        $this->assertNull($c->claim($id));
        $this->time = $this->time->setTime(1, 0);
        $this->healthy = false;
        $this->assertNull($c->claim($id));
        $this->healthy = true;
        $nonce = $c->claim($id);
        $this->assertNotNull($nonce);
        $this->time = $this->time->setTime(2, 0);
        $this->assertFalse($c->intent($id, $nonce, $this->evidence));
        $this->assertSame('expired', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_cancel_wins_and_stale_nonce_cannot_settle_or_dispatch(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertNull($c->claim($id));
        $this->assertTrue($c->cancel($id, $this->user->id));
        $this->assertFalse($c->intent($id, $nonce, $this->evidence));
        $this->assertFalse($c->settle($id, $nonce, 'completed'));
        $this->assertSame('cancelled', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_uninstalled_adapter_cannot_fire_even_when_flag_and_clock_are_healthy(): void
    {
        // PR3 now installs reboot; the not-yet-enrolled CIPP sign-in adapter stays absent.
        $this->run->update(['action_type' => 'cipp_stage_disable_user_sign_in']);
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertFalse($c->intent($id, $nonce, $this->evidence));
        $this->assertSame('adapter_unavailable', DB::table('scheduled_authorizations')->value('reason'));
        $this->assertNull(DB::table('scheduled_authorizations')->value('intent_at'));
    }

    public function test_pre_intent_recovery_fences_old_worker_and_post_intent_is_terminal(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $old = $c->claim($id);
        $this->time = $this->time->addMinutes(5);
        $c->recover($id);
        $this->assertFalse($c->intent($id, $old, $this->evidence));
        $this->assertNull($c->claim($id));
        $this->time = $this->time->addMinute();
        $new = $c->claim($id);
        $this->assertNotSame($old, $new);
        // Synthetic persisted intent: PR1 has no production route to generate one.
        DB::table('scheduled_authorizations')->where('id', $id)->update(['state' => 'dispatch_intent', 'intent_at' => $this->time]);
        $this->assertFalse($c->cancel($id, $this->user->id));
        $this->assertFalse($c->settle($id, $old, 'completed'));
        $this->time = $this->time->addSeconds(640);
        $c->recover($id);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertFalse($c->settle($id, $new, 'completed'));
        $this->assertNull($c->claim($id));
        $this->assertDatabaseCount('scheduled_target_fences', 1);
    }

    public function test_revoked_approver_and_mutated_binding_block_before_adapter_check(): void
    {
        foreach (['user', 'ticket', 'hash', 'action', 'ciphertext', 'digest', 'revision', 'time', 'provenance'] as $mutation) {
            $id = $this->admit();
            $this->time = $this->time->setTime(1, 0);
            $c = app(ScheduledCoordinator::class);
            $nonce = $c->claim($id);
            $original = $this->run->fresh()->getAttributes();
            match ($mutation) {
                'user' => $this->user->update(['is_active' => false]),
                'ticket' => DB::table('technician_runs')->where('id', $this->run->id)->update(['ticket_id' => Ticket::factory()->create(['client_id' => $this->run->client_id])->id]),
                'hash' => DB::table('technician_runs')->where('id', $this->run->id)->update(['content_hash' => str_repeat('b', 64)]),
                'action' => DB::table('scheduled_authorizations')->where('id', $id)->update(['action_type' => 'cipp_stage_convert_mailbox']),
                'ciphertext' => DB::table('scheduled_authorizations')->where('id', $id)->update(['ciphertext' => 'invalid']),
                'digest' => DB::table('scheduled_authorizations')->where('id', $id)->update(['digest' => str_repeat('0', 64)]),
                'revision' => DB::table('scheduled_authorizations')->where('id', $id)->update(['revision' => 200]),
                'time' => DB::table('scheduled_authorizations')->where('id', $id)->update(['expires_at' => '2026-09-15 03:00:00']),
                'provenance' => DB::table('technician_runs')->where('id', $this->run->id)->update(['proposed_meta' => '{}']),
            };
            $this->assertFalse($c->intent($id, $nonce, $this->evidence), $mutation);
            $state = DB::table('scheduled_authorizations')->find($id);
            $this->assertSame('blocked', $state->state, $mutation);
            $this->assertNotSame('adapter_unavailable', $state->reason, 'guard was masked by absent adapter: '.$mutation);
            $this->assertNull($state->intent_at);
            $this->user->update(['is_active' => true]);
            DB::table('technician_runs')->where('id', $this->run->id)->update($original);
            DB::table('technician_runs')->where('id', $this->run->id)->update(['state' => 'awaiting_approval']);
            $this->time = $this->time->setTime(0, 0);
        }
    }

    public function test_mcp_lineage_revocation_is_terminal_and_missing_provenance_refuses(): void
    {
        $token = \App\Models\McpToken::create(['label' => 'synthetic', 'token_hash' => hash('sha256', 'synthetic-only'),
            'token_prefix' => 'test', 'tools' => ['cipp_set_mailbox_forwarding:staged'], 'activated_at' => now()]);
        $this->run->update(['proposed_meta' => ['scheduled_provenance' => ['version' => 1, 'kind' => 'mcp', 'token_id' => $token->id]]]);
        $id = app(ScheduledAdmission::class)->admit($this->run->id, $this->user->id, $this->run->content_hash, $token->id,
            '2026-09-15 01:00:00', '2026-09-15 02:00:00', 'UTC', [], $this->evidence);
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $token->update(['paused_at' => now()]);
        $this->assertFalse($c->intent($id, $nonce, $this->evidence));
        $this->assertSame('authorization_changed', DB::table('scheduled_authorizations')->value('reason'));
        $this->assertSame('blocked', DB::table('scheduled_authorizations')->value('state'));
    }

    public function test_disabled_feature_and_unprivileged_admission_refuse(): void
    {
        foreach (['billing', 'contractor', 'inactive', 'missing_provenance', 'disabled', 'clock'] as $case) {
            $this->user->update(['role' => in_array($case, ['billing', 'contractor']) ? $case : 'tech', 'is_active' => $case !== 'inactive']);
            $this->run->update(['proposed_meta' => $case === 'missing_provenance' ? [] : ['scheduled_provenance' => ['version' => 1, 'kind' => 'native_human', 'user_id' => $this->user->id]]]);
            config(['scheduled_approvals.enabled' => $case !== 'disabled']);
            $this->healthy = $case !== 'clock';
            try {
                $this->admit();
                $this->fail('admitted '.$case);
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('scheduled_authorizations', 0);
            }
        }
    }

    public function test_attempt_cap_and_missing_ticket_outbox_are_explicit(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        DB::table('scheduled_authorizations')->where('id', $id)->update(['attempt' => 100]);
        $this->assertNull(app(ScheduledCoordinator::class)->claim($id));
        $this->assertSame('attempt_limit', DB::table('scheduled_authorizations')->value('reason'));
        $this->run->ticket()->first()->delete();
        $item = DB::table('scheduled_note_outbox')->value('id');
        $this->assertFalse(app(ScheduledOutbox::class)->deliver($item));
        $this->assertSame('ticket_missing', DB::table('scheduled_note_outbox')->where('id', $item)->value('delivery_error'));
    }

    public function test_conflicting_target_reservation_rolls_back_new_run_but_other_tenant_does_not_collide(): void
    {
        $first = $this->admit();
        $second = $this->run->replicate();
        $second->state = TechnicianRunState::AwaitingApproval;
        $second->content_hash = str_repeat('b', 64);
        $second->save();
        $this->run = $second;
        try {
            $this->admit();
            $this->fail('duplicate target accepted');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('scheduled_authorizations', 1);
            $this->assertDatabaseCount('scheduled_note_outbox', 1);
            $this->assertSame('awaiting_approval', $second->fresh()->state->value);
        }
        $this->evidence = new class implements ScheduledEvidence
        {
            public function approve(TechnicianRun $run, User $user, array $inputs): array
            {
                return ['payload' => ['forward' => 'synthetic@example.test'], 'target' => ['tenant_id' => 'different-tenant', 'object_id' => 'synthetic-object']];
            }

            public function revalidate(TechnicianRun $run, User $user, array $approved): array
            {
                return $approved;
            }
        };
        $this->assertNotSame($first, $this->admit());
        $this->assertDatabaseCount('scheduled_target_fences', 2);
    }

    public function test_each_durable_outcome_has_exactly_one_private_system_note(): void
    {
        foreach (['completed', 'submitted', 'uncertain'] as $outcome) {
            $id = $this->admit();
            $this->time = $this->time->setTime(1, 0);
            $c = app(ScheduledCoordinator::class);
            $nonce = $c->claim($id);
            DB::table('scheduled_authorizations')->where('id', $id)->update(['state' => 'dispatch_intent', 'intent_at' => $this->time]);
            $this->assertTrue($c->settle($id, $nonce, $outcome));
            $this->assertFalse($c->settle($id, $nonce, $outcome));
            foreach (DB::table('scheduled_note_outbox')->where('authorization_id', $id)->pluck('id') as $item) {
                $this->assertTrue(app(ScheduledOutbox::class)->deliver($item));
                $this->assertTrue(app(ScheduledOutbox::class)->deliver($item));
            }
            $notes = TicketNote::where('ticket_id', $this->run->ticket_id)->get();
            $this->assertCount(2, $notes);
            $this->assertTrue($notes->every(fn ($note) => $note->is_private && $note->note_type->value === 'system'));
            $this->assertStringContainsString(': '.$outcome.'.', $notes->last()->body);
            // Fresh synthetic target/run for next outcome; uncertain reservations are not removed.
            $client = Client::factory()->create();
            $ticket = Ticket::factory()->create(['client_id' => $client->id]);
            $next = $this->run->replicate();
            $next->ticket_id = $ticket->id;
            $next->client_id = $client->id;
            $next->state = TechnicianRunState::AwaitingApproval;
            $next->save();
            $this->run = $next;
            $this->time = $this->time->setTime(0, 0);
        }
    }

    public function test_retry_backoff_obeys_cooldown_and_never_retries_intent(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertTrue($c->defer($id, $nonce, 'cooldown', $this->time->addMinutes(10)));
        $this->assertNull($c->claim($id));
        $this->time = $this->time->addMinutes(9);
        $this->assertNull($c->claim($id));
        $this->time = $this->time->addMinute();
        $next = $c->claim($id);
        $this->assertNotNull($next);
        $this->assertFalse($c->defer($id, $nonce, 'offline'));
        DB::table('scheduled_authorizations')->where('id', $id)->update(['state' => 'dispatch_intent', 'intent_at' => $this->time]);
        $this->assertFalse($c->defer($id, $next, 'read_unavailable'));
        $this->assertDatabaseCount('scheduled_note_outbox', 2);
    }

    public function test_clock_threshold_is_fixed_and_conservative(): void
    {
        $this->assertTrue(ScheduledClock::withinThreshold(100, 98, 102));
        $this->assertFalse(ScheduledClock::withinThreshold(100, 97.999, 100));
        $this->assertFalse(ScheduledClock::withinThreshold(100, 100, 102.001));
        $this->assertFalse(ScheduledClock::withinThreshold(100, 101, 99));
    }

    public function test_retention_is_terminal_plus_thirty_days(): void
    {
        $id = $this->admit();
        $outbox = app(ScheduledOutbox::class);
        $this->assertSame(0, $outbox->purge());
        app(ScheduledCoordinator::class)->cancel($id, $this->user->id);
        $digest = DB::table('scheduled_authorizations')->value('digest');
        $this->time = $this->time->addDays(30)->subSecond();
        $this->assertSame(0, $outbox->purge());
        $this->time = $this->time->addSecond();
        $this->assertSame(1, $outbox->purge());
        $this->assertNull(DB::table('scheduled_authorizations')->value('ciphertext'));
        $this->assertSame($digest, DB::table('scheduled_authorizations')->value('digest'));
    }
}
