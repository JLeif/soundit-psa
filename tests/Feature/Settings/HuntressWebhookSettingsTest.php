<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\HuntressConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HuntressWebhookSettingsTest extends TestCase
{
    use RefreshDatabase;

    // Synthetic Svix-compatible value; never a vendor credential.
    private const SECRET = 'whsec_c3ludGhldGljLXRlc3Qtc2VjcmV0';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): self
    {
        return $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['signing_secret' => self::SECRET, 'account_id' => '12345', 'webhooks_enabled' => '0'], $overrides);
    }

    private function save(array $payload)
    {
        return $this->post(route('settings.integrations.huntress-webhooks.update'), $payload);
    }

    public function test_save_encrypts_secret_and_is_dark_and_separate_from_api_sync(): void
    {
        Setting::setValue('huntress_enabled', '0');
        Log::shouldReceive('info', 'warning', 'error', 'debug')->never();
        $response = $this->admin()->save($this->payload());
        $response->assertRedirect(route('settings.integrations'))->assertSessionHasNoErrors()
            ->assertSessionMissing('_old_input.signing_secret');
        $this->assertSame(self::SECRET, HuntressConfig::get('webhook_signing_secret'));
        $this->assertStringNotContainsString(self::SECRET, Setting::getValue('huntress_webhook_signing_secret'));
        $this->assertSame('12345', HuntressConfig::get('webhook_account_id'));
        $this->assertFalse(HuntressConfig::webhooksEnabled());
        $this->assertSame('0', Setting::getValue('huntress_enabled'));
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_admin_form_is_write_only_and_renders_no_ciphertext_or_old_secret(): void
    {
        Setting::setEncrypted('huntress_webhook_signing_secret', self::SECRET);
        $html = $this->admin()->withSession(['_old_input' => ['signing_secret' => 'SYNTHETIC_OLD_SECRET']])
            ->get(route('settings.integrations'))->assertOk()->assertSee('Webhook linking enabled')->getContent();
        $this->assertMatchesRegularExpression('/id="huntress_webhook_signing_secret"[^>]*value=""/s', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString('SYNTHETIC_OLD_SECRET', $html);
        $this->assertStringNotContainsString(Setting::getValue('huntress_webhook_signing_secret'), $html);
    }

    public function test_explicit_enable_then_disable_preserves_secret_and_other_huntress_switch(): void
    {
        Setting::setValue('huntress_enabled', '1');
        $this->admin()->save($this->payload(['webhooks_enabled' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue(HuntressConfig::webhooksEnabled());
        $ciphertext = Setting::getValue('huntress_webhook_signing_secret');
        $this->save($this->payload(['signing_secret' => '', 'webhooks_enabled' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse(HuntressConfig::webhooksEnabled());
        $this->assertSame($ciphertext, Setting::getValue('huntress_webhook_signing_secret'));
        $this->assertSame('1', Setting::getValue('huntress_enabled'));
    }

    #[DataProvider('preservedSecrets')]
    public function test_blank_missing_or_mask_preserves_existing_secret(array $secret): void
    {
        Setting::setEncrypted('huntress_webhook_signing_secret', self::SECRET);
        $ciphertext = Setting::getValue('huntress_webhook_signing_secret');
        $this->admin()->save(array_merge(['account_id' => '54321', 'webhooks_enabled' => '1'], $secret))->assertSessionHasNoErrors();
        $this->assertSame($ciphertext, Setting::getValue('huntress_webhook_signing_secret'));
        $this->assertSame('54321', HuntressConfig::get('webhook_account_id'));
        $this->assertTrue(HuntressConfig::webhooksEnabled());
    }

    public static function preservedSecrets(): array
    {
        return [[[]], [['signing_secret' => '']], [['signing_secret' => '   ']], [['signing_secret' => '••••••••']]];
    }

    public function test_replacement_secret_round_trips_and_blank_disabled_setup_is_allowed(): void
    {
        $this->admin()->save($this->payload(['signing_secret' => '', 'account_id' => '']))->assertSessionHasNoErrors();
        $this->assertNull(HuntressConfig::get('webhook_signing_secret'));
        $this->save($this->payload())->assertSessionHasNoErrors();
        $replacement = 'whsec_'.base64_encode('synthetic replacement');
        $this->save($this->payload(['signing_secret' => '  '.$replacement.'  ']))->assertSessionHasNoErrors();
        $this->assertSame($replacement, HuntressConfig::get('webhook_signing_secret'));
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_write_nothing_and_never_flash_secrets(array $overrides, string $field): void
    {
        Setting::setEncrypted('huntress_webhook_signing_secret', self::SECRET);
        Setting::setValue('huntress_webhook_account_id', '12345');
        Setting::setValue('huntress_webhooks_enabled', '1');
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->admin()->save($this->payload($overrides))->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.signing_secret');
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public static function invalidSettings(): array
    {
        return [
            'secret array' => [['signing_secret' => ['secret']], 'signing_secret'],
            'secret too long' => [['signing_secret' => str_repeat('x', 4097)], 'signing_secret'],
            'invalid base64' => [['signing_secret' => 'whsec_!not-base64!'], 'signing_secret'],
            'empty prefix' => [['signing_secret' => 'whsec_'], 'signing_secret'],
            'missing account on enable' => [['account_id' => '', 'webhooks_enabled' => '1'], 'account_id'],
            'account array' => [['account_id' => ['123']], 'account_id'],
            'negative account' => [['account_id' => '-1'], 'account_id'],
            'zero account' => [['account_id' => '0'], 'account_id'],
            'fraction account' => [['account_id' => '1.5'], 'account_id'],
            'exponent account' => [['account_id' => '1e3'], 'account_id'],
            'overflow account' => [['account_id' => '9223372036854775808'], 'account_id'],
            'invalid switch' => [['webhooks_enabled' => 'yes'], 'webhooks_enabled'],
            'switch array' => [['webhooks_enabled' => ['1']], 'webhooks_enabled'],
            'null switch' => [['webhooks_enabled' => null], 'webhooks_enabled'],
        ];
    }

    public function test_enabling_without_stored_or_submitted_secret_is_refused(): void
    {
        $this->admin()->save($this->payload(['signing_secret' => '', 'webhooks_enabled' => '1']))
            ->assertSessionHasErrors('signing_secret');
        $this->assertFalse(HuntressConfig::webhooksEnabled());
        $this->assertNull(HuntressConfig::get('webhook_account_id'));
    }

    public function test_corrupt_stored_secret_cannot_enable_but_can_be_disabled(): void
    {
        Setting::setValue('huntress_webhook_signing_secret', 'invalid ciphertext');
        $this->admin()->save($this->payload(['signing_secret' => '', 'webhooks_enabled' => '1']))
            ->assertSessionHasErrors('signing_secret');
        $this->assertFalse(HuntressConfig::webhooksEnabled());
        $this->save($this->payload(['signing_secret' => '', 'webhooks_enabled' => '0']))->assertSessionHasNoErrors();
    }

    public function test_guest_cannot_write_any_settings(): void
    {
        $this->save($this->payload(['webhooks_enabled' => '1']))->assertRedirect(route('login'));
        $this->assertNull(HuntressConfig::get('webhook_signing_secret'));
        $this->assertNull(HuntressConfig::get('webhook_account_id'));
        $this->assertFalse(HuntressConfig::webhooksEnabled());
    }

    #[DataProvider('nonAdmins')]
    public function test_non_admin_cannot_see_form_or_write_settings(UserRole $role): void
    {
        Setting::setEncrypted('huntress_webhook_signing_secret', self::SECRET);
        Setting::setValue('huntress_webhook_account_id', '12345');
        Setting::setValue('huntress_webhooks_enabled', '0');
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->save($this->payload(['account_id' => '54321', 'webhooks_enabled' => '1']))->assertForbidden();
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
        $this->get(route('settings.integrations'))->assertDontSee('id="huntress_webhook_signing_secret"', false);
    }

    public static function nonAdmins(): array
    {
        return [[UserRole::Tech], [UserRole::Billing], [UserRole::Contractor]];
    }
}
