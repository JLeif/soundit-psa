<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Admin-only "Preview BenjiPays link" on the staff invoice page (#2065). Faked vendor, no live call. */
class InvoiceBenjiPaysPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-only-benjipays-key';

    private const LINK_URL = 'https://portal.benjipays.com/pay/tok_synthetic_preview';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
    }

    private function invoice(array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'client_id' => Client::create(['name' => 'Acme Corp'])->id,
            'invoice_number' => 'INV-PRV-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(10),
            'due_date' => now()->addDays(20),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
            'qbo_invoice_id' => '1042',
        ], $attrs));
    }

    private function fakeMint(): void
    {
        Http::fake(['https://api.benjipays.com/v2/payment-links/applied/*' => Http::response(['url' => self::LINK_URL, 'expiresAt' => '2030-01-01T12:00:00.000Z'])]);
    }

    private function realCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_admin_preview_mints_and_renders_url_and_expiry(): void
    {
        $this->realCsrf();
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->withSession(['_token' => 'csrf'])
            ->post(route('invoices.benjipays-preview', $invoice), ['_token' => 'csrf'])
            ->assertRedirect(route('invoices.show', $invoice));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.benjipays.com/v2/payment-links/applied/1042'
            && json_decode($r->body(), true) === ['allowSavedPaymentMethods' => false]);

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="'.self::LINK_URL.'"', false)
            ->assertSee('href="'.self::LINK_URL.'"', false)
            ->assertSee('Expires 2030-01-01T12:00:00+00:00')
            ->assertDontSee(self::KEY);
    }

    public function test_the_button_is_rendered_for_admins_on_eligible_invoices_only(): void
    {
        Http::fake();
        $action = fn (Invoice $i) => 'action="'.route('invoices.benjipays-preview', $i).'"';
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $eligible = $this->invoice();
        $this->get(route('invoices.show', $eligible))->assertOk()->assertSee($action($eligible), false)->assertSee('Preview BenjiPays link');

        $noQbo = $this->invoice(['qbo_invoice_id' => null]);
        $this->get(route('invoices.show', $noQbo))->assertOk()->assertDontSee($action($noQbo), false);

        $paid = $this->invoice(['status' => InvoiceStatus::Paid]);
        $this->get(route('invoices.show', $paid))->assertOk()->assertDontSee($action($paid), false);

        Setting::where('key', 'benjipays_api_key')->delete();
        $this->get(route('invoices.show', $eligible))->assertOk()->assertDontSee($action($eligible), false);
        Http::assertNothingSent();
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admin_with_valid_csrf_is_403_and_sees_no_button(UserRole $role): void
    {
        $this->realCsrf();
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->withSession(['_token' => 'csrf'])
            ->post(route('invoices.benjipays-preview', $invoice), ['_token' => 'csrf'])->assertForbidden();
        Http::assertNothingSent();
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertDontSee('action="'.route('invoices.benjipays-preview', $invoice).'"', false)
            ->assertDontSee('Preview BenjiPays link');
    }

    public static function deniedRoles(): array
    {
        return [[UserRole::Tech], [UserRole::Billing], [UserRole::Contractor]];
    }

    public function test_guest_is_redirected_to_login_without_a_vendor_call(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->post(route('invoices.benjipays-preview', $invoice))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_admin_missing_csrf_is_419(): void
    {
        $this->realCsrf();
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'valid'])->post(route('invoices.benjipays-preview', $invoice))->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_mint_failure_flashes_the_status_only_message(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['detail' => 'VENDOR-SECRET-TEXT '.self::KEY], 403)]);
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->post(route('invoices.benjipays-preview', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('error')->assertSessionMissing('benjipays_preview');
        $this->assertStringContainsString('403', session('error'));
        $this->assertStringNotContainsString('VENDOR-SECRET-TEXT', json_encode(session()->all()));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
    }

    public function test_preview_is_independent_of_the_portal_toggle(): void
    {
        $this->assertNull(Setting::getValue('benjipays_pay_online'));
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->post(route('invoices.benjipays-preview', $invoice))->assertSessionHas('benjipays_preview');
        Http::assertSentCount(1);
    }

    public function test_preview_is_throttled_at_six_per_minute(): void
    {
        $this->fakeMint();
        $invoice = $this->invoice();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('invoices.benjipays-preview', $invoice))->assertRedirect(route('invoices.show', $invoice));
        }
        $this->post(route('invoices.benjipays-preview', $invoice))->assertStatus(429);
    }
}
