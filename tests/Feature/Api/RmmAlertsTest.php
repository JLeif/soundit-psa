<?php

namespace Tests\Feature\Api;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/rmm/alerts and /api/rmm/alerts/resolve — the write surface Leif RMM
 * uses to report coverage drift.
 *
 * The RMM watches whether each client's machines still have what that client is
 * supposed to have (Huntress, Control D, our own agent). When a machine loses
 * coverage, something has to say so. These two endpoints are that channel, and
 * they deliberately raise ALERTS rather than tickets: the PSA's alert pipeline
 * already dedupes on source + source_alert_id, counts re-fires, and can be
 * promoted to a ticket by a human from the alerts screen. A ticket is a
 * commitment to bill and to work; an automatic one per transition is how a queue
 * becomes noise.
 *
 * The refusals matter as much as the happy path. An alert filed against the
 * wrong client is worse than no alert at all.
 */
class RmmAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leif_rmm_is_a_known_alert_source(): void
    {
        $this->assertSame('leif_rmm', AlertSource::LeifRmm->value);
        $this->assertSame('Leif RMM', AlertSource::LeifRmm->label());
    }

    private const KEY = 'rmm-test-key-that-is-suitably-long';

    private function configure(): void
    {
        Setting::setEncrypted('rmm_api_key', self::KEY);
    }

    private function authed(): array
    {
        return ['Authorization' => 'Bearer '.self::KEY];
    }

    /** The payload the RMM actually sends. Overrides replace top-level keys. */
    private function payload(int $clientId, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $clientId,
            'source_alert_id' => $clientId.':huntress',
            'severity' => 'warning',
            'title' => 'Huntress coverage changed',
            'message' => '1 device lost Huntress coverage.',
            'hostname' => 'ACME-LT-01',
            'metadata' => [
                'requirement_key' => 'huntress',
                'devices' => [
                    ['hostname' => 'ACME-LT-01', 'from' => 'ok', 'to' => 'not_enrolled'],
                ],
            ],
            'fired_at' => '2026-09-16T01:00:00.000Z',
        ], $overrides);
    }

    // -- authentication -------------------------------------------------------

    public function test_refuses_without_a_key(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $this->postJson('/api/rmm/alerts', $this->payload($client->id))
            ->assertStatus(401);

        $this->assertSame(0, Alert::count());
    }

    public function test_refuses_a_wrong_key(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $this->postJson('/api/rmm/alerts', $this->payload($client->id), ['Authorization' => 'Bearer wrong-key-entirely'])
            ->assertStatus(401);

        $this->assertSame(0, Alert::count());
    }

    // -- raising --------------------------------------------------------------

    public function test_raises_an_alert_against_the_named_client(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $response = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())
            ->assertOk()
            ->assertJson(['created' => true, 'status' => 'active', 'refired_count' => 0]);

        $alert = Alert::findOrFail($response->json('alert_id'));
        $this->assertSame($client->id, $alert->client_id);
        $this->assertSame(AlertSource::LeifRmm, $alert->source);
        $this->assertSame($client->id.':huntress', $alert->source_alert_id);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame('Huntress coverage changed', $alert->title);
        $this->assertSame('ACME-LT-01', $alert->hostname);
        $this->assertSame('huntress', $alert->metadata['requirement_key']);
        $this->assertCount(1, $alert->metadata['devices']);
    }

    public function test_a_second_post_with_the_same_key_refires_rather_than_duplicating(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $first = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $second = $this->postJson('/api/rmm/alerts', $this->payload($client->id, [
            'message' => '2 devices lost Huntress coverage.',
        ]), $this->authed())->assertOk();

        // One row, not two. This is the whole reason for posting an alert rather
        // than creating a ticket per transition.
        $this->assertSame(1, Alert::count());
        $this->assertSame($first->json('alert_id'), $second->json('alert_id'));
        $this->assertFalse($second->json('created'));
        $this->assertSame(1, $second->json('refired_count'));

        $alert = Alert::findOrFail($first->json('alert_id'));
        $this->assertSame('2 devices lost Huntress coverage.', $alert->message);
    }

    public function test_a_different_requirement_gets_its_own_alert(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();
        $this->postJson('/api/rmm/alerts', $this->payload($client->id, [
            'source_alert_id' => $client->id.':controld',
            'title' => 'Control D coverage changed',
            'metadata' => ['requirement_key' => 'controld', 'devices' => []],
        ]), $this->authed())->assertOk();

        $this->assertSame(2, Alert::count());
    }

    // -- refusals -------------------------------------------------------------

    public function test_refuses_an_unknown_client_rather_than_filing_it_elsewhere(): void
    {
        $this->configure();

        $this->postJson('/api/rmm/alerts', $this->payload(999_999), $this->authed())
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertSame(0, Alert::count());
    }

    public function test_refuses_a_severity_outside_the_known_set(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $this->postJson('/api/rmm/alerts', $this->payload($client->id, ['severity' => 'catastrophic']), $this->authed())
            ->assertStatus(422)
            ->assertJsonValidationErrors('severity');

        $this->assertSame(0, Alert::count());
    }

    public function test_refuses_a_missing_source_alert_id(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $payload = $this->payload($client->id);
        unset($payload['source_alert_id']);

        $this->postJson('/api/rmm/alerts', $payload, $this->authed())
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_alert_id');

        $this->assertSame(0, Alert::count());
    }
}
