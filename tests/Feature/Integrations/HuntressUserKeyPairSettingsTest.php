<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use App\Services\Mcp\StaffHuntressActionToolExecutor;
use App\Support\HuntressConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settings > Integrations > Huntress: the user-based API key pair that the
 * escalation-resolve write lane reads (HuntressConfig::isWriteConfigured),
 * issues #1023 and #581.
 *
 * WHAT THESE CONTROLS PIN
 * -----------------------
 * The pair can be entered through the real form route and is stored
 * encrypted (the raw row is not the plaintext; getEncrypted returns it); the
 * page never renders a stored value back; a blank submit keeps the stored
 * pair; the explicit clear control removes both halves; validation refuses
 * over-length, a lone half, and clear-plus-new; non-admins cannot write it;
 * the status line reads both states; and both remedy messages name the field
 * that the page actually renders.
 *
 * WHAT THEY DO NOT
 * ----------------
 * No Huntress call is made, and nothing here says the vendor accepts a
 * user-based key for resolution. All values are synthetic.
 */
class HuntressUserKeyPairSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-user-key-7Q2xK9pL';

    private const SECRET = 'synthetic-user-secret-Vb3nM8rT';

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function save(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.huntress.update'), $fields);
    }

    private function raw(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    private function storePair(string $key = self::KEY, string $secret = self::SECRET): void
    {
        Setting::setEncrypted('huntress_user_api_key', $key);
        Setting::setEncrypted('huntress_user_api_secret', $secret);
    }

    // ---- round trip ----

    public function test_the_pair_round_trips_encrypted_through_the_form(): void
    {
        $response = $this->save(['user_api_key' => self::KEY, 'user_api_secret' => self::SECRET]);

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHasNoErrors();

        $rawKey = $this->raw('huntress_user_api_key');
        $rawSecret = $this->raw('huntress_user_api_secret');
        $this->assertNotNull($rawKey, 'the user API key row was not written');
        $this->assertNotNull($rawSecret, 'the user API secret row was not written');
        $this->assertStringNotContainsString(self::KEY, $rawKey, 'the user API key is stored in plaintext');
        $this->assertStringNotContainsString(self::SECRET, $rawSecret, 'the user API secret is stored in plaintext');

        $this->assertSame(self::KEY, Setting::getEncrypted('huntress_user_api_key'));
        $this->assertSame(self::SECRET, Setting::getEncrypted('huntress_user_api_secret'));
        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));
        $this->assertSame(self::SECRET, HuntressConfig::get('user_api_secret'));
        $this->assertTrue(HuntressConfig::isWriteConfigured());
    }

    public function test_saving_the_user_pair_leaves_the_read_pair_untouched(): void
    {
        Setting::setEncrypted('huntress_api_key', 'synthetic-read-key');
        Setting::setEncrypted('huntress_api_secret', 'synthetic-read-secret');

        $this->save(['user_api_key' => self::KEY, 'user_api_secret' => self::SECRET])->assertSessionHasNoErrors();

        $this->assertSame('synthetic-read-key', HuntressConfig::get('api_key'));
        $this->assertSame('synthetic-read-secret', HuntressConfig::get('api_secret'));
    }

    // ---- never echoed ----

    public function test_the_page_renders_both_inputs_and_never_the_stored_values(): void
    {
        $this->storePair();

        $response = $this->actingAs($this->admin())->get(route('settings.integrations'));

        $response->assertOk();
        // Precondition: the inputs really rendered, so an absence below is
        // not the absence of the whole block.
        $response->assertSee('name="user_api_key"', false);
        $response->assertSee('name="user_api_secret"', false);
        $response->assertSee('id="huntress_user_api_key"', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString(self::KEY, $html, 'the stored user API key was rendered into the page');
        $this->assertStringNotContainsString(self::SECRET, $html, 'the stored user API secret was rendered into the page');
        $this->assertStringNotContainsString($this->raw('huntress_user_api_key'), $html, 'the ciphertext was rendered into the page');
    }

    public function test_a_saved_pair_shows_the_saved_hint_and_mask(): void
    {
        $this->storePair();

        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->getContent();
        $block = $this->userPairBlock($html);

        $this->assertSame(2, substr_count($block, 'Saved. Encrypted at rest.'), 'each saved half must show the saved hint');
        $this->assertStringContainsString('placeholder="••••••••"', $block);
        $this->assertStringContainsString('name="clear_user_api_key_pair"', $block);
    }

    public function test_an_unsaved_pair_shows_no_saved_hint_and_no_clear_control(): void
    {
        $block = $this->userPairBlock($this->actingAs($this->admin())->get(route('settings.integrations'))->getContent());

        $this->assertStringNotContainsString('Saved. ', $block);
        $this->assertStringContainsString('placeholder="Enter user API key"', $block);
        $this->assertStringNotContainsString('name="clear_user_api_key_pair"', $block);
    }

    // ---- blank keeps, clear clears ----

    public function test_a_blank_submit_keeps_the_stored_pair(): void
    {
        $this->storePair();
        $before = [$this->raw('huntress_user_api_key'), $this->raw('huntress_user_api_secret')];

        $this->save(['user_api_key' => '', 'user_api_secret' => ''])->assertSessionHasNoErrors();
        $this->save([])->assertSessionHasNoErrors();
        $this->save(['user_api_key' => '••••••••', 'user_api_secret' => '••••••••'])->assertSessionHasNoErrors();

        $this->assertSame($before, [$this->raw('huntress_user_api_key'), $this->raw('huntress_user_api_secret')], 'a blank or masked submit rewrote the stored pair');
        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));
        $this->assertSame(self::SECRET, HuntressConfig::get('user_api_secret'));
    }

    public function test_one_new_half_replaces_only_that_half(): void
    {
        $this->storePair();

        $this->save(['user_api_secret' => 'synthetic-rotated-secret'])->assertSessionHasNoErrors();

        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));
        $this->assertSame('synthetic-rotated-secret', HuntressConfig::get('user_api_secret'));
    }

    public function test_the_clear_control_removes_both_halves(): void
    {
        $this->storePair();
        Setting::setEncrypted('huntress_api_key', 'synthetic-read-key');

        $this->save(['clear_user_api_key_pair' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($this->raw('huntress_user_api_key'));
        $this->assertNull($this->raw('huntress_user_api_secret'));
        $this->assertFalse(HuntressConfig::isWriteConfigured());
        $this->assertSame('synthetic-read-key', HuntressConfig::get('api_key'), 'clearing the user pair must not touch the read key');
    }

    // ---- validation ----

    public function test_over_length_values_are_refused_and_nothing_is_saved(): void
    {
        $this->save(['user_api_key' => str_repeat('k', 501), 'user_api_secret' => self::SECRET])
            ->assertSessionHasErrors('user_api_key');
        $this->save(['user_api_key' => self::KEY, 'user_api_secret' => str_repeat('s', 501)])
            ->assertSessionHasErrors('user_api_secret');

        $this->assertNull($this->raw('huntress_user_api_key'));
        $this->assertNull($this->raw('huntress_user_api_secret'));
    }

    public function test_a_value_at_the_limit_is_accepted(): void
    {
        $this->save(['user_api_key' => str_repeat('k', 500), 'user_api_secret' => str_repeat('s', 500)])
            ->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('k', 500), HuntressConfig::get('user_api_key'));
    }

    public function test_a_lone_half_is_refused_when_nothing_is_stored(): void
    {
        $this->save(['user_api_key' => self::KEY])->assertSessionHasErrors('user_api_secret');
        $this->save(['user_api_secret' => self::SECRET])->assertSessionHasErrors('user_api_key');

        $this->assertNull($this->raw('huntress_user_api_key'), 'a refused lone half was saved');
        $this->assertNull($this->raw('huntress_user_api_secret'), 'a refused lone half was saved');
    }

    public function test_a_refused_lone_half_saves_nothing_else_from_the_submit(): void
    {
        $this->save(['api_key' => 'synthetic-read-key', 'user_api_key' => self::KEY])
            ->assertSessionHasErrors('user_api_secret');

        $this->assertNull($this->raw('huntress_api_key'), 'a refused submit still saved the read key');
    }

    public function test_clear_together_with_a_new_value_is_refused(): void
    {
        $this->storePair();

        $this->save(['clear_user_api_key_pair' => '1', 'user_api_key' => 'synthetic-new-key', 'user_api_secret' => 'synthetic-new-secret'])
            ->assertSessionHasErrors('user_api_key');

        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));
        $this->assertSame(self::SECRET, HuntressConfig::get('user_api_secret'));
    }

    public function test_a_failed_save_does_not_flash_the_pair(): void
    {
        $this->save(['user_api_key' => self::KEY, 'user_api_secret' => str_repeat('s', 501), 'api_key' => str_repeat('r', 501)])
            ->assertSessionHasErrors('user_api_secret');

        // Precondition: something WAS flashed, so absence below is protection.
        $this->assertNotNull(session('_old_input'), 'precondition failed: nothing was flashed');
        $this->assertArrayNotHasKey('user_api_key', session('_old_input'));
        $this->assertArrayNotHasKey('user_api_secret', session('_old_input'));
    }

    public function test_a_non_admin_cannot_write_or_clear_the_pair(): void
    {
        $tech = User::factory()->create(['role' => UserRole::Tech, 'is_active' => true]);

        $this->save(['user_api_key' => self::KEY, 'user_api_secret' => self::SECRET], $tech)->assertForbidden();
        $this->assertNull($this->raw('huntress_user_api_key'));

        $this->storePair();
        $this->save(['clear_user_api_key_pair' => '1'], $tech)->assertForbidden();
        $this->assertSame(self::KEY, HuntressConfig::get('user_api_key'));

        // The read pair stays editable by the same user, as before.
        $this->save(['api_key' => 'synthetic-read-key'], $tech)->assertSessionHasNoErrors();
        $this->assertSame('synthetic-read-key', HuntressConfig::get('api_key'));
    }

    // ---- status line ----

    public function test_the_status_line_reads_write_enabled_when_the_pair_is_saved(): void
    {
        $this->storePair();

        $block = $this->userPairBlock($this->actingAs($this->admin())->get(route('settings.integrations'))->getContent());

        $this->assertStringContainsString('Write enabled:', $block);
        $this->assertStringNotContainsString('Write not enabled:', $block);
    }

    public function test_the_status_line_says_what_to_enter_when_the_pair_is_absent(): void
    {
        Setting::setEncrypted('huntress_user_api_key', self::KEY); // half a pair is not write-enabled

        $block = $this->userPairBlock($this->actingAs($this->admin())->get(route('settings.integrations'))->getContent());

        $this->assertStringContainsString('Write not enabled:', $block);
        $this->assertStringContainsString('until a User API key and User API secret are saved here', $block);
        $this->assertStringNotContainsString('Write enabled:', $block);
    }

    // ---- remedy messages name the real field ----

    public function test_the_remedy_location_names_labels_the_page_renders(): void
    {
        $html = $this->actingAs($this->admin())->get(route('settings.integrations'))->getContent();

        $this->assertSame('Settings > Integrations > Huntress EDR / ITDR > User API key and User API secret', HuntressConfig::WRITE_CREDENTIAL_LOCATION);
        $this->assertStringContainsString('Huntress EDR / ITDR', $html);
        $this->assertStringContainsString('>User API key</label>', $html);
        $this->assertStringContainsString('>User API secret</label>', $html);
    }

    public function test_the_executor_remedy_points_at_the_user_pair_field(): void
    {
        Setting::setEncrypted('huntress_api_key', 'synthetic-read-key');
        Setting::setEncrypted('huntress_api_secret', 'synthetic-read-secret');

        $result = app(StaffHuntressActionToolExecutor::class)
            ->execute('huntress_resolve_escalation', ['escalation_id' => 1], 1, 'test');

        $this->assertSame(
            'Huntress write credential (user-based API key) is not configured; escalation resolution is unavailable until the pair is saved in Settings > Integrations > Huntress EDR / ITDR > User API key and User API secret.',
            $result['error'] ?? null,
        );
    }

    public function test_the_write_client_remedy_points_at_the_user_pair_field(): void
    {
        try {
            (new HuntressWriteClient(['api_key' => 'synthetic-read-key', 'api_secret' => 'synthetic-read-secret']))->resolveEscalation(1);
            $this->fail('expected HuntressWriteScopeException');
        } catch (HuntressWriteScopeException $e) {
            $this->assertSame(
                'Huntress write credential (user-based API key) is not configured; nothing was sent. Save the pair in Settings > Integrations > Huntress EDR / ITDR > User API key and User API secret.',
                $e->getMessage(),
            );
        }
    }

    private function userPairBlock(string $html): string
    {
        $start = strpos($html, 'id="huntress-user-key-pair"');
        $this->assertNotFalse($start, 'precondition failed: the user key pair block did not render');
        $end = strpos($html, 'Save Huntress Settings', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
