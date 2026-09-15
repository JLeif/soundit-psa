<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\AutoElevateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AutoElevate API key on the RMM & Monitoring tab: encrypted at rest,
 * blank-submit-safe, replaceable, and never echoed or logged.
 *
 * Every key below is a dummy value; none of them is a real credential.
 */
class AutoElevateApiKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const MASK = '••••••••';

    private const DUMMY_KEY = 'bp_test_dummy_0123456789abcdef';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake();
        $this->user = User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_the_card_renders_an_empty_password_field_when_no_key_is_stored(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('AutoElevate')
            ->assertSee('id="autoelevate_api_key"', false)
            ->assertSee('type="password"', false)
            ->assertSee('Not configured');
    }

    public function test_saving_a_key_stores_it_encrypted_not_plaintext(): void
    {
        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => self::DUMMY_KEY])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $raw = Setting::getValue('autoelevate_api_key');
        $this->assertNotEmpty($raw);
        $this->assertNotSame(self::DUMMY_KEY, $raw, 'the key must be encrypted at rest');
        $this->assertStringNotContainsString(self::DUMMY_KEY, (string) $raw);

        $this->assertSame(self::DUMMY_KEY, AutoElevateConfig::get('api_key'));
        $this->assertTrue(AutoElevateConfig::isConfigured());
    }

    public function test_a_stored_key_is_masked_in_the_form_and_never_rendered(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);

        $html = $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Key stored')
            ->getContent();

        $this->assertStringNotContainsString(self::DUMMY_KEY, $html);
        $this->assertStringNotContainsString((string) Setting::getValue('autoelevate_api_key'), $html, 'the ciphertext must not be rendered either');
        $this->assertMatchesRegularExpression('/id="autoelevate_api_key"[^>]*value=""/s', $html);
        $this->assertMatchesRegularExpression('/id="autoelevate_api_key"[^>]*placeholder="'.preg_quote(self::MASK, '/').'"/s', $html);
    }

    public function test_blank_submit_keeps_the_stored_key(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame(self::DUMMY_KEY, AutoElevateConfig::get('api_key'));
    }

    public function test_mask_placeholder_submit_keeps_the_stored_key(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => self::MASK])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::DUMMY_KEY, AutoElevateConfig::get('api_key'));
    }

    public function test_a_new_key_replaces_the_stored_one(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => '  bp_test_replacement_key_9876  '])
            ->assertSessionHasNoErrors();

        $this->assertSame('bp_test_replacement_key_9876', AutoElevateConfig::get('api_key'));
    }

    public function test_the_success_flash_never_contains_the_key(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => self::DUMMY_KEY]);

        $flash = (string) $response->baseResponse->getSession()->get('success');
        $this->assertNotSame('', $flash);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $flash);
    }

    public function test_saving_writes_nothing_to_the_log(): void
    {
        Log::shouldReceive('info', 'warning', 'error', 'debug')->never();

        $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => self::DUMMY_KEY])
            ->assertSessionHasNoErrors();
    }

    public function test_an_overlong_key_is_rejected_and_the_stored_key_survives(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);

        $this->actingAs($this->user)
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => str_repeat('x', 4097)])
            ->assertSessionHasErrors('api_key')
            ->assertSessionMissing('_old_input.api_key');

        $this->get(route('settings.integrations'))
            ->assertOk()
            ->assertDontSee(str_repeat('x', 4097), false);

        $this->assertSame(self::DUMMY_KEY, AutoElevateConfig::get('api_key'));
    }

    public function test_a_key_at_the_storage_bound_round_trips_byte_identically(): void
    {
        $key = str_repeat('x', 4096);

        $this->actingAs($this->user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => $key])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame($key, AutoElevateConfig::get('api_key'));
    }

    public function test_a_guest_cannot_save_a_key(): void
    {
        $this->post(route('settings.integrations.autoelevate.update'), ['api_key' => self::DUMMY_KEY])
            ->assertRedirect(route('login'));

        $this->assertNull(Setting::getValue('autoelevate_api_key'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminRoles')]
    public function test_non_admin_users_cannot_replace_the_stored_key(UserRole $role): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);
        $ciphertext = Setting::getValue('autoelevate_api_key');

        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)
            ->post(route('settings.integrations.autoelevate.update'), ['api_key' => 'bp_test_unauthorized_replacement'])
            ->assertForbidden();

        $this->assertSame($ciphertext, Setting::getValue('autoelevate_api_key'), $role->value);
    }

    public static function nonAdminRoles(): array
    {
        return [
            'tech' => [UserRole::Tech],
            'billing' => [UserRole::Billing],
            'contractor' => [UserRole::Contractor],
        ];
    }

    public function test_replacement_clears_old_verification_but_blank_preserves_it(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);
        Setting::setValue('autoelevate_last_verified_at', '2026-01-01T00:00:00Z');
        Setting::setValue('autoelevate_last_verification_outcome', 'ok');
        $this->actingAs($this->user)->post(route('settings.integrations.autoelevate.update'), ['api_key' => ''])->assertRedirect();
        $this->assertSame('ok', Setting::getValue('autoelevate_last_verification_outcome'));
        $this->post(route('settings.integrations.autoelevate.update'), ['api_key' => 'synthetic-replacement'])->assertRedirect();
        $this->assertNull(Setting::getValue('autoelevate_last_verified_at'));
        $this->assertNull(Setting::getValue('autoelevate_last_verification_outcome'));
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_control_characters_are_rejected_without_flash_or_replacement(): void
    {
        Setting::setEncrypted('autoelevate_api_key', self::DUMMY_KEY);
        $this->actingAs($this->user)->post(route('settings.integrations.autoelevate.update'), ['api_key' => "synthetic\r\nInjected: secret"])
            ->assertSessionHasErrors('api_key')->assertSessionMissing('_old_input.api_key');
        $this->assertSame(self::DUMMY_KEY, AutoElevateConfig::get('api_key'));
    }

    public function test_guide_and_card_are_inside_rmm_tab(): void
    {
        $html = $this->actingAs($this->user)->get(route('settings.integrations'))->assertOk()->getContent();
        $rmm = strpos($html, 'id="rmm"');
        $card = strpos($html, 'id="autoelevate_api_key"');
        $this->assertGreaterThan($rmm, $card);
        $this->assertStringContainsString('<summary>AutoElevate setup guide</summary>', $html);
        $this->assertStringContainsString('AE-BEARER', $html);
    }

    public function test_config_reads_null_when_nothing_is_stored(): void
    {
        $this->assertNull(AutoElevateConfig::get('api_key'));
        $this->assertFalse(AutoElevateConfig::isConfigured());
    }
}
