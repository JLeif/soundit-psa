<?php

namespace Tests\Feature\Huntress;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HuntressSignedWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-webhook-test-key-only';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('huntress_webhooks_enabled', '1');
        Setting::setEncrypted('huntress_webhook_signing_secret', 'whsec_'.base64_encode(self::KEY));
    }

    private function payload(string $type = 'incident_report.created'): array
    {
        $p = [
            'event_type' => $type, 'id' => 9182, 'account' => ['id' => 11, 'name' => null],
            'created_at' => '2026-09-14T12:00:00Z', 'updated_at' => '2026-09-14T12:00:00Z',
            'api_url' => 'https://api.example.test/records/9182', 'status' => null,
            'subject' => null, 'severity' => null,
        ];
        if (str_starts_with($type, 'incident_report.')) {
            return $p + ['organization' => ['id' => 42, 'name' => null], 'sent_at' => null,
                'closed_at' => null, 'agent_id' => null, 'body' => '', 'summary' => null,
                'indicator_counts' => new \stdClass, 'platform' => 'windows', 'status_updated_at' => null];
        }

        return array_replace($p, ['organizations' => [['id' => 42, 'name' => 'Synthetic']],
            'resolved_at' => null, 'total_organizations_impacted' => 1, 'type' => 'incident',
            'status' => 'open', 'subject' => 'Synthetic', 'severity' => 'high']);
    }

    private function signedHeaders(string $body, string $id, string $family, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, self::KEY, true));
        $prefix = 'HTTP_'.strtoupper($family).'_';

        return [$prefix.'ID' => $id, $prefix.'TIMESTAMP' => (string) $timestamp, $prefix.'SIGNATURE' => 'v1,'.$signature];
    }

    private function deliverHeaders(string $body, array $headers)
    {
        return $this->call('POST', '/api/huntress/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ] + $headers, $body);
    }

    private function deliver(string $body, string $id = 'msg_synthetic', ?int $timestamp = null, ?string $signedBody = null, string $family = 'svix')
    {
        return $this->deliverHeaders($body, $this->signedHeaders($signedBody ?? $body, $id, $family, $timestamp));
    }

    public function test_both_header_families_verify_and_bind_the_persisted_delivery(): void
    {
        $body = json_encode($this->payload());
        foreach (['svix', 'webhook'] as $family) {
            $id = 'msg_'.$family;
            $this->deliver($body, $id, family: $family)->assertOk();
            $this->deliver($body, $id, family: $family)->assertOk();
            $this->assertDatabaseHas('huntress_webhook_events', ['delivery_id' => $id, 'record_id' => 9182]);
            $p = $this->payload();
            $p['id'] = 9183;
            $this->deliver(json_encode($p), $id, family: $family)->assertStatus(409);
        }
        $this->assertDatabaseCount('huntress_webhook_events', 2);
    }

    public function test_both_families_reject_tampering_expiry_and_invalid_delivery_ids(): void
    {
        $body = json_encode($this->payload());
        foreach (['svix', 'webhook'] as $family) {
            $this->deliver($body.' ', signedBody: $body, family: $family)->assertStatus(401);
            $this->deliver($body, timestamp: time() - 600, family: $family)->assertStatus(401);
            $this->deliver($body, timestamp: time() + 600, family: $family)->assertStatus(401);
            foreach (['', 'invalid id', str_repeat('a', 256)] as $id) {
                $this->deliver($body, $id, family: $family)->assertStatus(400)->assertExactJson(['error' => 'Invalid delivery']);
            }
        }
        $this->deliverHeaders($body, [])->assertStatus(400)->assertExactJson(['error' => 'Invalid delivery']);
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_vendor_complete_family_precedence_and_partial_svix_fallback(): void
    {
        $body = json_encode($this->payload());
        $svix = $this->signedHeaders($body, 'msg_svix', 'svix');
        $webhook = $this->signedHeaders($body, 'msg_webhook', 'webhook');
        $this->deliverHeaders($body, $svix + $webhook)->assertOk();
        $this->assertDatabaseHas('huntress_webhook_events', ['delivery_id' => 'msg_svix']);
        $this->assertDatabaseMissing('huntress_webhook_events', ['delivery_id' => 'msg_webhook']);
        // A present but incomplete svix family must not mask webhook headers.
        unset($svix['HTTP_SVIX_SIGNATURE']);
        $this->deliverHeaders($body, $svix + $webhook)->assertOk();
        $this->assertDatabaseHas('huntress_webhook_events', ['delivery_id' => 'msg_webhook']);
        $this->assertDatabaseCount('huntress_webhook_events', 2);
    }

    public function test_invalid_complete_svix_does_not_fall_back_and_mixed_fields_cannot_verify(): void
    {
        $body = json_encode($this->payload());
        $svix = $this->signedHeaders($body, 'msg_svix', 'svix');
        $webhook = $this->signedHeaders($body, 'msg_webhook', 'webhook');
        foreach (['v1,invalid', ''] as $signature) {
            $svix['HTTP_SVIX_SIGNATURE'] = $signature;
            $this->deliverHeaders($body, $svix + $webhook)->assertStatus(401);
        }
        $mixed = $this->signedHeaders($body, 'msg_webhook', 'webhook');
        $mixed['HTTP_SVIX_SIGNATURE'] = $mixed['HTTP_WEBHOOK_SIGNATURE'];
        unset($mixed['HTTP_WEBHOOK_SIGNATURE']);
        $this->deliverHeaders($body, $mixed)->assertStatus(401);
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_real_cw_ingest_and_signed_arrival_link_in_both_orders(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        // Exercise the real ingest anchor with a non-UTC display timezone.
        config(['app.timezone' => 'America/Los_Angeles']);
        // Laravel sets PHP's timezone during bootstrap; changing config alone
        // after bootstrap would leave now() in UTC and make this guard vacuous.
        $originalTimezone = date_default_timezone_get();
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($originalTimezone));
        date_default_timezone_set('America/Los_Angeles');
        $this->travelTo(\Carbon\Carbon::parse('2026-09-14T05:01:00-07:00'));
        $user = \App\Models\User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $user->id);
        Setting::setValue('huntress_webhook_account_id', '11');
        $client = \App\Models\Client::factory()->create(['huntress_organization_id' => 42, 'is_active' => true]);
        foreach ([false, true] as $eventFirst) {
            $id = $eventFirst ? 9183 : 9182;
            $payload = $this->payload();
            $payload['id'] = $id;
            if ($eventFirst) {
                $this->deliver(json_encode($payload), 'msg_'.$id)->assertOk();
            }
            $result = app(\App\Services\Huntress\HuntressService::class)->createTicketFromCw([
                'summary' => 'HIGH - Incident on SYNTHETIC (Synthetic)',
                'initialDescription' => 'https://synthetic.huntress.io/org/42/incident_reports/'.$id,
                'company' => ['id' => $client->id],
            ]);
            $alert = \App\Models\Alert::where('ticket_id', $result['id'])->firstOrFail();
            $this->assertSame('2026-09-14 12:01:00', DB::table('huntress_link_candidates')
                ->where('ticket_id', $result['id'])->value('received_at'));
            if (! $eventFirst) {
                $this->assertNull($alert->huntress_event_id);
                $this->deliver(json_encode($payload), 'msg_'.$id)->assertOk();
            }
            $this->assertEquals($id, $alert->fresh()->huntress_record_id);
            $this->assertEquals(42, $alert->fresh()->huntress_org_id);
            $this->deliver(json_encode($payload), 'msg_'.$id)->assertOk();
            $this->assertEquals($id, $alert->fresh()->huntress_record_id);
        }
        $this->assertDatabaseCount('huntress_link_candidates', 2);
        $this->assertDatabaseCount('huntress_webhook_events', 2);
        $this->travelBack();
    }

    public function test_cw_capture_failure_rolls_back_ticket_alert_and_notes(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = \App\Models\User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $user->id);
        $client = \App\Models\Client::factory()->create(['huntress_organization_id' => 42]);
        $this->mock(\App\Services\Huntress\HuntressLinkService::class, function ($mock) {
            $mock->shouldReceive('capture')->once()->andThrow(new \RuntimeException('synthetic capture interruption'));
        });
        try {
            app(\App\Services\Huntress\HuntressService::class)->createTicketFromCw([
                'summary' => 'HIGH - Incident on SYNTHETIC (Synthetic)',
                'initialDescription' => 'https://synthetic.huntress.io/org/42/incident_reports/9182',
                'company' => ['id' => $client->id],
            ]);
            $this->fail('Capture interruption must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic capture interruption', $e->getMessage());
        }
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('huntress_link_candidates', 0);
    }

    public function test_valid_event_is_committed_once_and_conflicting_replay_refuses(): void
    {
        $body = json_encode($this->payload());
        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk();
        $this->assertDatabaseCount('huntress_webhook_events', 1);
        $p = $this->payload();
        $p['id'] = 9183;
        $this->deliver(json_encode($p))->assertStatus(409);
        $this->assertDatabaseHas('huntress_webhook_events', ['record_id' => 9182, 'resolved' => false]);
    }

    public function test_raw_byte_change_and_stale_or_future_signatures_write_nothing(): void
    {
        $body = json_encode($this->payload());
        $this->deliver($body.' ', signedBody: $body)->assertStatus(401);
        $this->deliver($body, timestamp: time() - 600)->assertStatus(401);
        $this->deliver($body, timestamp: time() + 600)->assertStatus(401);
        $this->postJson('/api/huntress/webhooks', $this->payload())->assertStatus(400);
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_actual_webhook_route_enforces_120_requests_per_minute_even_dark(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/huntress/webhooks', 'POST'));
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
        Setting::setValue('huntress_webhooks_enabled', '0');
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/huntress/webhooks', [])->assertStatus(503)
                ->assertHeader('X-RateLimit-Limit', '120');
        }
        $this->postJson('/api/huntress/webhooks', [])->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->assertDatabaseCount('huntress_webhook_events', 0);
        $this->travel(61)->seconds();
        $this->postJson('/api/huntress/webhooks', [])->assertStatus(503);
        $this->travelBack();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_default_off_and_missing_secret_refuse(): void
    {
        Setting::where('key', 'huntress_webhooks_enabled')->delete();
        $this->deliver(json_encode($this->payload()))->assertStatus(503);
        Setting::setValue('huntress_webhooks_enabled', '1');
        Setting::where('key', 'huntress_webhook_signing_secret')->delete();
        $this->deliver(json_encode($this->payload()))->assertStatus(503);
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_signed_malformed_identity_and_json_refuse_before_writes(): void
    {
        foreach (['not json', '[]', 'null', '{"event_type":4}'] as $body) {
            $this->deliver($body)->assertStatus(422);
        }
        foreach ([null, 0, -1, 1.2, '9182', true, '18446744073709551616'] as $id) {
            $p = $this->payload();
            $p['id'] = $id;
            $this->deliver(json_encode($p))->assertStatus(422);
        }
        $body = str_replace('9182', '18446744073709551616', json_encode($this->payload()));
        $this->deliver($body)->assertStatus(422);
        foreach (['tomorrow', '2026-02-30T12:00:00Z', null] as $date) {
            $p = $this->payload();
            $p['created_at'] = $date;
            $this->deliver(json_encode($p))->assertStatus(422);
        }
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_nullable_scope_is_preserved_not_filled_from_deprecated_aliases(): void
    {
        $p = $this->payload();
        $p['account']['id'] = null;
        $p['organization']['id'] = null;
        $p['account_id'] = 11;
        $p['organization_id'] = 42;
        $this->deliver(json_encode($p))->assertOk();
        $this->assertDatabaseHas('huntress_webhook_events', ['account_id' => null, 'organization_ids' => '[]']);
    }

    public function test_incident_sent_time_and_closed_state_and_multi_org_escalation(): void
    {
        $p = $this->payload('incident_report.closed');
        $p['sent_at'] = '2026-09-14T12:02:00Z';
        $this->deliver(json_encode($p))->assertOk();
        $row = DB::table('huntress_webhook_events')->first();
        $this->assertSame('2026-09-14 12:02:00.000000', $row->correlation_at);
        $this->assertEquals(1, $row->resolved);
        $p = $this->payload('escalation.created');
        $p['organizations'][] = ['id' => 43, 'name' => 'Synthetic second'];
        $p['resolved_at'] = '2026-09-14T13:00:00Z';
        $this->deliver(json_encode($p), 'msg_second')->assertOk();
        $this->assertDatabaseHas('huntress_webhook_events', ['record_type' => 'escalation', 'organization_ids' => '[42,43]', 'resolved' => true]);
    }

    public function test_persistence_failure_is_not_acknowledged(): void
    {
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('synthetic storage failure'));
        $this->deliver(json_encode($this->payload()))->assertStatus(500);
    }

    public function test_malformed_scope_and_secret_refuse(): void
    {
        foreach (['account', 'organization'] as $field) {
            foreach ([[], null, ['id' => '42'], ['id' => -1], ['id' => 1.5]] as $value) {
                $p = $this->payload();
                $p[$field] = $value;
                $this->deliver(json_encode($p))->assertStatus(422);
            }
        }
        foreach ([null, new \stdClass, [['id' => null]], [['id' => '42']]] as $value) {
            $p = $this->payload('escalation.created');
            $p['organizations'] = $value;
            $this->deliver(json_encode($p))->assertStatus(422);
        }
        Setting::setEncrypted('huntress_webhook_signing_secret', 'whsec_%%%');
        $this->deliver(json_encode($this->payload()))->assertStatus(503);
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }

    public function test_verified_unhandled_events_never_persist(): void
    {
        foreach (['incident_report.comment_added', 'escalation.overdue', 'test'] as $type) {
            $this->deliver(json_encode(['event_type' => $type]))->assertOk();
        }
        $this->assertDatabaseCount('huntress_webhook_events', 0);
    }
}
