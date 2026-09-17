<?php

namespace Tests\Feature\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Setting;
use App\Support\BenjiPaysConfig;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Portal Pay Online via a BenjiPays applied link (#2065). Every vendor request
 * is faked and strays are refused: no live BenjiPays call from dev or CI, ever.
 */
class PortalBenjiPaysPayOnlineTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-key-never-real';

    private const STRIPE_URL = 'https://invoice.stripe.com/i/acct_synthetic/test_synthetic';

    private const LINK_URL = 'https://portal.benjipays.com/pay/tok_synthetic';

    private static int $seq = 0;

    private Client $client;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('portal_enabled', '1');
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '1');

        $this->client = Client::create(['name' => 'Portal Corp']);
        $this->person = $this->portalUser($this->client, 'portal-2065@example.test');
    }

    private function portalUser(Client $client, string $email): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => $email,
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ]);
    }

    private function invoice(array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-2065-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
            'qbo_invoice_id' => '1042',
            'stripe_invoice_url' => self::STRIPE_URL,
        ], $attrs));
    }

    private function fakeMint(string $url = self::LINK_URL, string $expiresAt = '2030-01-01T12:00:00.000Z'): void
    {
        Http::fake(['https://api.benjipays.com/v2/payment-links/applied/*' => Http::response(['url' => $url, 'expiresAt' => $expiresAt])]);
    }

    private function pay(Invoice $invoice)
    {
        return $this->actingAs($this->person, 'portal')->post(route('portal.invoices.pay-online', $invoice));
    }

    // ── the action ──

    public function test_pay_online_mints_an_applied_link_and_redirects_to_it(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->pay($invoice)->assertStatus(302)->assertRedirect(self::LINK_URL);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.benjipays.com/v2/payment-links/applied/1042'
            && $r->method() === 'POST'
            && $r->hasHeader('x-api-key', self::KEY)
            && json_decode($r->body(), true) === ['allowSavedPaymentMethods' => false]);
    }

    public function test_another_clients_invoice_is_404_and_mints_nothing(): void
    {
        // The named red-check: remove the ownership check and this must fail.
        $this->fakeMint();
        $other = Client::create(['name' => 'Other Corp']);
        $invoice = $this->invoice(['client_id' => $other->id]);

        $this->pay($invoice)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_an_invoice_in_a_non_portal_status_is_404(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice(['status' => InvoiceStatus::Draft]);

        $this->pay($invoice)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_toggle_off_redirects_to_the_stripe_link_without_a_vendor_call(): void
    {
        // A form submitted after the toggle was turned off: the action re-reads it.
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '0');
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->pay($invoice)->assertRedirect(self::STRIPE_URL);
        Http::assertNothingSent();
    }

    public function test_no_qbo_id_redirects_to_the_stripe_link_without_a_vendor_call(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice(['qbo_invoice_id' => null]);

        $this->pay($invoice)->assertRedirect(self::STRIPE_URL);
        Http::assertNothingSent();
    }

    public function test_mint_failure_falls_back_to_stripe_when_present(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['detail' => 'VENDOR-SECRET-TEXT '.self::KEY], 400)]);
        $invoice = $this->invoice();

        $this->pay($invoice)->assertRedirect(self::STRIPE_URL);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('VENDOR-SECRET-TEXT', json_encode(session()->all()));
    }

    public function test_mint_failure_without_stripe_flashes_a_generic_message(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['detail' => 'VENDOR-SECRET-TEXT '.self::KEY], 503)]);
        $invoice = $this->invoice(['stripe_invoice_url' => null]);

        $this->pay($invoice)
            ->assertRedirect(route('portal.invoices.show', $invoice))
            ->assertSessionHas('error', 'Online payment is temporarily unavailable. Please try again later or contact us.');
        $this->assertStringNotContainsString('VENDOR-SECRET-TEXT', json_encode(session()->all()));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));

        // And the rendered page carries no vendor text either.
        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))
            ->assertOk()->assertSee('Online payment is temporarily unavailable')
            ->assertDontSee('VENDOR-SECRET-TEXT')->assertDontSee(self::KEY);
    }

    public function test_mint_failure_on_a_partially_paid_invoice_is_not_sent_to_the_full_amount_stripe_page(): void
    {
        // The named red-check: drop the partial-balance branch in payOnline() and
        // this must fail — a 302 to the full-amount Stripe page with the caveat
        // dropped from the balance note is the #1173 overpayment again.
        Http::fake(['https://api.benjipays.com/*' => Http::response(['detail' => 'VENDOR-SECRET-TEXT '.self::KEY], 503)]);
        $invoice = $this->invoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);

        $this->pay($invoice)
            ->assertRedirect(route('portal.invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertStringContainsString('$500.00', session('error'));
        $this->assertStringNotContainsString('VENDOR-SECRET-TEXT', json_encode(session()->all()));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))
            ->assertOk()->assertSee('would charge the full $500.00')
            ->assertDontSee('VENDOR-SECRET-TEXT')->assertDontSee(self::KEY);
    }

    public function test_mint_failure_on_a_partially_paid_qbo_only_invoice_flashes_the_generic_message(): void
    {
        // No Stripe page exists for this invoice, so the partial-balance branch
        // must not claim a full-amount Stripe page was withheld.
        Http::fake(['https://api.benjipays.com/*' => Http::response(['detail' => 'VENDOR-SECRET-TEXT '.self::KEY], 503)]);
        $invoice = $this->invoice(['status' => InvoiceStatus::Paid, 'stripe_invoice_url' => null]);
        $this->partiallyRevert($invoice, 120.50);

        $this->pay($invoice)
            ->assertRedirect(route('portal.invoices.show', $invoice))
            ->assertSessionHas('error', 'Online payment is temporarily unavailable. Please try again later or contact us.');

        $this->assertStringNotContainsString('would charge the full', json_encode(session()->all()));
        $this->assertStringNotContainsString('VENDOR-SECRET-TEXT', json_encode(session()->all()));

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))
            ->assertOk()->assertSee('Online payment is temporarily unavailable')
            ->assertDontSee('would charge the full');
    }

    public function test_two_clicks_mint_once(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->pay($invoice)->assertRedirect(self::LINK_URL);
        $this->pay($invoice)->assertRedirect(self::LINK_URL);

        Http::assertSentCount(1);
    }

    public function test_cache_is_per_invoice(): void
    {
        $this->fakeMint();
        $a = $this->invoice(['qbo_invoice_id' => '1042']);
        $b = $this->invoice(['qbo_invoice_id' => '1043']);

        $this->pay($a)->assertRedirect(self::LINK_URL);
        $this->pay($b)->assertRedirect(self::LINK_URL);

        Http::assertSentCount(2);
    }

    public function test_cache_ttl_is_expiry_minus_margin_capped_at_one_hour(): void
    {
        $this->fakeMint(expiresAt: now()->addMinutes(10)->toIso8601ZuluString());
        $invoice = $this->invoice();
        Cache::spy();

        $this->pay($invoice);

        Cache::shouldHaveReceived('put')->once()->withArgs(fn ($key, $value, $ttl) => $ttl >= 9 * 60 - 2 && $ttl <= 9 * 60);
    }

    public function test_cache_ttl_caps_at_one_hour(): void
    {
        $this->fakeMint(expiresAt: '2030-01-01T12:00:00.000Z');
        $invoice = $this->invoice();
        Cache::spy();

        $this->pay($invoice);

        Cache::shouldHaveReceived('put')->once()->withArgs(fn ($key, $value, $ttl) => $ttl === 3600);
    }

    public function test_an_already_expired_link_is_not_cached(): void
    {
        $this->fakeMint(expiresAt: now()->addSeconds(30)->toIso8601ZuluString());
        $invoice = $this->invoice();

        $this->pay($invoice)->assertRedirect(self::LINK_URL);
        $this->pay($invoice)->assertRedirect(self::LINK_URL);

        Http::assertSentCount(2);
    }

    public function test_get_cannot_mint(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.pay-online', $invoice))->assertStatus(405);
        Http::assertNothingSent();
    }

    public function test_guest_is_redirected_to_portal_login_without_a_vendor_call(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->post(route('portal.invoices.pay-online', $invoice))->assertRedirect(route('portal.login'));
        Http::assertNothingSent();
    }

    public function test_a_missing_csrf_token_is_419_with_the_real_middleware(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->fakeMint();
        $invoice = $this->invoice();

        $this->actingAs($this->person, 'portal')->withSession(['_token' => 'valid-csrf'])
            ->post(route('portal.invoices.pay-online', $invoice))->assertStatus(419);
        Http::assertNothingSent();
    }

    // ── the buttons ──

    /** The three portal pages that carry Pay Online, plus the balance note on all of them. */
    private function pages(Invoice $invoice): array
    {
        return [
            'dashboard' => route('portal.dashboard'),
            'index' => route('portal.invoices.index'),
            'show' => route('portal.invoices.show', $invoice),
        ];
    }

    public function test_toggle_on_renders_a_post_form_on_all_three_pages(): void
    {
        Http::fake();
        $invoice = $this->invoice();
        $action = route('portal.invoices.pay-online', $invoice);

        foreach ($this->pages($invoice) as $name => $url) {
            $this->actingAs($this->person, 'portal')->get($url)->assertOk()
                ->assertSee('action="'.$action.'"', false)
                ->assertSee('Pay Online')
                ->assertDontSee('href="'.self::STRIPE_URL.'"', false);
        }
        Http::assertNothingSent();
    }

    public function test_toggle_off_renders_byte_identical_stripe_markup(): void
    {
        Http::fake();
        $invoice = $this->invoice();
        $action = route('portal.invoices.pay-online', $invoice);

        $on = [];
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '0');
        foreach ($this->pages($invoice) as $name => $url) {
            $off = $this->actingAs($this->person, 'portal')->get($url)->assertOk()
                ->assertSee('href="'.self::STRIPE_URL.'"', false)
                ->assertDontSee($action, false)
                ->getContent();
            $on[$name] = $off;
        }

        // With no setting row at all (the deploy state) the pages are the same bytes
        // as with the toggle explicitly off, once the CSRF token is normalised.
        Setting::where('key', BenjiPaysConfig::PAY_ONLINE_SETTING)->delete();
        foreach ($this->pages($invoice) as $name => $url) {
            $absent = $this->actingAs($this->person, 'portal')->get($url)->assertOk()->getContent();
            $this->assertSame($this->stripToken($on[$name]), $this->stripToken($absent), $name);
        }
        Http::assertNothingSent();
    }

    private function stripToken(string $html): string
    {
        return preg_replace('/(csrf-token" content="|name="_token" value="|csrfToken = \')[^"\']*/', '$1', $html);
    }

    public function test_no_qbo_id_keeps_the_stripe_button_with_the_toggle_on(): void
    {
        Http::fake();
        $invoice = $this->invoice(['qbo_invoice_id' => null]);

        foreach ($this->pages($invoice) as $url) {
            $this->actingAs($this->person, 'portal')->get($url)->assertOk()
                ->assertSee('href="'.self::STRIPE_URL.'"', false)
                ->assertDontSee(route('portal.invoices.pay-online', $invoice), false);
        }
    }

    public function test_key_cleared_returns_the_portal_to_stripe_with_the_toggle_still_on(): void
    {
        Http::fake();
        Setting::where('key', 'benjipays_api_key')->delete();
        $invoice = $this->invoice();

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))->assertOk()
            ->assertSee('href="'.self::STRIPE_URL.'"', false)
            ->assertDontSee(route('portal.invoices.pay-online', $invoice), false);
        $this->pay($invoice)->assertRedirect(self::STRIPE_URL);
        Http::assertNothingSent();
    }

    public function test_paid_invoice_offers_no_button_either_way(): void
    {
        Http::fake();
        $invoice = $this->invoice(['status' => InvoiceStatus::Paid]);

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))->assertOk()
            ->assertDontSee('Pay Online')
            ->assertDontSee(route('portal.invoices.pay-online', $invoice), false);
        $this->pay($invoice)->assertRedirect(route('portal.invoices.show', $invoice));
        Http::assertNothingSent();
    }

    // ── the balance note ──

    private function partiallyRevert(Invoice $invoice, float $balance): void
    {
        $invoice->statusChangeContext = new \App\Support\InvoiceStatusChangeContext(
            source: \App\Enums\InvoiceStatusChangeSource::QboPull,
            reason: 'QuickBooks reports a balance.',
            qboBalance: $balance,
        );
        $invoice->update(['status' => InvoiceStatus::Posted]);
    }

    public function test_balance_note_drops_the_stripe_full_amount_warning_on_the_benjipays_path(): void
    {
        Http::fake();
        $invoice = $this->invoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))->assertOk()
            ->assertSee('Partially paid')
            ->assertSee('$120.50')
            ->assertDontSee('Paying online will charge the full');
    }

    public function test_balance_note_keeps_the_stripe_warning_when_the_toggle_is_off(): void
    {
        Http::fake();
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '0');
        $invoice = $this->invoice(['status' => InvoiceStatus::Paid]);
        $this->partiallyRevert($invoice, 120.50);

        $this->actingAs($this->person, 'portal')->get(route('portal.invoices.show', $invoice))->assertOk()
            ->assertSee('Partially paid')
            ->assertSee('$120.50')
            ->assertSee('Paying online will charge the full $500.00');
    }
}
