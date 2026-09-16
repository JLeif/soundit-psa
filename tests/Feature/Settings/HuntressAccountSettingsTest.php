<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HuntressAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function configure(): void
    {
        Setting::setEncrypted('huntress_api_key', 'synthetic-key');
        Setting::setEncrypted('huntress_api_secret', 'synthetic-secret');
    }

    private function save(array $changes = [])
    {
        return $this->post(route('settings.integrations.huntress-webhooks.update'), array_replace([
            'account_id' => '12345', 'signing_secret' => '', 'webhooks_enabled' => '0',
        ], $changes));
    }

    public function test_unconfigured_form_explains_exact_source_and_detection_refuses_without_network(): void
    {
        $this->get(route('settings.integrations'))->assertOk()
            ->assertSee('GET https://api.huntress.io/v1/account')
            ->assertSee('HTTP Basic auth')->assertSee('not shown on the Huntress webhook page')
            ->assertDontSee('id="detect-huntress-account"', false);
        $this->postJson(route('settings.integrations.huntress.account'))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_configured_form_is_passive_and_detect_returns_only_id_without_writes(): void
    {
        $this->configure();
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->get(route('settings.integrations'))->assertOk()->assertSee('Detect account ID')
            ->assertSee('mismatches are rejected')->assertDontSee('synthetic-secret');
        Http::assertNothingSent();
        Http::fake(['https://api.huntress.io/v1/account' => Http::response(['account' => ['id' => 12345, 'name' => 'private-name']])]);
        $this->postJson(route('settings.integrations.huntress.account'))->assertOk()->assertExactJson(['account_id' => '12345']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.huntress.io/v1/account'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-key:synthetic-secret')));
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
    }

    public function test_save_derives_blank_id_and_matching_value_is_accepted(): void
    {
        $this->configure();
        Http::fake(['https://api.huntress.io/v1/account' => Http::response(['account' => ['id' => 12345]])]);
        $this->save(['account_id' => '', 'webhooks_enabled' => '1', 'signing_secret' => base64_encode('synthetic-signing')])->assertSessionHasNoErrors();
        $this->assertSame('12345', Setting::getValue('huntress_webhook_account_id'));
        $this->save(['webhooks_enabled' => '1'])->assertSessionHasNoErrors();
        Http::assertSentCount(2);
    }

    public function test_mismatch_rejects_all_changes_and_renders_error_without_flashing_secrets(): void
    {
        $this->configure();
        Http::fake(['https://api.huntress.io/v1/account' => Http::response(['account' => ['id' => 54321]])]);
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->save(['signing_secret' => base64_encode('synthetic-signing')])->assertSessionHasErrors('account_id')
            ->assertSessionMissing('_old_input.signing_secret');
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
        $this->get(route('settings.integrations'))->assertOk()->assertSee('does not match')->assertSee('54321');
        Http::assertSentCount(1);
    }

    #[DataProvider('badResponses')]
    public function test_bad_vendor_result_is_sanitized_and_persists_nothing(array $body, int $status): void
    {
        $this->configure();
        Http::fake(['https://api.huntress.io/v1/account' => Http::response($body, $status, ['Location' => 'https://redirect.invalid'])]);
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->postJson(route('settings.integrations.huntress.account'))->assertStatus(422)->assertDontSee('private-marker');
        $this->save()->assertSessionHasErrors('account_id');
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
        Http::assertSentCount(2);
    }

    public static function badResponses(): array
    {
        return [
            [[], 200], [['account' => ['id' => 0]], 200], [['account' => ['id' => true]], 200],
            [['account' => ['id' => 1.5]], 200], [['account' => ['id' => '1e3']], 200],
            [['account' => ['id' => '9223372036854775808']], 200], [['account' => ['id' => ['123']]], 200],
            [['error' => 'private-marker'], 403], [['error' => 'private-marker'], 429],
            [['account' => ['id' => 12345]], 302], [['error' => 'private-marker'], 500],
        ];
    }

    public function test_connection_failure_and_offline_disable(): void
    {
        $this->configure();
        Setting::setValue('huntress_webhook_account_id', '12345');
        Setting::setValue('huntress_webhooks_enabled', '1');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('private-marker'));
        $this->save(['account_id' => '54321'])->assertSessionHasErrors('account_id');
        $this->assertStringNotContainsString('private-marker', json_encode(session()->all()));
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame('0', Setting::getValue('huntress_webhooks_enabled'));
        $this->assertSame('12345', Setting::getValue('huntress_webhook_account_id'));
    }

    public function test_detection_has_csrf_and_throttle_guards(): void
    {
        $this->configure();
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $url = route('settings.integrations.huntress.account');
        $this->withSession(['_token' => 'synthetic-csrf']);
        $this->postJson($url)->assertStatus(419);
        $this->postJson($url, ['_token' => 'wrong'])->assertStatus(419);
        Http::assertNothingSent();
        Http::fake(['https://api.huntress.io/v1/account' => Http::response(['account' => ['id' => 12345]])]);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($url, ['_token' => 'synthetic-csrf'])->assertOk();
        }
        $this->postJson($url, ['_token' => 'synthetic-csrf'])->assertStatus(429);
        Http::assertSentCount(6);
    }

    public function test_transport_does_not_follow_redirects_and_has_bounded_timeouts(): void
    {
        $this->configure();
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);

            return Http::response(['account' => ['id' => 12345]]);
        });
        $this->postJson(route('settings.integrations.huntress.account'))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_detection_requires_admin_and_post(): void
    {
        $this->configure();
        $this->get(route('settings.integrations.huntress.account'))->assertStatus(405);
        foreach ([UserRole::Tech, UserRole::Billing, UserRole::Contractor] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson(route('settings.integrations.huntress.account'))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson(route('settings.integrations.huntress.account'))->assertUnauthorized();
        Http::assertNothingSent();
    }
}
