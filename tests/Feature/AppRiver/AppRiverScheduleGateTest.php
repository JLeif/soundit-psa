<?php

namespace Tests\Feature\AppRiver;

use App\Enums\AlertSource;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Card 6abc5913 (EKnz4VSM), scope item 2: the daily 05:50 appriver:sync-licenses
 * must not skip silently when AppRiver was connected and no longer is.
 *
 * Drives the REAL event registered in routes/console.php through the scheduler's
 * own filtersPass(), the call ScheduleRunCommand makes before running an event,
 * so a schedule that stops consulting the monitor fails here.
 */
class AppRiverScheduleGateTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'appriver:sync-licenses'));
        $this->assertCount(1, $events, 'exactly one appriver:sync-licenses schedule');

        return $events->first();
    }

    private function mapClient(): void
    {
        Client::factory()->create(['appriver_customer_id' => '11111111-2222-3333-4444-555555555555']);
    }

    public function test_the_schedule_time_is_unchanged(): void
    {
        $this->assertSame('50 5 * * *', $this->event()->expression);
    }

    public function test_previously_connected_now_disconnected_run_is_logged_and_alerted_not_silent(): void
    {
        $this->mapClient();
        Setting::setValue('appriver_connected_at', now()->subDays(12)->toDateTimeString());
        Log::spy();

        $this->assertFalse($this->event()->filtersPass($this->app), 'no token: the sync itself cannot run');

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => str_starts_with($message, '[AppRiverSync]') && str_contains($message, 'login dropped')
        )->once();
        $alerts = Alert::where('source', AlertSource::AppRiver)->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Reconnect in Settings > Integrations > AppRiver', $alerts->first()->message);
    }

    public function test_daily_runs_while_dropped_refresh_one_alert(): void
    {
        $this->mapClient();
        Setting::setValue('appriver_connected_at', now()->subDays(12)->toDateTimeString());

        foreach (range(1, 3) as $day) {
            $this->event()->filtersPass($this->app);
        }

        $alerts = Alert::where('source', AlertSource::AppRiver)->get();
        $this->assertCount(1, $alerts, 'one episode, not one alert per daily run');
        $this->assertSame(2, $alerts->first()->refired_count);
    }

    public function test_never_connected_skips_quietly(): void
    {
        $this->mapClient();
        Log::spy();

        $this->assertFalse($this->event()->filtersPass($this->app));

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, Alert::where('source', AlertSource::AppRiver)->count());
    }

    public function test_connected_with_a_mapped_client_runs(): void
    {
        Setting::setEncrypted('appriver_access_token', 'live-token');
        Setting::setValue('appriver_connected_at', now()->toDateTimeString());
        $this->assertFalse($this->event()->filtersPass($this->app), 'no mapped client: nothing to sync');

        $this->mapClient();
        $this->assertTrue($this->event()->filtersPass($this->app));
        $this->assertSame(0, Alert::where('source', AlertSource::AppRiver)->count());
    }
}
