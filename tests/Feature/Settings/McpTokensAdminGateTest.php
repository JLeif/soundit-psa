<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\SignalDestination;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B4.2 (#2056, context:3): every MUTATING /settings/mcp-tokens route is Admin-only.
 *
 * Before this gate the token routes sat under `auth` alone, so any active staff user
 * could mint a token, tick `ai_actor`, grant `controld_onboard_client:staged` and
 * activate it — the surface B4.1 (#2043) made load-bearing, since an ai_actor token
 * is the token lane's whole authority to stage. The gate is RequireAdmin (#762), the
 * same alias the client-page onboarding button carries, and it refuses with 403
 * exactly as that route does. The read-only GETs (index, show) stay under `auth`:
 * the index lists tokens read-only for non-admins today and that UX is unchanged.
 */
class McpTokensAdminGateTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $label = 'gated'): McpToken
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: $label, aiActor: false);

        return McpToken::where('label', $label)->sole();
    }

    private function tech(): User
    {
        return User::factory()->tech()->create(['is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['is_active' => true]);
    }

    private function destination(?string $label): SignalDestination
    {
        return SignalDestination::create(['label' => 'Synthetic inbox', 'type' => 'mcp', 'mcp_token_label' => $label]);
    }

    /** @return array<string, mixed> */
    private function snapshot(McpToken $token): array
    {
        return $token->fresh()->getAttributes();
    }

    /**
     * Every mutating route: [method, route name, body]. `{destination}` is filled per test.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function mutatingRoutes(): array
    {
        return [
            'tools' => ['patch', 'settings.mcp-tokens.tools', ['tools' => ['find_clients']]],
            'directive' => ['patch', 'settings.mcp-tokens.directive', ['directive' => 'do the thing']],
            'trust-flags' => ['patch', 'settings.mcp-tokens.trust-flags', ['ai_actor' => true]],
            'rename' => ['patch', 'settings.mcp-tokens.rename', ['label' => 'renamed']],
            'activate' => ['post', 'settings.mcp-tokens.activate', []],
            'pause' => ['post', 'settings.mcp-tokens.pause', []],
            'resume' => ['post', 'settings.mcp-tokens.resume', []],
            'regenerate' => ['post', 'settings.mcp-tokens.regenerate', []],
            'signal-destinations.link' => ['post', 'settings.mcp-tokens.signal-destinations.link', []],
            'revoke' => ['delete', 'settings.mcp-tokens.revoke', []],
        ];
    }

    /**
     * RED CONTROL: a non-admin is refused (403, RequireAdmin's existing refusal) on every
     * per-token mutating route, JSON and form alike, and the token row is byte-identical after.
     *
     * @dataProvider mutatingRoutes
     */
    public function test_non_admin_is_refused_on_every_per_token_mutating_route(string $method, string $route, array $body): void
    {
        $token = $this->token();
        $destination = $this->destination(null);
        if ($route === 'settings.mcp-tokens.signal-destinations.link') {
            $body = ['signal_destination_id' => $destination->id];
        }
        $before = $this->snapshot($token);
        $tech = $this->tech();

        $this->actingAs($tech)->{$method.'Json'}(route($route, $token), $body)->assertForbidden();
        $this->actingAs($tech)->{$method}(route($route, $token), $body)->assertForbidden();

        $this->assertSame($before, $this->snapshot($token), "{$route}: a refused call must not touch the token row");
        $this->assertSame(1, McpToken::count(), "{$route}: no row created or deleted");
        $this->assertNull($destination->fresh()->mcp_token_label, "{$route}: no destination linked");
    }

    /** RED CONTROL: a non-admin cannot mint a token at all, nor unlink a signal destination. */
    public function test_non_admin_cannot_store_a_token_or_unlink_a_signal_destination(): void
    {
        $tech = $this->tech();
        $this->actingAs($tech)->post(route('settings.mcp-tokens.store'))->assertForbidden();
        $this->actingAs($tech)->postJson(route('settings.mcp-tokens.store'))->assertForbidden();
        $this->assertSame(0, McpToken::count(), 'no draft token is minted for a non-admin');

        $token = $this->token();
        $destination = $this->destination($token->label);
        $this->actingAs($tech)->deleteJson(route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]))->assertForbidden();
        $this->actingAs($tech)->delete(route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]))->assertForbidden();
        $this->assertSame($token->label, $destination->fresh()->mcp_token_label, 'the destination stays linked');
    }

    /** The refusal is a role gate, not a login gate: every other role is refused the same way. */
    public function test_billing_and_contractor_are_refused_like_tech(): void
    {
        $token = $this->token();
        foreach ([User::factory()->billing()->create(['is_active' => true]), User::factory()->contractor()->create(['is_active' => true])] as $user) {
            $this->actingAs($user)->patchJson(route('settings.mcp-tokens.trust-flags', $token), ['ai_actor' => true])->assertForbidden();
            $this->actingAs($user)->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => ['controld_onboard_client:staged']])->assertForbidden();
        }
        $token->refresh();
        $this->assertFalse($token->ai_actor);
        $this->assertSame(['find_staff'], $token->tools);
    }

    /** POSITIVE CONTROL: an Admin still drives every one of those routes. */
    public function test_admin_still_succeeds_on_the_mutating_routes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.mcp-tokens.store'))->assertRedirect();
        $token = McpToken::query()->latest('id')->sole();

        $this->actingAs($admin)->patchJson(route('settings.mcp-tokens.rename', $token), ['label' => 'admin-owned'])->assertOk();
        $this->actingAs($admin)->patchJson(route('settings.mcp-tokens.tools', $token->fresh()), ['tools' => ['find_clients']])->assertOk();
        $this->actingAs($admin)->patchJson(route('settings.mcp-tokens.directive', $token->fresh()), ['directive' => 'be brief'])->assertOk();
        $this->actingAs($admin)->patchJson(route('settings.mcp-tokens.trust-flags', $token->fresh()), ['ai_actor' => true])->assertOk();
        $this->actingAs($admin)->postJson(route('settings.mcp-tokens.activate', $token->fresh()))->assertOk();
        $this->actingAs($admin)->postJson(route('settings.mcp-tokens.pause', $token->fresh()))->assertOk();
        $this->actingAs($admin)->postJson(route('settings.mcp-tokens.resume', $token->fresh()))->assertOk();
        $secretBefore = $token->fresh()->token_hash;
        $this->actingAs($admin)->post(route('settings.mcp-tokens.regenerate', $token->fresh()))->assertRedirect()->assertSessionHas('mcp_new_token');

        $token->refresh();
        $this->assertSame('admin-owned', $token->label);
        $this->assertSame(['find_clients'], $token->tools);
        $this->assertTrue($token->ai_actor);
        $this->assertTrue($token->isActive());
        $this->assertNotSame($secretBefore, $token->token_hash);

        $destination = $this->destination(null);
        $this->actingAs($admin)->post(route('settings.mcp-tokens.signal-destinations.link', $token), ['signal_destination_id' => $destination->id])->assertRedirect();
        $this->assertSame('admin-owned', $destination->fresh()->mcp_token_label);
        $this->actingAs($admin)->delete(route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]))->assertRedirect();
        $this->assertNull($destination->fresh()->mcp_token_label);

        $this->actingAs($admin)->delete(route('settings.mcp-tokens.revoke', $token))->assertRedirect(route('settings.mcp-tokens.index'));
        $this->assertTrue($token->fresh()->isRevoked());
    }

    /** The read-only surface is unchanged: a non-admin still reads the index and a token page. */
    public function test_non_admin_still_reads_index_and_show(): void
    {
        $token = $this->token();
        $tech = $this->tech();
        $this->actingAs($tech)->get(route('settings.mcp-tokens.index'))->assertOk()->assertSee('gated');
        $this->actingAs($tech)->get(route('settings.mcp-tokens.show', $token))->assertOk()->assertSee('gated');
    }
}
