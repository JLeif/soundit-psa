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

    private function deliver(string $body, string $id = 'msg_synthetic', ?int $timestamp = null, ?string $signedBody = null)
    {
        $timestamp ??= time();
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.($signedBody ?? $body), self::KEY, true));

        return $this->call('POST', '/api/huntress/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
        ], $body);
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
