<?php

namespace Tests\Feature\AutoElevate;

use App\Enums\UserRole;
use App\Http\Controllers\Web\ClientAutoElevateController;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientAutoElevatePanelTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
        Setting::setValue('app_timezone', 'America/Los_Angeles');
        $this->actingAs(User::factory()->create(['role' => UserRole::Tech]));
    }

    private function panel(Client $client)
    {
        return $this->get(route('clients.autoelevate.computers', $client));
    }

    // --- the three empty states, each distinct and explicit ---------------------------

    public function test_empty_state_1_client_not_mapped_makes_no_vendor_call(): void
    {
        Http::fake();
        $client = Client::factory()->create();

        $this->panel($client)->assertOk()
            ->assertSee('data-state="not_mapped"', false)
            ->assertSee('Not mapped.')
            ->assertSee('not linked to an AutoElevate company')
            ->assertDontSee('No computers returned')
            ->assertDontSee('read failed')
            ->assertDontSee('<table', false);
        Http::assertNothingSent();
    }

    public function test_empty_state_2_mapped_but_vendor_returned_zero_computers(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response(self::envelope([], 0), 200)]);
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);

        $this->panel($client)->assertOk()
            ->assertSee('data-state="empty"', false)
            ->assertSee('No computers returned.')
            ->assertSee('reported zero computers')
            ->assertDontSee('Not mapped.')
            ->assertDontSee('read failed')
            ->assertDontSee('<table', false);
        Http::assertSentCount(1);
    }

    public function test_empty_state_3_read_failed_screams_and_is_not_cached(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::sequence()
            ->push('', 503)
            ->push(self::envelope([self::computer()]), 200)]);
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);

        $this->panel($client)->assertOk()
            ->assertSee('data-state="failed"', false)
            ->assertSee('data-reason="http_503"', false)
            ->assertSee('AutoElevate read failed')
            ->assertSee('not evidence that the client has no machines')
            ->assertDontSee('Not mapped.')
            ->assertDontSee('No computers returned')
            ->assertDontSee('<table', false)
            ->assertDontSee('synthetic-only-key');

        // The failure was not cached: the next open retries and gets the rows.
        $this->panel($client)->assertOk()->assertSee('data-state="ok"', false)->assertSee('WS-FINANCE-04');
        Http::assertSentCount(2);
    }

    public function test_read_failed_covers_envelope_drift_and_a_truncated_page(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);

        Http::fake([self::BASE.'/*' => Http::sequence()
            ->push([self::computer()], 200)                              // bare array (no envelope)
            ->push(self::envelope([self::computer()], 999), 200)         // short page before totalCount
            ->push(self::envelope([self::computer(['companyId' => self::COMPANY_B])]), 200)]);   // foreign row
        $this->panel($client)->assertSee('data-reason="envelope_drift"', false)->assertDontSee('<table', false);
        $this->panel($client)->assertSee('data-reason="paging_incomplete"', false)->assertDontSee('<table', false);
        $this->panel($client)->assertSee('data-reason="row_drift"', false)->assertDontSee('<table', false);
    }

    /**
     * The three reasons added for #2111/#2115/#2124 are operator-visible surfaces, not just
     * internal labels: each reaches this panel and must say what it means in words, while a
     * reason that needs no elaboration adds nothing.
     */
    public function test_the_new_read_failures_reach_the_panel_with_an_honest_operator_sentence(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $page = [];
        for ($i = 1; $i <= 200; $i++) {
            $page[] = self::computer(['id' => self::uuid($i)]);
        }

        // One counter-driven stub, because Http::fake() MERGES stubs rather than replacing
        // them: a second Http::fake() in this method would never be reached.
        $call = 0;
        Http::fake(function () use (&$call, $page) {
            return Http::response(match ($call++) {
                0 => self::envelope($page, 10001),                        // over the collectible cap
                1 => self::envelope(array_slice($page, 0, 5), 3),         // 5 distinct rows, vendor claims 3
                2 => self::envelope([self::computer(['lastCheckedInAt' => intdiv(self::EXAMPLE_MS, 1000)])]),
                default => self::envelope([self::computer()], 999),       // short page before totalCount
            }, 200);
        });

        // paging_over_cap — a tenant bigger than one walk can collect, known from page one.
        $this->panel($client)->assertOk()
            ->assertSee('data-reason="paging_over_cap"', false)
            ->assertSee('more machines than one read can collect')
            ->assertDontSee('<table', false);
        Http::assertSentCount(1);

        // paging_count_mismatch — distinct rows do not account for the vendor's own total.
        Cache::flush();
        $this->panel($client)->assertOk()
            ->assertSee('data-reason="paging_count_mismatch"', false)
            ->assertSee('different number of distinct machines than it said it held');

        // timestamp_implausible — the vendor's example value handed over in SECONDS.
        Cache::flush();
        $this->panel($client)->assertOk()
            ->assertSee('data-reason="timestamp_implausible"', false)
            ->assertSee('outside any believable range')
            ->assertDontSee('1970');

        // A reason with no useful elaboration stands alone rather than restating itself.
        Cache::flush();
        $this->panel($client)->assertOk()
            ->assertSee('data-reason="paging_incomplete"', false)
            ->assertDontSee('more machines than one read can collect');
    }

    public function test_mapped_client_with_key_removed_is_a_failed_read_not_an_empty_one(): void
    {
        Setting::setEncrypted('autoelevate_api_key', '');
        Http::fake();
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->panel($client)->assertOk()->assertSee('data-state="failed"', false)->assertSee('data-reason="configuration"', false);
        Http::assertNothingSent();
    }

    // --- rows ----------------------------------------------------------------------

    public function test_rows_render_machine_os_mode_and_pacific_check_in_from_epoch_ms(): void
    {
        Http::fake([self::BASE.'/api/v1/computers*' => Http::response(self::envelope([
            self::computer(['id' => self::uuid(1), 'machineName' => 'WS-FINANCE-04', 'elevationMode' => 'audit',
                'lastCheckedInAt' => 1716900000000]),   // 2024-05-28T12:40:00Z → 5:40 AM PDT
            self::computer(['id' => self::uuid(2), 'machineName' => 'SRV-DC-01', 'elevationMode' => 'live',
                'operatingSystem' => ['name' => 'Windows Server 2022', 'version' => null],
                'lastCheckedInAt' => 1737000000000]),   // 2025-01-16T04:00:00Z → Jan 15, 8:00 PM PST
            self::computer(['id' => self::uuid(3), 'machineName' => 'LT-NEW-01', 'elevationMode' => null,
                'operatingSystem' => null, 'lastCheckedInAt' => null]),
            self::computer(['id' => self::uuid(4), 'machineName' => null, 'elevationMode' => 'someFutureMode']),
        ]), 200)]);
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);

        $response = $this->panel($client)->assertOk()->assertSee('data-state="ok"', false);

        $response->assertSeeInOrder(['LT-NEW-01', 'SRV-DC-01', 'WS-FINANCE-04'])
            ->assertSee('Windows 11 Pro')->assertSee('10.0.26100')
            ->assertSee('Windows Server 2022')
            ->assertSee('May 28, 2024 5:40 AM PDT')
            ->assertSee('Jan 15, 2025 8:00 PM PST')
            ->assertSee('Never checked in')
            ->assertSee('Not reported')
            ->assertSee('No mode reported')
            ->assertSee('Unrecognised: someFutureMode')
            ->assertSee('4 computers')
            ->assertDontSee('Not mapped.')->assertDontSee('No computers returned')->assertDontSee('read failed')
            ->assertDontSee('2024-05-28T12:40', false)
            ->assertDontSee('1716900000000');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'companyId='.self::COMPANY_A) && str_contains($r->url(), 'take=200'));
    }

    public function test_rows_are_rendered_in_utc_when_no_display_timezone_is_set(): void
    {
        Setting::setValue('app_timezone', 'UTC');
        Http::fake([self::BASE.'/*' => Http::response(self::envelope([self::computer(['lastCheckedInAt' => 1716900000000])]), 200)]);
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->panel($client)->assertSee('May 28, 2024 12:40 PM UTC');
    }

    public function test_successful_read_is_cached_per_company_for_a_minute(): void
    {
        Http::fake([self::BASE.'/*' => Http::sequence()
            ->push(self::envelope([self::computer()]), 200)
            ->push(self::envelope([], 0), 200)]);
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->panel($client)->assertSee('data-state="ok"', false);
        $this->panel($client)->assertSee('data-state="ok"', false);
        Http::assertSentCount(1);
        $this->assertSame(60, ClientAutoElevateController::CACHE_TTL);

        // A different company is a different cache entry: it gets the second response.
        $other = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        $this->panel($other)->assertSee('data-state="empty"', false);
        Http::assertSentCount(2);
    }

    public function test_paged_company_lands_every_row_on_the_panel(): void
    {
        $all = [];
        for ($i = 1; $i <= 201; $i++) {
            $all[] = self::computer(['id' => self::uuid($i), 'machineName' => sprintf('PC-%03d', $i)]);
        }
        Http::fake(function ($r) use ($all) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(self::envelope(array_slice($all, (int) $q['skip'], 200), 201), 200);
        });
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->panel($client)->assertSee('201 computers')->assertSee('PC-201');
        Http::assertSentCount(2);
    }

    // --- the client page hosts the card --------------------------------------------

    public function test_client_page_carries_the_panel_card_only_when_configured(): void
    {
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        Http::fake();

        $this->get(route('clients.show', $client))->assertOk()
            ->assertSee('id="autoelevate-card"', false)
            ->assertSee(route('clients.autoelevate.computers', $client));
        Http::assertNothingSent();   // the page view itself never calls the vendor

        Setting::setEncrypted('autoelevate_api_key', '');
        $this->get(route('clients.show', $client))->assertOk()->assertDontSee('id="autoelevate-card"', false);
    }

    public function test_panel_requires_login(): void
    {
        auth()->logout();
        Http::fake();
        $client = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->panel($client)->assertRedirect(route('login'));
        Http::assertNothingSent();
    }
}
