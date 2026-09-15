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

class AutoElevateConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-only-autoelevate-key';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('autoelevate_api_key', self::KEY);
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
        Http::fake(['https://partner-api.autoelevate.com/api/v1/companies?take=1' => Http::response(
            $status === 200 ? ['data' => []] : ['detail' => "\r\n<script>".self::KEY, 'status' => 200], $status
        )]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->post(route('settings.integrations.autoelevate.test'), ['_token' => 'synthetic-csrf-token'])
            ->assertRedirect(route('settings.integrations'));
        $this->assertSame($outcome, Setting::getValue('autoelevate_last_verification_outcome'));
        $this->assertNotNull(Setting::getValue('autoelevate_last_verified_at'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get(route('settings.integrations'))->assertOk()->assertSee('Last connection attempt:')
            ->assertSee('Outcome: '.$outcome)->assertDontSee(self::KEY)->assertDontSee('<script>'.self::KEY, false);
        Http::assertSentCount(1);
    }

    public static function outcomes(): array
    {
        return [[200, 'ok'], [401, '401'], [403, '403'], [400, '400'], [404, 'error'], [406, '406'], [429, '429'], [500, 'error']];
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admin_with_valid_csrf_cannot_test(UserRole $role): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])->assertForbidden();
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('autoelevate_last_verified_at'));
        $this->get(route('settings.integrations'))->assertDontSee('action="'.route('settings.integrations.autoelevate.test').'"', false);
    }

    public static function deniedRoles(): array
    {
        return [[UserRole::Tech], [UserRole::Billing], [UserRole::Contractor]];
    }

    public function test_guest_with_valid_csrf_is_redirected_without_vendor_request(): void
    {
        Http::fake();
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('autoelevate_last_verified_at'));
    }

    #[DataProvider('badTokens')]
    public function test_admin_missing_or_invalid_csrf_is_denied(?string $token): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'valid-csrf'])->post(route('settings.integrations.autoelevate.test'), $token === null ? [] : ['_token' => $token])->assertStatus(419);
        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('autoelevate_last_verified_at'));
    }

    public static function badTokens(): array
    {
        return [[null], ['wrong-csrf']];
    }

    public function test_transport_failure_is_safe_in_flash_and_persisted_state(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(self::KEY));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('error');
        $this->assertSame('transport', Setting::getValue('autoelevate_last_verification_outcome'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee(self::KEY);
    }

    public function test_invalid_stored_key_records_error_without_transport(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::KEY."\r\n");
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])
            ->assertRedirect(route('settings.integrations'));
        $this->assertSame('configuration', Setting::getValue('autoelevate_last_verification_outcome'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_seventh_attempt_is_throttled_without_transport_or_state_change(): void
    {
        Http::fake(['https://partner-api.autoelevate.com/api/v1/companies?take=1' => Http::response('', 200)]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        for ($i = 0; $i < 6; $i++) {
            $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])->assertRedirect();
        }
        $stamp = Setting::getValue('autoelevate_last_verified_at');
        $this->withSession(['_token' => 'csrf'])->post(route('settings.integrations.autoelevate.test'), ['_token' => 'csrf'])->assertStatus(429);
        Http::assertSentCount(6);
        $this->assertSame($stamp, Setting::getValue('autoelevate_last_verified_at'));
    }

    #[DataProvider('badTokens')]
    public function test_save_requires_real_csrf(?string $token): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'valid-csrf'])->post(route('settings.integrations.autoelevate.update'), ['api_key' => 'replacement', '_token' => $token])->assertStatus(419);
        $this->assertSame(self::KEY, \App\Support\AutoElevateConfig::get('api_key'));
        Http::assertNothingSent();
    }

    public function test_get_cannot_trigger_connection_check(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.integrations.autoelevate.test'))->assertStatus(405);
        Http::assertNothingSent();
    }
}
