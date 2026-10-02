<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\MintsApiToken;
use Tests\TestCase;

class ApiTokensSettingsTest extends TestCase
{
    use MintsApiToken;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_mint_shows_the_plaintext_once_and_a_reload_does_not(): void
    {
        $admin = $this->admin();

        $redirect = $this->actingAs($admin)->post(route('settings.api-tokens.store'));
        $token = ApiToken::query()->sole();
        $redirect->assertRedirect(route('settings.api-tokens.show', $token));

        $first = $this->actingAs($admin)->get(route('settings.api-tokens.show', $token))->assertOk();
        preg_match('/psa-api-[A-Za-z0-9]{48}/', $first->getContent(), $m);
        $this->assertNotEmpty($m, 'plaintext shown on the first view');
        $this->assertSame(hash('sha256', $m[0]), $token->token_hash);

        $reload = $this->actingAs($admin)->get(route('settings.api-tokens.show', $token))->assertOk();
        $this->assertStringNotContainsString($m[0], $reload->getContent());
        $this->assertDoesNotMatchRegularExpression('/psa-api-[A-Za-z0-9]{48}/', $reload->getContent());
    }

    public function test_a_minted_token_is_a_draft_with_no_endpoints_and_is_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.api-tokens.store'));

        $token = ApiToken::query()->sole();
        $this->assertSame('draft', $token->state());
        $this->assertSame([], $token->grantedEndpoints());
        $this->assertSame($admin->id, $token->created_by);
        $this->assertSame('token/mint', ApiRequestLog::query()->where('kind', 'lifecycle')->value('endpoint'));
    }

    public function test_grant_validates_names_against_the_registry(): void
    {
        $admin = $this->admin();
        [$token] = $this->mintApiToken(endpoints: [], state: []);

        $this->actingAs($admin)
            ->patchJson(route('settings.api-tokens.endpoints', $token), ['endpoints' => ['clients.read', 'tickets.delete']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('endpoints');
        $this->assertSame([], $token->fresh()->grantedEndpoints());

        $this->actingAs($admin)
            ->patchJson(route('settings.api-tokens.endpoints', $token), ['endpoints' => ['clients.read', 'assets.read']])
            ->assertOk()
            ->assertJson(['ok' => true, 'granted_count' => 2]);
        $this->assertSame(['clients.read', 'assets.read'], $token->fresh()->grantedEndpoints());

        $this->actingAs($admin)
            ->patchJson(route('settings.api-tokens.endpoints', $token), ['endpoints' => []])
            ->assertOk();
        $this->assertSame([], $token->fresh()->grantedEndpoints());

        $audit = ApiRequestLog::query()->where('endpoint', 'token/endpoints')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame(['before' => [], 'after' => ['clients.read', 'assets.read']], $audit[0]->details);
    }

    public function test_every_write_is_admin_only(): void
    {
        $tech = User::factory()->tech()->create();
        [$token] = $this->mintApiToken(endpoints: [], state: []);

        $this->actingAs($tech)->post(route('settings.api-tokens.store'))->assertForbidden();
        $this->actingAs($tech)->patchJson(route('settings.api-tokens.endpoints', $token), ['endpoints' => ['clients.read']])->assertForbidden();
        $this->actingAs($tech)->patch(route('settings.api-tokens.update', $token), ['label' => 'x'])->assertForbidden();
        $this->actingAs($tech)->post(route('settings.api-tokens.activate', $token))->assertForbidden();
        $this->actingAs($tech)->post(route('settings.api-tokens.pause', $token))->assertForbidden();
        $this->actingAs($tech)->post(route('settings.api-tokens.resume', $token))->assertForbidden();
        $this->actingAs($tech)->post(route('settings.api-tokens.regenerate', $token))->assertForbidden();
        $this->actingAs($tech)->delete(route('settings.api-tokens.revoke', $token))->assertForbidden();

        $this->assertSame(1, ApiToken::count());
        $this->assertSame('draft', $token->fresh()->state());
        $this->assertSame([], $token->fresh()->grantedEndpoints());

        $this->actingAs($tech)->get(route('settings.api-tokens.index'))->assertOk();
        $this->actingAs($tech)->get(route('settings.api-tokens.show', $token))->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('settings.api-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_a_revoked_token_is_read_only(): void
    {
        $admin = $this->admin();
        [$token] = $this->mintApiToken(endpoints: ['clients.read'], state: ['activated_at' => 'now', 'revoked_at' => 'now']);

        $this->actingAs($admin)->patchJson(route('settings.api-tokens.endpoints', $token), ['endpoints' => ['assets.read']])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('settings.api-tokens.activate', $token))->assertStatus(422);
        $this->actingAs($admin)->post(route('settings.api-tokens.regenerate', $token))->assertRedirect();
        $this->assertSame(['clients.read'], $token->fresh()->grantedEndpoints());
        $this->assertSame('revoked', $token->fresh()->state());

        $page = $this->actingAs($admin)->get(route('settings.api-tokens.show', $token))->assertOk()->getContent();
        $this->assertStringNotContainsString('Regenerate secret</button>', $page);
        $this->assertMatchesRegularExpression('/class="form-check-input ep-switch[^"]*"[^>]*disabled/', $page);
    }

    public function test_lifecycle_activate_pause_resume_revoke_flows_through_authentication(): void
    {
        $admin = $this->admin();
        [$token, $plain] = $this->mintApiToken(endpoints: ['clients.read'], state: []);

        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertStatus(401);

        $this->actingAs($admin)->post(route('settings.api-tokens.activate', $token));
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertOk();

        $this->actingAs($admin)->post(route('settings.api-tokens.pause', $token));
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertStatus(401);

        $this->actingAs($admin)->post(route('settings.api-tokens.resume', $token));
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertOk();

        $this->actingAs($admin)->delete(route('settings.api-tokens.revoke', $token));
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertStatus(401);
    }

    public function test_regenerate_invalidates_the_old_secret(): void
    {
        $admin = $this->admin();
        [$token, $old] = $this->mintApiToken(endpoints: ['clients.read']);

        $this->actingAs($admin)->post(route('settings.api-tokens.regenerate', $token))->assertRedirect();
        $new = session('api_new_token');

        $this->assertIsString($new);
        $this->getJson('/api/v1/clients', $this->bearer($old))->assertStatus(401);
        $this->getJson('/api/v1/clients', $this->bearer($new))->assertOk();
    }

    public function test_update_sets_label_and_optional_expiry(): void
    {
        $admin = $this->admin();
        [$token] = $this->mintApiToken(endpoints: [], state: []);

        $this->actingAs($admin)->patch(route('settings.api-tokens.update', $token), ['label' => 'lits rmm!'])->assertSessionHasErrors('label');
        $this->assertSame('test-token', $token->fresh()->label);

        $this->actingAs($admin)->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => ''])->assertRedirect();
        $this->assertSame('lits-rmm', $token->fresh()->label);
        $this->assertNull($token->fresh()->expires_at);

        $this->actingAs($admin)->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => '2030-01-31'])->assertRedirect();
        $this->assertNotNull($token->fresh()->expires_at);
        $this->assertSame('2030', $token->fresh()->expires_at->format('Y'));
    }

    // -- expires_at upper bound (Jeeves RULED (B) term 4, run 01a0fb29) -------
    //
    // expires_at is a TIMESTAMP column, which ends at 2038-01-19 03:14:07 UTC
    // on MariaDB; the bound refuses a later date as a validation error rather
    // than letting the write fail.

    private const EXPIRY_MESSAGE = 'The expiry date must be before 2038-01-19. Leave it blank for a token that never expires.';

    public function test_update_refuses_an_expiry_on_or_after_2038_01_19_with_a_friendly_message(): void
    {
        $admin = $this->admin();
        [$token] = $this->mintApiToken(endpoints: [], state: []);

        foreach (['2038-01-19', '2040-06-01', '9999-12-31'] as $date) {
            $this->actingAs($admin)
                ->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => $date])
                ->assertSessionHasErrors(['expires_at' => self::EXPIRY_MESSAGE]);
            $this->assertNull($token->fresh()->expires_at, "{$date} was stored");
            $this->assertSame('test-token', $token->fresh()->label, "{$date} saved the label anyway");
        }

        // The day before the bound is still accepted.
        $this->actingAs($admin)
            ->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => '2038-01-18'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2038-01-18', $token->fresh()->expires_at->format('Y-m-d'));
    }

    public function test_create_refuses_an_expiry_on_or_after_2038_01_19_with_a_friendly_message(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('settings.api-tokens.store'), ['expires_at' => '2038-01-19'])
            ->assertSessionHasErrors(['expires_at' => self::EXPIRY_MESSAGE]);
        $this->assertSame(0, ApiToken::count(), 'a token was minted despite the refused expiry');

        $this->actingAs($admin)
            ->post(route('settings.api-tokens.store'), ['expires_at' => '2037-12-31'])
            ->assertRedirect();
        $this->assertSame('2037-12-31', ApiToken::query()->sole()->expires_at->format('Y-m-d'));
    }

    public function test_the_last_day_before_the_bound_is_refused_where_its_utc_end_of_day_passes_the_column_limit(): void
    {
        // 2038-01-18 end of day in Los Angeles is 2038-01-19 07:59:59 UTC,
        // past the column's 03:14:07 UTC limit, though it passes before:.
        \App\Models\Setting::setValue('app_timezone', 'America/Los_Angeles');
        $admin = $this->admin();
        [$token] = $this->mintApiToken(endpoints: [], state: []);

        $this->actingAs($admin)
            ->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => '2038-01-18'])
            ->assertSessionHasErrors('expires_at');
        $this->assertNull($token->fresh()->expires_at);

        $this->actingAs($admin)
            ->patch(route('settings.api-tokens.update', $token), ['label' => 'lits-rmm', 'expires_at' => '2038-01-17'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2038-01-18 07:59:59', $token->fresh()->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_api_tokens_do_not_touch_mcp_tokens(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.api-tokens.store'));

        $this->assertSame(0, McpToken::count());
    }

    public function test_sidebar_has_api_tokens_after_mcp_tokens(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin)->get(route('settings.api-tokens.index'))->assertOk()->getContent();

        $mcp = strpos($page, route('settings.mcp-tokens.index').'"');
        $api = strpos($page, route('settings.api-tokens.index').'"');
        $this->assertNotFalse($mcp);
        $this->assertNotFalse($api);
        $this->assertGreaterThan($mcp, $api, 'API Tokens sits under MCP Tokens');
        $this->assertStringContainsString('<span class="sidebar-label">API Tokens</span>', $page);
    }
}
