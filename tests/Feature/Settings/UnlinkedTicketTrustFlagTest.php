<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpStaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlinkedTicketTrustFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_new_tokens_default_denied_and_migration_is_additive(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic');
        $row = McpToken::where('label', 'synthetic')->firstOrFail();
        $migration = require database_path('migrations/2026_09_15_000001_add_unlinked_ticket_permission_to_mcp_tokens.php');
        $migration->down();
        $migration->up();
        $this->assertFalse($row->fresh()->allow_unlinked_tickets);
        $this->assertSame(['close_ticket'], $row->fresh()->tools);
        McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'another-synthetic');
        $this->assertFalse(McpToken::where('label', 'another-synthetic')->firstOrFail()->allow_unlinked_tickets);
        $row->update(['allow_unlinked_tickets' => true]);
        $migration->up();
        $this->assertTrue($row->fresh()->allow_unlinked_tickets);
        $this->assertFalse((new McpStaffToken)->allowUnlinkedTickets);
    }

    public function test_trust_update_requires_web_auth_not_a_bearer_and_validates_boolean(): void
    {
        $bearer = McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic');
        $row = McpToken::where('label', 'synthetic')->firstOrFail();
        $route = route('settings.mcp-tokens.trust-flags', $row);
        $this->patchJson($route, ['allow_unlinked_tickets' => true])->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->patchJson($route, ['allow_unlinked_tickets' => true])->assertUnauthorized();
        $this->assertFalse($row->fresh()->allow_unlinked_tickets);
        $this->actingAs(User::factory()->create())->patchJson($route, ['allow_unlinked_tickets' => 'invalid'])->assertUnprocessable();
        $this->assertFalse($row->fresh()->allow_unlinked_tickets);
        $this->patchJson($route, ['allow_unlinked_tickets' => true])->assertOk();
        $this->assertTrue($row->fresh()->allow_unlinked_tickets);
        $this->assertTrue(McpConfig::resolveStaffToken($bearer)->allowUnlinkedTickets);
        $audit = McpAuditLog::where('method', 'token/trust_flags')->latest('id')->firstOrFail();
        $this->assertTrue($audit->arguments['allow_unlinked_tickets']);
        $this->patchJson($route, ['ai_actor' => true])->assertOk();
        $this->assertTrue($row->fresh()->allow_unlinked_tickets, 'Omitted flag is preserved');
        $this->patchJson($route, ['allow_unlinked_tickets' => false])->assertOk();
        $this->assertFalse(McpConfig::resolveStaffToken($bearer)->allowUnlinkedTickets);
    }

    public function test_settings_shows_default_denied_flag_and_draft_does_not_grant_it(): void
    {
        $this->actingAs(User::factory()->create())->post(route('settings.mcp-tokens.store'));
        $row = McpToken::latest('id')->firstOrFail();
        $this->assertFalse($row->allow_unlinked_tickets);
        $this->get(route('settings.mcp-tokens.show', $row))->assertOk()
            ->assertSee('data-flag="allow_unlinked_tickets"', false)
            ->assertSee('Default denied.')
            ->assertSee('destination name confirmation');
        $row->update(['allow_unlinked_tickets' => true]);
        $this->get(route('settings.mcp-tokens.show', $row))->assertOk()
            ->assertSee('data-flag="allow_unlinked_tickets" checked', false);
    }
}
