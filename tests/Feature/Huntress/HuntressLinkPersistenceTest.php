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

    public function test_conflicting_candidates_are_never_promoted(): void
    {
        $this->event();
        $pair = $this->pair();
        $this->capture($pair, 'incident_reports/9182 https://synthetic.huntress.io/org/42/escalations/9182');
        $this->assertNull($pair[0]->fresh()->huntress_event_id);
        $this->assertSame('link_conflict', $pair[0]->fresh()->huntress_link_refusal);
    }
}
