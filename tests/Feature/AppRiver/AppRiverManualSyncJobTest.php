<?php

namespace Tests\Feature\AppRiver;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppRiver\AppRiverManualSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card 6abc5913 (EKnz4VSM), scope items 4 and 5: "Sync Licenses Now" must not
 * ride the queue worker whose --timeout=30 killed it in prod, and it must report
 * its outcome (success or failure, with a time) back to the Integrations page.
 */
class AppRiverManualSyncJobTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        Setting::setEncrypted('appriver_client_id', 'test-client');
        Setting::setEncrypted('appriver_client_secret', 'test-secret');
        Setting::setEncrypted('appriver_access_token', 'live-token');
        Setting::setValue('appriver_token_expires_at', now()->addHour()->toDateTimeString());
        Setting::setValue('appriver_connected_at', now()->toDateTimeString());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_manual_sync_runs_outside_the_queue_worker(): void
    {
        $this->connect();
        Queue::fake();
        Process::fake();

        $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.appriver.sync'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');

        // The prod worker's --timeout=30 bounds anything pushed to the queue.
        Queue::assertNothingPushed();
        Process::assertRan(fn (PendingProcess $p) => is_string($p->command)
            && str_contains($p->command, 'appriver:sync-licenses --manual')
            && str_ends_with(trim($p->command), '&'));
        Process::assertRanTimes(fn (PendingProcess $p) => true, 1);

        $status = AppRiverManualSync::status();
        $this->assertSame(AppRiverManualSync::STATE_RUNNING, $status['state']);
        $this->assertNotNull($status['started_at']);
    }

    public function test_a_second_press_while_running_starts_nothing(): void
    {
        $this->connect();
        Process::fake();
        (new AppRiverManualSync)->start();

        $this->actingAs($this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.appriver.sync'))
            ->assertSessionHas('error');

        Process::assertRanTimes(fn (PendingProcess $p) => true, 1);
    }

    public function test_successful_manual_run_records_success_with_a_time(): void
    {
        $this->connect();
        $this->travelTo(now()->startOfMinute());
        Process::fake();
        (new AppRiverManualSync)->start();

        // No mapped client: the real sync completes with "no changes".
        $this->artisan('appriver:sync-licenses', ['--manual' => true])->assertExitCode(0);

        $status = AppRiverManualSync::status();
        $this->assertSame(AppRiverManualSync::STATE_SUCCESS, $status['state']);
        $this->assertSame(now()->toIso8601String(), $status['finished_at']);
        $this->assertSame('no changes', $status['summary']);

        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringContainsString('id="appriver-manual-sync" data-state="success"', $html);
        $this->assertStringContainsString('Last manual sync succeeded', $html);
    }

    public function test_failed_manual_run_records_failure_and_logs_under_appriversync(): void
    {
        $this->connect();
        // Nothing listens on port 1: every vendor read fails without leaving the box.
        Setting::setValue('appriver_base_url', 'http://127.0.0.1:1');
        Client::factory()->create(['appriver_customer_id' => '11111111-2222-3333-4444-555555555555']);
        Log::spy();

        $this->artisan('appriver:sync-licenses', ['--manual' => true])->assertExitCode(1);

        $status = AppRiverManualSync::status();
        $this->assertSame(AppRiverManualSync::STATE_FAILED, $status['state']);
        $this->assertNotNull($status['finished_at']);
        $this->assertStringContainsString('error', $status['summary']);
        Log::shouldHaveReceived('error')->withArgs(
            fn ($message) => str_starts_with($message, '[AppRiverSync] Manual sync failed')
        )->once();

        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringContainsString('id="appriver-manual-sync" data-state="failed"', $html);
        $this->assertStringContainsString('Last manual sync failed', $html);
    }

    public function test_manual_run_while_disconnected_records_failure(): void
    {
        Log::spy();

        $this->artisan('appriver:sync-licenses', ['--manual' => true])->assertExitCode(1);

        $this->assertSame(AppRiverManualSync::STATE_FAILED, AppRiverManualSync::status()['state']);
        Log::shouldHaveReceived('error')->withArgs(
            fn ($message) => str_starts_with($message, '[AppRiverSync] Manual sync failed')
        )->once();
    }

    public function test_scheduled_run_does_not_touch_the_manual_outcome(): void
    {
        $this->connect();

        $this->artisan('appriver:sync-licenses')->assertExitCode(0);

        $this->assertNull(AppRiverManualSync::status(), 'only a manual run reports to the page');
    }

    public function test_a_run_that_never_reports_back_reads_as_no_result(): void
    {
        $this->connect();
        Process::fake();
        (new AppRiverManualSync)->start();
        $this->travel(AppRiverManualSync::STALE_RUNNING_MINUTES + 1)->minutes();

        $this->assertTrue(AppRiverManualSync::status()['overdue']);
        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringContainsString('Manual sync reported no result', $html);

        // An overdue run no longer blocks a fresh press.
        $this->assertTrue((new AppRiverManualSync)->start());
    }
}
