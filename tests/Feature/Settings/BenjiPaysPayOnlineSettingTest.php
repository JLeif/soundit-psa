<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\BenjiPaysConfig;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The `benjipays_pay_online` toggle on the Integrations BenjiPays card (#2065). */
class BenjiPaysPayOnlineSettingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-only-benjipays-key';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
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

    public function test_default_is_off_and_never_read_as_on_without_a_row(): void
    {
        $this->assertNull(Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
        $this->assertFalse(BenjiPaysConfig::payOnlineEnabled());
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->assertFalse(BenjiPaysConfig::payOnlineEnabled());
    }

    public function test_on_requires_a_stored_key(): void
    {
        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '1');
        $this->assertFalse(BenjiPaysConfig::payOnlineEnabled());
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->assertTrue(BenjiPaysConfig::payOnlineEnabled());
    }

    public function test_admin_can_turn_it_on_and_off_with_real_csrf(): void
    {
        $this->realCsrf();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->withSession(['_token' => 'csrf'])
            ->post(route('settings.integrations.benjipays.pay-online'), ['_token' => 'csrf', 'enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('success');
        $this->assertSame('1', Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
        $this->assertTrue(BenjiPaysConfig::payOnlineEnabled());

        $this->withSession(['_token' => 'csrf'])
            ->post(route('settings.integrations.benjipays.pay-online'), ['_token' => 'csrf'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('success');
        $this->assertSame('0', Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
        $this->assertFalse(BenjiPaysConfig::payOnlineEnabled());
        Http::assertNothingSent();
    }

    public function test_turning_on_without_a_key_is_refused_and_stays_off(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->post(route('settings.integrations.benjipays.pay-online'), ['enabled' => '1'])
            ->assertRedirect(route('settings.integrations'))->assertSessionHas('error');
        $this->assertSame('0', Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
        $this->assertFalse(BenjiPaysConfig::payOnlineEnabled());
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admin_with_valid_csrf_cannot_flip_it(UserRole $role): void
    {
        $this->realCsrf();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->withSession(['_token' => 'csrf'])
            ->post(route('settings.integrations.benjipays.pay-online'), ['_token' => 'csrf', 'enabled' => '1'])
            ->assertForbidden();
        $this->assertNull(Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
        $this->get(route('settings.integrations'))->assertOk()
            ->assertDontSee('action="'.route('settings.integrations.benjipays.pay-online').'"', false);
    }

    public static function deniedRoles(): array
    {
        return [[UserRole::Tech], [UserRole::Billing], [UserRole::Contractor]];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post(route('settings.integrations.benjipays.pay-online'), ['enabled' => '1'])->assertRedirect(route('login'));
        $this->assertNull(Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
    }

    public function test_admin_missing_csrf_is_419(): void
    {
        $this->realCsrf();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->withSession(['_token' => 'valid'])
            ->post(route('settings.integrations.benjipays.pay-online'), ['enabled' => '1'])->assertStatus(419);
        $this->assertNull(Setting::getValue(BenjiPaysConfig::PAY_ONLINE_SETTING));
    }

    public function test_the_switch_is_offered_to_admins_only_when_a_key_is_stored(): void
    {
        $action = 'action="'.route('settings.integrations.benjipays.pay-online').'"';
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.integrations'))->assertOk()->assertDontSee($action, false);

        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->get(route('settings.integrations'))->assertOk()->assertSee($action, false)
            ->assertSee('Use BenjiPays for portal Pay Online')
            ->assertDontSee('id="benjipays_pay_online" checked', false);

        Setting::setValue(BenjiPaysConfig::PAY_ONLINE_SETTING, '1');
        $this->get(route('settings.integrations'))->assertOk()->assertSee('id="benjipays_pay_online" checked', false);
    }
}
