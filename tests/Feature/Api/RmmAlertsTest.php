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

    public function test_a_string_client_id_still_refires_its_own_alert(): void
    {
        // Laravel's `integer` rule accepts a numeric string without casting
        // it, so a client_id of "5" survives validation as the string "5".
        // The model attribute comes back as a native int. If the cross-client
        // guard compared those with !== it would treat a caller's own
        // legitimate re-fire as a conflict with a different client, forever.
        $this->configure();
        $client = Client::factory()->create();

        $first = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $second = $this->postJson('/api/rmm/alerts', $this->payload($client->id, [
            'client_id' => (string) $client->id,
        ]), $this->authed())
            ->assertOk()
            ->assertJson(['alert_id' => $first->json('alert_id'), 'refired_count' => 1]);

        $this->assertFalse($second->json('created'));
        $this->assertSame(1, Alert::count());
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

    public function test_refuses_to_refire_or_revive_an_alert_under_another_client(): void
    {
        // AlertService::upsert matches on source + source_alert_id with no
        // client scoping, and neither its re-fire branch nor its revive branch
        // (a resolved row recurring under the same key) ever updates client_id.
        // If two different clients ever posted the same source_alert_id, the
        // second post would silently re-fire or revive the FIRST client's
        // alert. The RMM's key convention (<clientId>:<requirement>) prevents
        // this in practice, but that is a caller convention, not a
        // server-side invariant - so the controller guards it directly, against
        // ANY status under the key, not just open ones.
        $this->configure();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $raised = $this->postJson('/api/rmm/alerts', $this->payload($clientA->id), $this->authed())->assertOk();

        $this->postJson('/api/rmm/alerts', $this->payload($clientB->id, [
            'source_alert_id' => $clientA->id.':huntress',
        ]), $this->authed())
            ->assertStatus(422)
            ->assertJson(['message' => 'An alert already exists under this source_alert_id for a different client.']);

        $this->assertSame(1, Alert::count());
        $alert = Alert::findOrFail($raised->json('alert_id'));
        $this->assertSame($clientA->id, $alert->client_id);
    }

    public function test_refuses_to_revive_a_resolved_alert_under_another_client(): void
    {
        $this->configure();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $raised = $this->postJson('/api/rmm/alerts', $this->payload($clientA->id), $this->authed())->assertOk();
        $this->postJson('/api/rmm/alerts/resolve', ['source_alert_id' => $clientA->id.':huntress'], $this->authed())->assertOk();

        $this->postJson('/api/rmm/alerts', $this->payload($clientB->id, [
            'source_alert_id' => $clientA->id.':huntress',
        ]), $this->authed())
            ->assertStatus(422)
            ->assertJson(['message' => 'An alert already exists under this source_alert_id for a different client.']);

        $this->assertSame(1, Alert::count());
        $alert = Alert::findOrFail($raised->json('alert_id'));
        $this->assertSame($clientA->id, $alert->client_id);
        $this->assertSame(AlertStatus::Resolved, $alert->status);
    }

    // -- resolving ------------------------------------------------------------

    public function test_resolves_the_alert_raised_under_that_key(): void
    {
        $this->configure();
        $client = Client::factory()->create();
        $raised = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $this->postJson('/api/rmm/alerts/resolve', [
            'source_alert_id' => $client->id.':huntress',
            'reason' => 'All devices returned to ok',
        ], $this->authed())
            ->assertOk()
            ->assertJson(['resolved' => true, 'alert_id' => $raised->json('alert_id')]);

        $this->assertSame(AlertStatus::Resolved, Alert::findOrFail($raised->json('alert_id'))->status);
    }

    public function test_resolving_an_unknown_key_succeeds_quietly(): void
    {
        // The RMM retries. A 404 here would make it special-case a situation
        // that is not a problem: nothing is open, which is what it wanted.
        $this->configure();

        $this->postJson('/api/rmm/alerts/resolve', ['source_alert_id' => 'nobody:nothing'], $this->authed())
            ->assertOk()
            ->assertJson(['resolved' => false, 'alert_id' => null]);
    }

    public function test_resolving_twice_is_not_an_error(): void
    {
        $this->configure();
        $client = Client::factory()->create();
        $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $body = ['source_alert_id' => $client->id.':huntress'];
        $this->postJson('/api/rmm/alerts/resolve', $body, $this->authed())->assertOk()->assertJson(['resolved' => true]);
        $this->postJson('/api/rmm/alerts/resolve', $body, $this->authed())->assertOk()->assertJson(['resolved' => false]);
    }

    public function test_a_new_alert_after_a_resolve_revives_the_same_row(): void
    {
        // alerts has unique(source, source_alert_id), so a resolved row still
        // owns its key forever - drift that comes back has to revive that same
        // row, not create a second one under the same key (which the unique
        // index forbids).
        $this->configure();
        $client = Client::factory()->create();

        $first = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();
        $this->postJson('/api/rmm/alerts/resolve', ['source_alert_id' => $client->id.':huntress'], $this->authed())->assertOk();
        $second = $this->postJson('/api/rmm/alerts', $this->payload($client->id, [
            'message' => '1 device lost Huntress coverage again.',
        ]), $this->authed())->assertOk();

        $this->assertSame($first->json('alert_id'), $second->json('alert_id'));
        $this->assertSame(1, Alert::count());

        $alert = Alert::findOrFail($first->json('alert_id'));
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame(1, $alert->refired_count);
        $this->assertNull($alert->resolved_at);
        $this->assertNull($alert->acknowledged_at);
        $this->assertSame('1 device lost Huntress coverage again.', $alert->message);
    }

    public function test_reviving_a_resolved_alert_that_had_a_ticket_preserves_it_in_metadata(): void
    {
        $this->configure();
        $client = Client::factory()->create();

        $raised = $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();
        $alert = Alert::findOrFail($raised->json('alert_id'));

        // Attach a ticket directly - no need to exercise the ticket-creation
        // flow to prove revival preserves the link.
        $ticket = \App\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $alert->update(['ticket_id' => $ticket->id]);
        $this->postJson('/api/rmm/alerts/resolve', ['source_alert_id' => $client->id.':huntress'], $this->authed())->assertOk();
        $this->assertNotNull($alert->refresh()->resolved_at);

        $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $revived = $alert->refresh();
        $this->assertNull($revived->ticket_id);
        $this->assertSame($ticket->id, $revived->metadata['previous_ticket_id']);
        $this->assertArrayHasKey('previous_resolved_at', $revived->metadata);
    }

    public function test_resolve_refuses_without_a_key(): void
    {
        $this->configure();
        $client = Client::factory()->create();
        $this->postJson('/api/rmm/alerts', $this->payload($client->id), $this->authed())->assertOk();

        $this->postJson('/api/rmm/alerts/resolve', ['source_alert_id' => $client->id.':huntress'])
            ->assertStatus(401);

        $this->assertSame(AlertStatus::Active, Alert::first()->status);
    }
}
