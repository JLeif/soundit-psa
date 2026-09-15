<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\TicketSource;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Huntress\HuntressLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HuntressLinkPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::setValue('huntress_webhooks_enabled', '1');
        Setting::setValue('huntress_webhook_account_id', '11');
    }

    private function pair(?Client $client = null): array
    {
        $client ??= Client::where('huntress_organization_id', 42)->first() ?? Client::factory()->create(['huntress_organization_id' => 42]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'source' => TicketSource::Huntress]);
        $alert = Alert::create(['source' => AlertSource::Huntress, 'source_alert_id' => 'synthetic-'.$ticket->id,
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'title' => 'Synthetic',
            'status' => AlertStatus::Ticketed, 'severity' => 'critical', 'fired_at' => now()]);

        return [$alert, $ticket];
    }

    private function event(string $type = 'incident_report', array $overrides = []): int
    {
        return DB::table('huntress_webhook_events')->insertGetId(array_replace([
            'delivery_id' => 'synthetic-'.DB::table('huntress_webhook_events')->count(),
            'content_hash' => str_repeat('a', 64), 'event_type' => $type.'.created',
            'record_type' => $type, 'record_id' => 9182, 'account_id' => 11,
            'organization_ids' => '[42]', 'agent_id' => null, 'resolved' => false,
            'record_created_at' => '2026-09-14 12:00:00', 'correlation_at' => '2026-09-14 12:00:00',
            'received_at' => now(),
        ], $overrides));
    }

    private function capture(array $pair, string $path = 'incident_reports/9182', int $org = 42): void
    {
        app(HuntressLinkService::class)->capture($pair[0], $pair[1],
            'https://synthetic.huntress.io/org/'.$org.'/'.$path, '2026-09-14 12:01:00');
    }

    public function test_event_history_is_bounded_and_repair_reaches_evidence_after_the_first_page(): void
    {
        foreach (['incident_report', 'escalation'] as $type) {
            for ($i = 0; $i < 201; $i++) {
                $this->event($type, ['account_id' => 12]);
            }
            $valid = $this->event($type);
            $pair = $this->pair();
            $path = $type === 'escalation' ? 'escalations/9182' : 'incident_reports/9182';
            DB::enableQueryLog();
            $this->capture($pair, $path);
            $history = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'select * from "huntress_webhook_events"'));
            DB::disableQueryLog();
            DB::flushQueryLog();
            $this->assertCount(1, $history);
            $this->assertStringContainsString('limit 101', $history->first()['query']);
            $this->assertNull($pair[0]->fresh()->huntress_event_id);
            $this->assertSame('event_history_pending', $pair[0]->fresh()->huntress_link_refusal);
            $cursor = DB::table('huntress_link_candidates')->where('alert_id', $pair[0]->id)->value('event_scan_after_id');
            $this->assertGreaterThan(0, $cursor);
            app(HuntressLinkService::class)->promote($type, 9182);
            $this->assertNull($pair[0]->fresh()->huntress_event_id);
            $this->assertGreaterThan($cursor, DB::table('huntress_link_candidates')->where('alert_id', $pair[0]->id)->value('event_scan_after_id'));
            app(HuntressLinkService::class)->promote($type, 9182);
            $this->assertEquals($valid, $pair[0]->fresh()->huntress_event_id);
            // The valid binding is past page one; a later repair must not revoke it.
            app(HuntressLinkService::class)->promote($type, 9182);
            $this->assertEquals($valid, $pair[0]->fresh()->huntress_event_id);
            $this->assertSame(0, DB::table('huntress_link_candidates')->where('alert_id', $pair[0]->id)->value('event_scan_after_id'));
        }
    }

    public function test_history_cursor_and_link_roll_back_together_and_new_arrival_is_not_lost(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->event(overrides: ['account_id' => 12]);
        }
        $pair = $this->pair();
        $this->capture($pair);
        $cursor = DB::table('huntress_link_candidates')->value('event_scan_after_id');
        $this->assertGreaterThan(0, $cursor);
        DB::beginTransaction();
        app(HuntressLinkService::class)->promote('incident_report', 9182);
        $this->assertSame(0, DB::table('huntress_link_candidates')->value('event_scan_after_id'));
        DB::rollBack();
        $this->assertEquals($cursor, DB::table('huntress_link_candidates')->value('event_scan_after_id'));
        $valid = $this->event();
        app(HuntressLinkService::class)->promote('incident_report', 9182);
        $this->assertEquals($valid, $pair[0]->fresh()->huntress_event_id);
        // A new competitor must revoke synchronously, regardless of history cursor.
        $other = $this->pair($pair[1]->client);
        $this->capture($other);
        foreach ([$pair, $other] as $p) {
            $this->assertNull($p[0]->fresh()->huntress_event_id);
            $this->assertSame('ambiguous_binding', $p[0]->fresh()->huntress_link_refusal);
        }
    }

    public function test_completed_history_wraps_to_recheck_changed_client_scope(): void
    {
        $valid = $this->event();
        $pair = $this->pair();
        $this->capture($pair);
        $pair[1]->client->update(['huntress_organization_id' => 43]);
        app(HuntressLinkService::class)->promote('incident_report', 9182);
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $pair[1]->client->update(['huntress_organization_id' => 42]);
        app(HuntressLinkService::class)->promote('incident_report', 9182);
        $this->assertEquals($valid, $pair[0]->fresh()->huntress_event_id);
    }

    public function test_both_arrival_orders_promote_once_and_capture_is_immutable(): void
    {
        foreach (['incident_report', 'escalation'] as $type) {
            $pair = $this->pair();
            $path = $type === 'escalation' ? 'escalations/9182' : 'incident_reports/9182';
            if ($type === 'incident_report') {
                $this->capture($pair, $path);
                $this->assertNull($pair[0]->fresh()->huntress_event_id);
                $id = $this->event($type);
                app(HuntressLinkService::class)->promote();
            } else {
                $id = $this->event($type);
                $this->capture($pair, $path);
            }
            $this->assertEquals($id, $pair[0]->fresh()->huntress_event_id);
            $linkedAt = $pair[0]->fresh()->huntress_linked_at;
            $this->capture($pair, 'incident_reports/9999');
            app(HuntressLinkService::class)->promote();
            $this->assertEquals(9182, $pair[0]->fresh()->huntress_record_id);
            $this->assertEquals($linkedAt, $pair[0]->fresh()->huntress_linked_at);
        }
        $this->assertDatabaseCount('huntress_link_candidates', 2);
    }

    public function test_late_duplicate_revokes_first_link_and_never_selects_a_winner(): void
    {
        $this->event();
        $a = $this->pair();
        $this->capture($a);
        $this->assertNotNull($a[0]->fresh()->huntress_event_id);
        $b = $this->pair($a[1]->client);
        $this->capture($b);
        foreach ([$a, $b] as $pair) {
            $this->assertNull($pair[0]->fresh()->huntress_event_id);
            $this->assertSame('ambiguous_binding', $pair[0]->fresh()->huntress_link_refusal);
        }
        app(HuntressLinkService::class)->promote();
        $this->assertSame(0, DB::table('alerts')->whereNotNull('huntress_event_id')->count());
    }

    public function test_multi_org_escalation_can_link_once_per_org(): void
    {
        $this->event('escalation', ['organization_ids' => '[42,43]']);
        foreach ([42, 43] as $org) {
            $pair = $this->pair(Client::factory()->create(['huntress_organization_id' => $org]));
            $this->capture($pair, 'escalations/9182', $org);
            $this->assertEquals($org, $pair[0]->fresh()->huntress_org_id);
        }
    }

    public function test_same_org_wrong_record_and_changed_client_mapping_refuse(): void
    {
        $this->event();
        $pair = $this->pair();
        $this->capture($pair, 'incident_reports/9183');
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $this->assertSame('unvalidated_candidate', $pair[0]->fresh()->huntress_link_refusal);
        $bound = $this->pair();
        $this->capture($bound);
        $this->assertNotNull($bound[0]->fresh()->huntress_event_id);
        $bound[1]->client->update(['huntress_organization_id' => 43]);
        app(HuntressLinkService::class)->promote();
        $this->assertNull($bound[0]->fresh()->huntress_event_id);
        $this->assertSame('scope_or_identity_mismatch', $bound[0]->fresh()->huntress_link_refusal);
    }

    public function test_bound_incident_and_escalation_resolve_once_by_exact_id(): void
    {
        \App\Models\User::factory()->create();
        foreach (['incident_report', 'escalation'] as $type) {
            $this->event($type);
            $pair = $this->pair();
            $pair[1]->update(['status' => \App\Enums\TicketStatus::New]);
            $this->capture($pair, $type === 'escalation' ? 'escalations/9182' : 'incident_reports/9182');
            $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
            $client->shouldReceive('getIncidentReports', 'getEscalations')->never();
            $client->shouldReceive($type === 'escalation' ? 'getEscalation' : 'getIncidentReport')
                ->once()->with(9182)->andReturn(['id' => 9182, 'organization_id' => '42',
                    'organizations' => [['id' => '42']], 'status' => $type === 'escalation' ? 'resolved' : 'closed']);
            $class = $type === 'escalation'
                ? \App\Services\Huntress\HuntressEscalationReconcileService::class
                : \App\Services\Huntress\HuntressIncidentReconcileService::class;
            $service = new $class($client, app(\App\Services\TicketService::class), app(\App\Services\AlertService::class));
            $this->assertSame(1, $service->reconcile()->updated);
            $this->assertSame(\App\Enums\TicketStatus::Resolved, $pair[1]->fresh()->status);
            $this->assertSame(0, $service->reconcile()->updated);
        }
    }

    public function test_bound_ticket_with_human_touch_is_not_resolved(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->event();
        $pair = $this->pair();
        $pair[1]->update(['status' => \App\Enums\TicketStatus::New]);
        $this->capture($pair);
        \App\Models\TicketNote::create(['ticket_id' => $pair[1]->id, 'author_id' => $user->id,
            'body' => 'Synthetic human reply', 'note_type' => \App\Enums\NoteType::StatusChange,
            'who_type' => \App\Enums\WhoType::EndUser, 'noted_at' => now()]);
        $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $client->shouldReceive('getIncidentReport')->once()->with(9182)
            ->andReturn(['id' => 9182, 'organization_id' => 42, 'status' => 'closed']);
        $result = (new \App\Services\Huntress\HuntressIncidentReconcileService($client,
            app(\App\Services\TicketService::class), app(\App\Services\AlertService::class)))->reconcile();
        $this->assertSame(0, $result->updated);
        $this->assertSame(["#{$pair[1]->id}: human_touched"], $result->skippedMessages);
        $this->assertSame(\App\Enums\TicketStatus::New, $pair[1]->fresh()->status);
    }

    public function test_polling_repairs_a_committed_event_without_another_delivery(): void
    {
        \App\Models\User::factory()->create();
        $pair = $this->pair();
        $pair[1]->update(['status' => \App\Enums\TicketStatus::New]);
        $this->capture($pair);
        $this->event(); // Simulate interruption after event commit, before promotion.
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $client->shouldReceive('getIncidentReport')->once()->with(9182)
            ->andReturn(['id' => 9182, 'organization_id' => 42, 'status' => 'closed']);
        $result = (new \App\Services\Huntress\HuntressIncidentReconcileService($client,
            app(\App\Services\TicketService::class), app(\App\Services\AlertService::class)))->reconcile();
        $this->assertSame(1, $result->updated);
        $this->assertNotNull($pair[0]->fresh()->huntress_event_id);
        $this->assertSame(\App\Enums\TicketStatus::Resolved, $pair[1]->fresh()->status);
    }

    public function test_old_text_and_metadata_without_a_signed_link_never_fetch(): void
    {
        $pair = $this->pair();
        $pair[1]->update(['status' => \App\Enums\TicketStatus::New,
            'subject' => 'Huntress Escalation Incident on synthetic',
            'description' => 'https://synthetic.huntress.io/org/42/incident_reports/9182 escalations/9182']);
        $pair[0]->update(['metadata' => ['escalation_id' => 9182]]);
        $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $client->shouldReceive('getIncidentReport', 'getIncidentReports', 'getEscalation', 'getEscalations')->never();
        foreach ([\App\Services\Huntress\HuntressIncidentReconcileService::class,
            \App\Services\Huntress\HuntressEscalationReconcileService::class] as $class) {
            $result = (new $class($client, app(\App\Services\TicketService::class), app(\App\Services\AlertService::class)))->reconcile();
            $this->assertSame(0, $result->updated);
            $this->assertSame(1, $result->skipped);
        }
        $this->assertSame(\App\Enums\TicketStatus::New, $pair[1]->fresh()->status);
    }

    public function test_upstream_mismatch_fetch_failure_and_live_remap_refuse(): void
    {
        $this->event();
        $pair = $this->pair();
        $pair[1]->update(['status' => \App\Enums\TicketStatus::New]);
        $this->capture($pair);
        foreach (['wrong_id', 'wrong_org', 'fetch_failed', 'live_remap'] as $case) {
            $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
            $call = $client->shouldReceive('getIncidentReport')->once()->with(9182);
            if ($case === 'fetch_failed') {
                $call->andThrow(new \RuntimeException('synthetic outage'));
            } elseif ($case === 'live_remap') {
                $call->andReturnUsing(function () use ($pair) {
                    $pair[1]->client->update(['huntress_organization_id' => 43]);

                    return ['id' => 9182, 'organization_id' => 42, 'status' => 'closed'];
                });
            } else {
                $call->andReturn(['id' => $case === 'wrong_id' ? 9183 : 9182,
                    'organization_id' => $case === 'wrong_org' ? 43 : 42, 'status' => 'closed']);
            }
            $result = (new \App\Services\Huntress\HuntressIncidentReconcileService($client,
                app(\App\Services\TicketService::class), app(\App\Services\AlertService::class)))->reconcile();
            $this->assertSame(0, $result->updated, $case);
            $this->assertSame($case === 'fetch_failed' ? 1 : 0, $result->errors, $case);
            $this->assertSame(\App\Enums\TicketStatus::New, $pair[1]->fresh()->status, $case);
        }
    }

    public function test_deleted_ticket_or_candidate_does_not_wedge_replacement_link(): void
    {
        foreach (['ticket', 'candidate'] as $deleted) {
            $type = $deleted === 'ticket' ? 'incident_report' : 'escalation';
            $path = $deleted === 'ticket' ? 'incident_reports/9182' : 'escalations/9182';
            $event = $this->event($type);
            $old = $this->pair();
            $this->capture($old, $path);
            $this->assertEquals($event, $old[0]->fresh()->huntress_event_id);
            if ($deleted === 'ticket') {
                $old[1]->delete();
            } else {
                DB::table('huntress_link_candidates')->where('ticket_id', $old[1]->id)->delete();
            }
            $replacement = $this->pair();
            $this->capture($replacement, $path);
            $this->assertNull($old[0]->fresh()->huntress_event_id);
            $this->assertSame('orphaned_link', $old[0]->fresh()->huntress_link_refusal);
            $this->assertEquals($event, $replacement[0]->fresh()->huntress_event_id);
            app(HuntressLinkService::class)->promote($type, 9182);
            $this->assertEquals($event, $replacement[0]->fresh()->huntress_event_id);
        }
    }

    public function test_arrival_is_record_scoped_and_replay_does_not_write_unchanged_alerts(): void
    {
        $this->event();
        $linked = $this->pair();
        $this->capture($linked);
        $other = $this->pair();
        $this->capture($other, 'incident_reports/9999');
        // A scoped event must not even read unrelated candidate rows.
        DB::enableQueryLog();
        app(HuntressLinkService::class)->promote('incident_report', 9182);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $updates = array_filter($queries, fn ($q) => str_starts_with($q['query'], 'update "alerts"')
            && ! str_contains($q['query'], 'not exists'));
        $this->assertCount(0, $updates, 'Replay must skip unchanged alert writes');
        $candidateReads = array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from "huntress_link_candidates"'));
        $this->assertNotEmpty($candidateReads);
        foreach ($candidateReads as $query) {
            $this->assertTrue(str_contains($query['query'], '"record_id" = ?') || str_contains($query['query'], '"id" in ('), $query['query']);
        }
        $this->assertSame('unvalidated_candidate', $other[0]->fresh()->huntress_link_refusal);
        $this->assertNotNull($linked[0]->fresh()->huntress_event_id);
    }

    public function test_polling_repair_clears_an_orphan_without_a_replacement_candidate(): void
    {
        $this->event();
        $pair = $this->pair();
        $this->capture($pair);
        DB::table('huntress_link_candidates')->where('ticket_id', $pair[1]->id)->delete();
        app(HuntressLinkService::class)->promote();
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $this->assertNull($pair[0]->fresh()->huntress_record_id);
    }

    public function test_capture_does_not_rescan_all_unlinked_competitors(): void
    {
        $first = $this->pair();
        $this->capture($first);
        $second = $this->pair();
        $this->capture($second);
        $third = $this->pair();
        DB::enableQueryLog();
        $this->capture($third);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        // No signed event exists, hence every competitor is already dark. Only
        // the new candidate needs loading; repeated COUNT scans are bounded too.
        $alertReads = array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from "alerts"'));
        $this->assertCount(1, $alertReads);
        $this->assertNull($third[0]->fresh()->huntress_event_id);
        $this->assertSame('ambiguous_binding', $third[0]->fresh()->huntress_link_refusal);
    }

    public static function authorityCases(): array
    {
        $cases = [];
        foreach (['incident_report', 'escalation'] as $type) {
            foreach (['account', 'null_account', 'empty_org', 'foreign_org', 'null_mapping', 'dark', 'late_duplicate'] as $case) {
                $cases[$type.'_'.$case] = [$type, $case];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authorityCases')]
    public function test_both_types_refuse_unproven_authority(string $type, string $case): void
    {
        $overrides = match ($case) {
            'account' => ['account_id' => 12],
            'null_account' => ['account_id' => null],
            'empty_org' => ['organization_ids' => '[]'],
            'foreign_org' => ['organization_ids' => '[43]'],
            default => [],
        };
        $this->event($type, $overrides);
        $pair = $this->pair();
        $pair[1]->update(['status' => \App\Enums\TicketStatus::New]);
        if ($case === 'null_mapping') {
            $pair[1]->client->update(['huntress_organization_id' => null]);
            $pair[1]->unsetRelation('client');
        }
        if ($case === 'dark') {
            Setting::setValue('huntress_webhooks_enabled', '0');
        }
        $path = $type === 'escalation' ? 'escalations/9182' : 'incident_reports/9182';
        $this->capture($pair, $path);
        if ($case === 'late_duplicate') {
            $this->assertNotNull($pair[0]->fresh()->huntress_event_id);
            $other = $this->pair();
            $this->capture($other, $path);
            $this->assertNull($other[0]->fresh()->huntress_event_id);
        }
        $this->assertNull($pair[0]->fresh()->huntress_event_id, $case);
        $client = \Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $client->shouldReceive('getIncidentReport', 'getIncidentReports', 'getEscalation', 'getEscalations')->never();
        $class = $type === 'escalation'
            ? \App\Services\Huntress\HuntressEscalationReconcileService::class
            : \App\Services\Huntress\HuntressIncidentReconcileService::class;
        $result = (new $class($client, app(\App\Services\TicketService::class), app(\App\Services\AlertService::class)))->reconcile();
        $this->assertSame(0, $result->checked);
        $this->assertSame(0, $result->updated);
        $this->assertSame(\App\Enums\TicketStatus::New, $pair[1]->fresh()->status);
    }

    public function test_conflicting_candidates_are_never_promoted(): void
    {
        $this->event();
        $pair = $this->pair();
        $this->capture($pair, 'incident_reports/9182 https://synthetic.huntress.io/org/42/escalations/9182');
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $this->assertSame('link_conflict', $pair[0]->fresh()->huntress_link_refusal);
    }
}
