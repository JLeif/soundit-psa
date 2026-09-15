<?php

namespace Tests\Feature\Huntress;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Huntress\HuntressLinkService;
use App\Services\Huntress\HuntressReconcileResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HuntressReconcileDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::setEncrypted('huntress_api_key', 'synthetic-key');
        Setting::setEncrypted('huntress_api_secret', 'synthetic-secret');
    }

    public static function commands(): array
    {
        return [['huntress:reconcile-incidents'], ['huntress:reconcile-escalations']];
    }

    #[DataProvider('commands')]
    public function test_unlinked_tickets_are_visible_without_network_calls(string $command): void
    {
        Setting::setValue('huntress_webhooks_enabled', '1');
        $vendor = Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $vendor->shouldNotReceive('getIncidentReport', 'getEscalation');
        $this->app->bind(\App\Services\Huntress\HuntressClient::class, fn () => $vendor);
        Ticket::factory()->create([
            'source' => TicketSource::Huntress,
            'status' => TicketStatus::InProgress,
        ]);
        // Real commands and pollers: no valid link means no vendor request, even
        // though read credentials and linking are enabled. Skips are not eligible.
        $this->artisan($command)
            ->expectsOutput('Done: 0 eligible, 0 checked, 0 resolved, 1 skipped, 0 errors, clean no-op: no eligible validated links, skipped:no_validated_link=1.')
            ->assertExitCode(0);
    }

    #[DataProvider('commands')]
    public function test_empty_dark_run_is_explicit_clean_noop(string $command): void
    {
        $this->artisan($command)
            ->expectsOutput('Done: 0 eligible, 0 checked, 0 resolved, 0 skipped, 0 errors, clean no-op: no eligible validated links.')
            ->assertExitCode(0);
    }

    #[DataProvider('commands')]
    public function test_repair_failure_stops_polling_and_is_visible_as_failure(string $command): void
    {
        Ticket::factory()->create([
            'source' => TicketSource::Huntress,
            'status' => TicketStatus::InProgress,
        ]);
        $links = Mockery::mock(HuntressLinkService::class);
        $links->shouldReceive('promote')->once()->andThrow(new \RuntimeException('synthetic private details'));
        $this->app->instance(HuntressLinkService::class, $links);
        // Zero skipped proves the loop did not proceed to the unlinked ticket.
        $this->artisan($command)
            ->expectsOutput('Done: 0 eligible, 0 checked, 0 resolved, 0 skipped, 1 errors, failed:link_repair_failed=1.')
            ->assertExitCode(1);
    }

    #[DataProvider('commands')]
    public function test_successful_read_and_fetch_outage_have_distinct_output(string $command): void
    {
        Setting::setValue('huntress_webhooks_enabled', '1');
        Setting::setValue('huntress_webhook_account_id', '11');
        $client = \App\Models\Client::factory()->create(['huntress_organization_id' => 42]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id,
            'source' => TicketSource::Huntress, 'status' => TicketStatus::New]);
        $alert = \App\Models\Alert::create(['source' => 'huntress', 'source_alert_id' => 'synthetic-command',
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'title' => 'Synthetic',
            'status' => 'ticketed', 'severity' => 'critical', 'fired_at' => now()]);
        $incident = $command === 'huntress:reconcile-incidents';
        $type = $incident ? 'incident_report' : 'escalation';
        \Illuminate\Support\Facades\DB::table('huntress_webhook_events')->insert([
            'delivery_id' => 'synthetic-command', 'content_hash' => str_repeat('a', 64),
            'event_type' => $type.'.created', 'record_type' => $type, 'record_id' => 9182,
            'account_id' => 11, 'organization_ids' => '[42]', 'resolved' => false,
            'record_created_at' => now(), 'correlation_at' => now(), 'received_at' => now(),
        ]);
        app(HuntressLinkService::class)->capture($alert, $ticket,
            'https://synthetic.huntress.io/org/42/'.($incident ? 'incident_reports' : 'escalations').'/9182', now()->toDateTimeString());
        $this->assertNotNull($alert->fresh()->huntress_event_id);
        $vendor = Mockery::mock(\App\Services\Huntress\HuntressClient::class);
        $method = $incident ? 'getIncidentReport' : 'getEscalation';
        $vendor->shouldReceive($method)->once()->with(9182)->andReturn([
            'id' => 9182, 'organization_id' => 42, 'organizations' => [['id' => 42]], 'status' => 'open',
        ]);
        $vendor->shouldReceive($method)->twice()->with(9182)->andThrow(new \RuntimeException('synthetic private vendor body'));
        $vendor->shouldReceive('getIncidentReports', 'getEscalations')->never();
        $this->app->bind(\App\Services\Huntress\HuntressClient::class, fn () => $vendor);
        $this->artisan($command)
            ->expectsOutput('Done: 1 eligible, 1 checked, 0 resolved, 1 skipped, 0 errors, skipped:still_open=1.')
            ->assertExitCode(0);
        $this->artisan($command)
            ->expectsOutput('Done: 1 eligible, 0 checked, 0 resolved, 0 skipped, 1 errors, failed:fetch_failed=1.')
            ->assertExitCode(1);
        $serviceClass = $incident ? \App\Services\Huntress\HuntressIncidentReconcileService::class
            : \App\Services\Huntress\HuntressEscalationReconcileService::class;
        $service = new $serviceClass($vendor, app(\App\Services\TicketService::class), app(\App\Services\AlertService::class));
        $outage = $service->reconcile();
        $this->assertSame([1, 0, 0, 0, 1], [$outage->eligible, $outage->checked, $outage->updated, $outage->skipped, $outage->errors]);
        $this->assertSame(['fetch_failed' => 1], $outage->failures);
        $this->assertSame([], $outage->refusals);
        $this->assertTrue($outage->failed());
        $this->assertSame(TicketStatus::New, $ticket->fresh()->status);

        // A genuinely linked ticket becomes ineligible while dark; no third read.
        Setting::setValue('huntress_webhooks_enabled', '0');
        $dark = $service->reconcile();
        $this->assertSame([0, 0, 0, 1, 0], [$dark->eligible, $dark->checked, $dark->updated, $dark->skipped, $dark->errors]);
        $this->assertSame(['no_validated_link' => 1], $dark->refusals);
        $this->assertFalse($dark->failed());
        $this->artisan($command)
            ->expectsOutput('Done: 0 eligible, 0 checked, 0 resolved, 1 skipped, 0 errors, clean no-op: no eligible validated links, skipped:no_validated_link=1.')
            ->assertExitCode(0);
    }

    public function test_zero_checked_eligible_result_is_failure_even_without_recorded_error(): void
    {
        $result = new HuntressReconcileResult;
        $this->assertFalse($result->failed());
        $result->recordSkipped('#1: no_validated_link');
        $this->assertSame(0, $result->eligible);
        $this->assertFalse($result->failed());
        $result->eligible = 1;
        $this->assertTrue($result->failed());
        $this->assertStringNotContainsString('clean no-op', $result->summary());
        $result->checked = 1;
        $this->assertFalse($result->failed());
        $result->recordError('#1: fetch_failed');
        $this->assertTrue($result->failed());
    }

    public function test_result_counts_reasons_without_ticket_ids_in_summary(): void
    {
        $result = new HuntressReconcileResult;
        $result->eligible = 4;
        $result->checked = 3;
        $result->recordSkipped('#1: still_open');
        $result->recordSkipped('#2: still_open');
        $result->recordSkipped('#3: upstream_scope_mismatch');
        $result->recordError('#4: fetch_failed');
        $this->assertSame(['still_open' => 2, 'upstream_scope_mismatch' => 1], $result->refusals);
        $this->assertSame(['fetch_failed' => 1], $result->failures);
        $this->assertSame('4 eligible, 3 checked, 0 resolved, 3 skipped, 1 errors, skipped:still_open=2, skipped:upstream_scope_mismatch=1, failed:fetch_failed=1', $result->summary());
    }
}
