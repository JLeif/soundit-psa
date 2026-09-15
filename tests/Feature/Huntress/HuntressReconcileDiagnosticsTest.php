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
        Ticket::factory()->create([
            'source' => TicketSource::Huntress,
            'status' => TicketStatus::InProgress,
        ]);
        // Real commands and pollers: no valid link means no vendor request, even
        // though read credentials are present. Linking stays default-off.
        $this->artisan($command)
            ->expectsOutput('Done: 0 checked, 0 resolved, 1 skipped, 0 errors, skipped:no_validated_link=1.')
            ->assertExitCode(1);
    }

    #[DataProvider('commands')]
    public function test_empty_run_is_not_reported_as_success(string $command): void
    {
        $this->artisan($command)
            ->expectsOutput('Done: 0 checked, 0 resolved, 0 skipped, 0 errors.')
            ->assertExitCode(1);
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
            ->expectsOutput('Done: 0 checked, 0 resolved, 0 skipped, 1 errors, failed:link_repair_failed=1.')
            ->assertExitCode(1);
    }

    public function test_result_counts_reasons_without_ticket_ids_in_summary(): void
    {
        $result = new HuntressReconcileResult;
        $result->checked = 3;
        $result->recordSkipped('#1: still_open');
        $result->recordSkipped('#2: still_open');
        $result->recordSkipped('#3: upstream_scope_mismatch');
        $result->recordError('#4: fetch_failed');
        $this->assertSame(['still_open' => 2, 'upstream_scope_mismatch' => 1], $result->refusals);
        $this->assertSame(['fetch_failed' => 1], $result->failures);
        $this->assertSame('3 checked, 0 resolved, 3 skipped, 1 errors, skipped:still_open=2, skipped:upstream_scope_mismatch=1, failed:fetch_failed=1', $result->summary());
    }
}
