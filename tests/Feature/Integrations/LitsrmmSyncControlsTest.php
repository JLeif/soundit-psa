<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\SyncResult;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The three ways a LITSRMM device sync starts, and the seats it leaves on the
 * client page:
 *
 *  - the schedule, every four hours beside level:sync-devices;
 *  - "Sync devices" on the client page's LITSRMM card, for that client only;
 *  - "Sync all devices" on Settings > Integrations > LITSRMM, admins only.
 *
 * The sync itself is LitsrmmAssetSyncTest's; here it is a mock, so these tests
 * pin who may start it, for which client, and what the operator is told.
 */
class LitsrmmSyncControlsTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR_CLIENT = 'a362168a-46c7-4b95-8800-c46162185469';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('litsrmm_api_key', 'fake-'.bin2hex(random_bytes(8)));
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');
    }

    private function mapped(): Client
    {
        return Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'litsrmm_client_id' => self::VENDOR_CLIENT,
        ]);
    }

    /** @return Mockery\MockInterface the service, bound so controllers receive it */
    private function syncService(): Mockery\MockInterface
    {
        $service = Mockery::mock(LitsrmmAssetSyncService::class);
        $this->app->instance(LitsrmmAssetSyncService::class, $service);

        return $service;
    }

    private static function created(int $n): SyncResult
    {
        $result = new SyncResult;
        $result->created = $n;

        return $result;
    }

    // ---- the schedule ----

    private function event(): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'litsrmm:sync-devices'));
        $this->assertCount(1, $events, 'exactly one litsrmm:sync-devices schedule');

        return $events->first();
    }

    public function test_it_is_scheduled_every_four_hours_without_overlap(): void
    {
        $event = $this->event();

        $this->assertSame('0 */4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_runs_only_when_switched_on_configured_and_mapped(): void
    {
        $this->assertFalse($this->event()->filtersPass($this->app), 'no client mapped: nothing to do');

        $this->mapped();
        $this->assertTrue($this->event()->filtersPass($this->app));

        Setting::setValue('litsrmm_enabled', '0');
        $this->assertFalse($this->event()->filtersPass($this->app), 'switched off means off');
    }

    // ---- the client page ----

    public function test_the_client_page_button_syncs_that_client_only(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (?Client $only) => $only?->id === $client->id)
            ->andReturn(self::created(3));

        $this->actingAs(User::factory()->tech()->create())
            ->from(route('clients.show', $client))
            ->post(route('clients.litsrmm.sync', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '3 created'));
    }

    public function test_the_client_page_button_reports_what_went_wrong(): void
    {
        $client = $this->mapped();
        $result = new SyncResult;
        $result->recordError('Failed to read LITSRMM devices: boom');
        $result->recordSkipped('SHARED: more than one asset or device could be this machine; not linked, not created');
        $this->syncService()->shouldReceive('sync')->once()->andReturn($result);

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'boom') && str_contains($message, 'SHARED'));
    }

    public function test_an_unmapped_client_is_refused_without_reading(): void
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'not linked to LITSRMM'));
    }

    public function test_a_switched_off_integration_is_refused_without_reading(): void
    {
        $client = $this->mapped();
        Setting::setValue('litsrmm_enabled', '0');
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('clients.litsrmm.sync', $client))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'switched off'));
    }

    public function test_the_client_page_button_needs_a_login(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->post(route('clients.litsrmm.sync', $client))->assertRedirect(route('login'));
    }

    public function test_the_litsrmm_card_carries_the_button_and_its_seats(): void
    {
        $client = $this->mapped();
        $type = LicenseType::create(['vendor' => 'litsrmm', 'vendor_sku_id' => 'rmm_workstation', 'name' => 'LITSRMM — Workstation', 'is_active' => true]);
        License::create(['license_type_id' => $type->id, 'client_id' => $client->id, 'vendor_ref' => self::VENDOR_CLIENT, 'quantity' => 3, 'status' => 'active', 'synced_at' => now()]);

        $this->actingAs(User::factory()->tech()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee(route('clients.litsrmm.sync', $client))
            ->assertSee('3 licenses');
    }

    public function test_an_unlinked_client_page_has_no_sync_button(): void
    {
        $client = Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);

        $this->actingAs(User::factory()->tech()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee(route('clients.litsrmm.sync', $client));
    }

    // ---- Settings > Integrations ----

    public function test_the_settings_button_runs_a_full_sync_for_an_admin(): void
    {
        $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (?Client $only = null) => $only === null)
            ->andReturn(self::created(2));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.litsrmm.sync-devices'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2 created'));
    }

    public function test_the_settings_button_is_admins_only(): void
    {
        $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->actingAs(User::factory()->tech()->create())
            ->post(route('settings.integrations.litsrmm.sync-devices'))
            ->assertForbidden();
    }

    public function test_the_settings_card_offers_the_button_only_when_it_can_run(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.integrations'))
            ->assertOk()->assertSee(route('settings.integrations.litsrmm.sync-devices'));

        Setting::setValue('litsrmm_enabled', '0');
        $this->actingAs($admin)->get(route('settings.integrations'))
            ->assertOk()->assertDontSee(route('settings.integrations.litsrmm.sync-devices'));
    }

    // ---- the command ----

    public function test_the_command_can_sync_one_client(): void
    {
        $client = $this->mapped();
        $this->syncService()->shouldReceive('sync')->once()
            ->withArgs(fn (?Client $only) => $only?->id === $client->id)
            ->andReturn(self::created(1));

        $this->artisan('litsrmm:sync-devices', ['--client' => $client->id])
            ->expectsOutputToContain('1 created')
            ->assertSuccessful();
    }

    public function test_the_command_refuses_an_unknown_client(): void
    {
        $this->mapped();
        $this->syncService()->shouldNotReceive('sync');

        $this->artisan('litsrmm:sync-devices', ['--client' => 999999])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }
}
