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

    // -- every source ----------------------------------------------------------
    //
    // Added in the PSA port (card w5kVPHVZ): the revive branch lives in the
    // shared AlertService, so it is proven for EVERY AlertSource case, not
    // only the two Justin's tests name. The provider reads the enum itself, so
    // a source added later is covered without editing this file.

    /** @return array<string, array{AlertSource}> */
    public static function everySource(): array
    {
        $cases = [];
        foreach (AlertSource::cases() as $source) {
            $cases[$source->value] = [$source];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everySource')]
    public function test_every_source_revives_a_resolved_alert_in_place(AlertSource $source): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert($source, 'recurring-key-1', $this->data($client));
        $service->resolve($first);

        $second = $service->upsert($source, 'recurring-key-1', $this->data($client, [
            'message' => 'It came back.',
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Alert::where('source', $source)->count());
        $this->assertSame(AlertStatus::Active, $second->refresh()->status);
        $this->assertSame(1, $second->refired_count);
        $this->assertNull($second->resolved_at);
        $this->assertSame('It came back.', $second->message);
        $this->assertArrayHasKey('previous_resolved_at', $second->metadata);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everySource')]
    public function test_every_source_refuses_cross_client_revival_with_the_defined_exception(AlertSource $source): void
    {
        // The refusal is a defined exception class, thrown before any write -
        // never a database error from the unique index.
        $service = app(AlertService::class);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $first = $service->upsert($source, 'shared-key-1', $this->data($clientA));
        $service->resolve($first);

        try {
            $service->upsert($source, 'shared-key-1', $this->data($clientB));
            $this->fail('Expected AlertClientConflictException');
        } catch (\App\Services\AlertClientConflictException $e) {
            $this->assertSame($first->id, $e->alertId);
        }

        $unchanged = Alert::findOrFail($first->id);
        $this->assertSame(1, Alert::count());
        $this->assertSame(AlertStatus::Resolved, $unchanged->status);
        $this->assertSame($clientA->id, $unchanged->client_id);
    }

    // -- null incoming client (Jeeves RULED (B) term 3, run 01a0fb29) ---------
    //
    // Callers that can pass client_id = null to upsert, read at source:
    // Tactical (agent with no PSA asset), Ninja (device with no PSA asset),
    // Huntress (organisation not resolved to a client), Cipp and AppRiver
    // (never pass a client). Leif RMM validates client_id required|exists, Comet
    // writes its own rows without upsert, and Level raises no alerts.

    /** @return array<string, array{AlertSource}> */
    public static function nullClientSources(): array
    {
        return [
            'tactical' => [AlertSource::Tactical],
            'ninja' => [AlertSource::Ninja],
            'huntress' => [AlertSource::Huntress],
            'cipp' => [AlertSource::Cipp],
            'appriver' => [AlertSource::AppRiver],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nullClientSources')]
    public function test_a_null_client_occurrence_does_not_revive_a_clients_resolved_alert(AlertSource $source): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert($source, 'unscoped-key-1', $this->data($client));
        $service->resolve($first);
        $before = Alert::findOrFail($first->id);

        try {
            $service->upsert($source, 'unscoped-key-1', $this->data($client, ['client_id' => null, 'message' => 'Unknown host.']));
            $this->fail('Expected AlertClientConflictException');
        } catch (\App\Services\AlertClientConflictException $e) {
            $this->assertSame($first->id, $e->alertId);
            $this->assertSame("Refusing to revive alert {$first->id}: it belongs to a client and this {$source->value} alert names none.", $e->getMessage());
        }

        $unchanged = Alert::findOrFail($first->id);
        $this->assertSame(1, Alert::count());
        $this->assertSame(AlertStatus::Resolved, $unchanged->status);
        $this->assertSame($client->id, $unchanged->client_id);
        $this->assertSame(0, $unchanged->refired_count);
        $this->assertSame($before->message, $unchanged->message);
        $this->assertEquals($before->resolved_at, $unchanged->resolved_at);
    }

    public function test_a_null_client_occurrence_omitting_the_key_entirely_is_refused_too(): void
    {
        // Cipp and AppRiver omit client_id from $data altogether rather than
        // passing null; the guard must read absence the same way.
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Cipp, 'absent-key-1', $this->data($client));
        $service->resolve($first);

        $data = $this->data($client);
        unset($data['client_id']);

        $this->expectException(\App\Services\AlertClientConflictException::class);
        try {
            $service->upsert(AlertSource::Cipp, 'absent-key-1', $data);
        } finally {
            $this->assertSame(AlertStatus::Resolved, Alert::findOrFail($first->id)->status);
        }
    }

    public function test_a_null_client_occurrence_still_revives_a_clientless_resolved_alert(): void
    {
        // Nothing to protect when neither side names a client: revive as before.
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Tactical, 'clientless-both', $this->data($client, ['client_id' => null]));
        $service->resolve($first);

        $revived = $service->upsert(AlertSource::Tactical, 'clientless-both', $this->data($client, ['client_id' => null]));

        $this->assertSame($first->id, $revived->id);
        $this->assertSame(AlertStatus::Active, $revived->status);
        $this->assertNull($revived->client_id);
        $this->assertSame(1, $revived->refired_count);
    }

    public function test_tactical_unmapped_agent_does_not_revive_a_clients_resolved_alert(): void
    {
        // End to end through the real caller: an alert_failure for an agent
        // with no TacticalAsset resolves client_id to null, and with no
        // alert_id the key is md5("{hostname}:{checkLabel}"), which a
        // client's earlier alert on a same-named host can already own.
        $client = Client::factory()->create();
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/tactical/alert_failure.json')), true);
        unset($payload['alert_id']);
        $payload['agent_id'] = 'agent-with-no-psa-asset';
        $key = md5("{$payload['hostname']}:{$payload['check_name']}");

        $owned = app(AlertService::class)->upsert(AlertSource::Tactical, $key, $this->data($client));
        app(AlertService::class)->resolve($owned);

        try {
            app(\App\Services\Tactical\TacticalAlertService::class)->handleAlertFailure($payload);
            $this->fail('Expected AlertClientConflictException');
        } catch (\App\Services\AlertClientConflictException $e) {
            $this->assertSame($owned->id, $e->alertId);
        }

        $unchanged = Alert::findOrFail($owned->id);
        $this->assertSame(AlertStatus::Resolved, $unchanged->status);
        $this->assertSame($client->id, $unchanged->client_id);
        $this->assertSame(1, Alert::count());
    }

    public function test_ninja_device_without_asset_does_not_revive_a_clients_resolved_alert(): void
    {
        // End to end through NinjaAlertService::handleTriggered: a deviceId
        // with no PSA asset passes client_id = null.
        $client = Client::factory()->create();
        $owned = app(AlertService::class)->upsert(AlertSource::Ninja, 'ninja-series-9', $this->data($client));
        app(AlertService::class)->resolve($owned);

        try {
            app(\App\Services\Ninja\NinjaAlertService::class)->handleTriggered([
                'seriesUid' => 'ninja-series-9',
                'deviceId' => 999999,
                'severity' => 'MAJOR',
                'message' => 'Disk failing',
                'sourceName' => 'Disk health',
            ]);
            $this->fail('Expected AlertClientConflictException');
        } catch (\App\Services\AlertClientConflictException $e) {
            $this->assertSame($owned->id, $e->alertId);
        }

        $this->assertSame(AlertStatus::Resolved, Alert::findOrFail($owned->id)->status);
        $this->assertSame($client->id, Alert::findOrFail($owned->id)->client_id);
    }

    public function test_reviving_preserves_the_previous_ticket_in_metadata(): void
    {
        $service = app(AlertService::class);
        $client = Client::factory()->create();

        $first = $service->upsert(AlertSource::Ninja, 'ninja-uid-5', $this->data($client));
        $ticket = \App\Models\Ticket::factory()->create(['client_id' => $client->id]);
        $first->update(['ticket_id' => $ticket->id, 'status' => AlertStatus::Ticketed]);
        $service->resolve($first->refresh());

        $revived = $service->upsert(AlertSource::Ninja, 'ninja-uid-5', $this->data($client));

        $this->assertSame($first->id, $revived->id);
        $this->assertNull($revived->ticket_id);
        $this->assertSame($ticket->id, $revived->metadata['previous_ticket_id']);
        $this->assertSame('nightly', $revived->metadata['job']);
    }
}
