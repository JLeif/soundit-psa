<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BenjiPaysConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-only-benjipays-key';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        // Keep the real web stack/token comparison; override ONLY Laravel's testing bypass.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    #[DataProvider('outcomes')]
    public function test_admin_with_real_csrf_records_safe_attempt(int $status, string $outcome): void
    {
        Http::fake(['https://api.benjipays.com/v2/gateways' => Http::response(
            $status === 200 ? ['data' => []] : ['detail' => "\r\n<script>".self::KEY, 'status' => 200], $status
        )]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->post(route('settings.integrations.benjipays.test'), ['_token' => 'synthetic-csrf-token'])
            ->assertRedirect(route('settings.integrations'));
        $this->assertSame($outcome, Setting::getValue('benjipays_last_verification_outcome'));
        $this->assertNotNull(Setting::getValue('benjipays_last_verified_at'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get(route('settings.integrations'))->assertOk()->assertSee('Last connection attempt:')
            ->assertSee('Outcome: '.$outcome)->assertDontSee(self::KEY)->assertDontSee('<script>'.self::KEY, false);
        Http::assertSentCount(1);
    }

    public static function outcomes(): array
    {
        return [[200, 'ok'], [401, '401'], [403, '403'], [400, 'error'], [404, 'error'], [429, 'error'], [500, 'error']];
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admin_with_valid_csrf_cannot_test(UserRole $role): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.benjipays.test'), ['_token' => 'csrf'])->assertForbidden();
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('benjipays_last_verified_at'));
        $this->get(route('settings.integrations'))->assertDontSee('action="'.route('settings.integrations.benjipays.test').'"', false);
    }

    public static function deniedRoles(): array
    {
        return [[UserRole::Tech], [UserRole::Billing], [UserRole::Contractor]];
    }

    public function test_guest_with_valid_csrf_is_redirected_without_vendor_request(): void
    {
        Http::fake();
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.benjipays.test'), ['_token' => 'csrf'])->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('benjipays_last_verified_at'));
    }

    #[DataProvider('badTokens')]
    public function test_admin_missing_or_invalid_csrf_is_denied(?string $token): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'valid-csrf'])->post(route('settings.integrations.benjipays.test'), $token === null ? [] : ['_token' => $token])->assertStatus(419);
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('benjipays_last_verified_at'));
    }

    public static function badTokens(): array
    {
        return [[null], ['wrong-csrf']];
    }

    public function test_transport_failure_is_safe_in_flash_and_persisted_state(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(self::KEY));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.benjipays.test'), ['_token' => 'csrf'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('error');
        $this->assertSame('error', Setting::getValue('benjipays_last_verification_outcome'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee(self::KEY);
    }

    public function test_invalid_stored_key_records_error_without_transport(): void
    {
        Setting::setEncrypted('benjipays_api_key', self::KEY."\r\n");
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.benjipays.test'), ['_token' => 'csrf'])
            ->assertRedirect(route('settings.integrations'));
        $this->assertSame('error', Setting::getValue('benjipays_last_verification_outcome'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_get_cannot_trigger_connection_check(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.integrations.benjipays.test'))->assertStatus(405);
        Http::assertNothingSent();
    }
}
