<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpHeldOnlyGrantsTest extends TestCase
{
    use RefreshDatabase;

    public static function heldTools(): array
    {
        return array_map(fn ($name) => [$name], [
            'resolve_email_item', 'mesh_add_allow_rule', 'mesh_remove_allow_rule',
            'mesh_edit_allow_rule', 'tactical_remove_agent',
        ]);
    }

    private function token(): McpToken
    {
        $this->actingAs(User::factory()->create());
        McpConfig::mintDraftToken('synthetic-held-only');

        return McpToken::query()->latest('id')->firstOrFail();
    }

    #[DataProvider('heldTools')]
    public function test_held_only_registry_and_rendered_controls(string $tool): void
    {
        $token = $this->token();
        $rows = collect(McpToolRegistry::integrationGroups())->pluck('tiers')->flatten(1)->pluck('tools')->flatten(1)->keyBy('name');
        $this->assertTrue($rows[$tool]['stageable']);
        $this->assertTrue($rows[$tool]['held_only'] ?? false);
        $this->assertFalse($rows['send_email']['held_only']);
        $page = $this->get(route('settings.mcp-tokens.show', $token))->assertOk();
        $page->assertDontSee('id="mode-'.$tool.'"', false)
            ->assertSee('held-only — every call is a cockpit proposal')
            ->assertSee('id="mode-send_email"', false);
    }

    #[DataProvider('heldTools')]
    public function test_bare_and_alias_grants_save_staged_but_immediate_is_rejected_atomically(string $tool): void
    {
        $token = $this->token();
        $alias = McpToolModes::stagedInternalFor($tool);
        foreach ([$tool, $tool.':staged', $alias] as $entry) {
            $this->assertSame([$tool, 'staged'], McpToolModes::parseGrantEntry($entry));
            $this->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => [$entry, 'send_email']])->assertOk();
            $this->assertSame([$tool.':staged', 'send_email:immediate'], $token->fresh()->tools);
        }
        foreach ([$tool.':immediate', $alias.':immediate'] as $entry) {
            $this->assertSame([$entry, null], McpToolModes::parseGrantEntry($entry));
            $this->assertNotContains($tool, McpToolModes::parseGrants([$entry])['tools']);
            $response = $this->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => ['find_staff', $entry]]);
            $response->assertUnprocessable()->assertJsonValidationErrors('tools');
            $this->assertStringContainsString($tool.' is held-only', $response->json('errors.tools.0'));
            $this->assertStringContainsString($tool.':staged', $response->json('errors.tools.0'));
            $this->assertSame([$tool.':staged', 'send_email:immediate'], $token->fresh()->tools);
        }
    }

    public function test_normal_stageable_grant_retains_legacy_immediate_mapping(): void
    {
        $token = $this->token();
        $this->assertSame(['send_email', 'immediate'], McpToolModes::parseGrantEntry('send_email'));
        $this->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => ['send_email:immediate']])->assertOk();
        $this->assertSame(['send_email:immediate'], $token->fresh()->tools);
    }
}
