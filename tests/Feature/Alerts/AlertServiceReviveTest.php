<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Client;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AlertService::upsert's revival branch, at the service level rather than
 * through an HTTP surface.
 *
 * `alerts` has unique(source, source_alert_id)
 * (database/migrations/2026_03_25_000001_create_alerts_table.php:32): a
 * resolved row still owns its key forever, so any source whose alert resolves
 * and later recurs under the same source_alert_id has to revive that row
 * rather than insert a second one under the same key. This is proven here
 * for a source other than Leif RMM, to show the fix lives in AlertService and
 * is not RMM-specific.
 */
class AlertServiceReviveTest extends TestCase
{
    use RefreshDatabase;

    private function data(Client $client, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $client->id,
            'severity' => AlertSeverity::Warning,
            'title' => 'Backup job failed',
            'message' => 'Nightly backup did not complete.',
            'hostname' => 'ACME-SRV-01',
            'metadata' => ['job' => 'nightly'],
            'fired_at' => now(),
        ], $overrides);
    }

    public function test_revives_a_resolved_alert_under_a_different_source(): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Comet, 'comet-job-42', $this->data($client));
        $service->resolve($first);

        $this->assertSame(AlertStatus::Resolved, $first->refresh()->status);
        $this->assertSame(1, Alert::count());

        $second = $service->upsert(AlertSource::Comet, 'comet-job-42', $this->data($client, [
            'message' => 'Nightly backup failed again.',
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Alert::count());
        $this->assertSame(AlertStatus::Active, $second->status);
        $this->assertSame(1, $second->refired_count);
        $this->assertNull($second->resolved_at);
        $this->assertNull($second->acknowledged_at);
        $this->assertSame('Nightly backup failed again.', $second->message);
    }

    public function test_revives_a_resolved_alert_for_tactical_too(): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Tactical, 'tactical-check-7', $this->data($client));
        $service->resolve($first);

        $second = $service->upsert(AlertSource::Tactical, 'tactical-check-7', $this->data($client));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Alert::count());
        $this->assertSame(AlertStatus::Active, $second->status);
    }

    public function test_a_resolved_alert_with_no_prior_ticket_gets_no_previous_ticket_key(): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Comet, 'comet-job-99', $this->data($client));
        $service->resolve($first);

        $revived = $service->upsert(AlertSource::Comet, 'comet-job-99', $this->data($client));

        $this->assertArrayNotHasKey('previous_ticket_id', $revived->metadata ?? []);
    }

    // Note: test_revives_a_resolved_alert_for_tactical_too above already
    // covers same-client revival (the same $client is used for both the
    // original alert and the recurrence), so it is not duplicated here.

    public function test_refuses_to_revive_a_resolved_alert_under_a_different_client(): void
    {
        // Tactical's fallback key - md5("{hostname}:{checkLabel}"), see
        // TacticalAlertService.php:173 - is NOT client-scoped: two different
        // clients can each have a "SERVER01" with the same check and collide
        // on the same source_alert_id. Before the revival branch existed,
        // that collision on a resolved row hit the unique index and threw a
        // QueryException. The revival branch must not turn that into a quiet
        // client_id reassignment - it must refuse just as loudly.
        $service = app(AlertService::class);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $first = $service->upsert(AlertSource::Tactical, 'tactical-collision-1', $this->data($clientA));
        $service->resolve($first);
        $this->assertSame(AlertStatus::Resolved, $first->refresh()->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Refusing to revive alert {$first->id}: it belongs to a different client than this tactical alert claims.");

        try {
            $service->upsert(AlertSource::Tactical, 'tactical-collision-1', $this->data($clientB));
        } finally {
            $unchanged = Alert::findOrFail($first->id);
            $this->assertSame(1, Alert::count());
            $this->assertSame(AlertStatus::Resolved, $unchanged->status);
            $this->assertSame($clientA->id, $unchanged->client_id);
            $this->assertSame(0, $unchanged->refired_count);
        }
    }

    public function test_revives_normally_when_the_resolved_alert_has_no_client(): void
    {
        // The refusal must only fire when both sides actually name a client.
        // A resolved alert with a null client_id (e.g. one raised before a
        // client could be resolved) must not block revival just because the
        // incoming payload supplies one.
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Comet, 'comet-job-clientless', $this->data($client, [
            'client_id' => null,
        ]));
        $this->assertNull($first->client_id);
        $service->resolve($first);

        $revived = $service->upsert(AlertSource::Comet, 'comet-job-clientless', $this->data($client));

        $this->assertSame($first->id, $revived->id);
        $this->assertSame(AlertStatus::Active, $revived->status);
        $this->assertSame($client->id, $revived->client_id);
    }
}
