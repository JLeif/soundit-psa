<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpStaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnlinkedTicketTrustFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_column_is_retained_but_not_a_runtime_or_mass_assignable_flag(): void
    {
        $this->assertTrue(Schema::hasColumn('mcp_tokens', 'allow_unlinked_tickets'));
        $this->assertFalse(property_exists(McpStaffToken::class, 'allowUnlinkedTickets'));
        $this->assertNotContains('allow_unlinked_tickets', (new McpToken)->getFillable());
        $this->assertArrayNotHasKey('allow_unlinked_tickets', (new McpToken)->getCasts());
        foreach ([false, true] as $legacy) {
            $bearer = McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic');
            $row = McpToken::where('label', 'synthetic')->firstOrFail();
            DB::table('mcp_tokens')->where('id', $row->id)->update(['allow_unlinked_tickets' => $legacy]);
            $this->assertFalse(property_exists(McpConfig::resolveStaffToken($bearer), 'allowUnlinkedTickets'));
            McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic');
            $this->assertSame($legacy, (bool) DB::table('mcp_tokens')->where('id', $row->id)->value('allow_unlinked_tickets'));
        }
    }

    public function test_retired_input_is_ignored_without_weakening_web_auth_or_other_flags(): void
    {
        $bearer = McpConfig::rotateStaffToken(allowedTools: ['close_ticket'], label: 'synthetic');
        $row = McpToken::where('label', 'synthetic')->firstOrFail();
        $route = route('settings.mcp-tokens.trust-flags', $row);
        $this->patchJson($route, ['allow_unlinked_tickets' => true])->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->patchJson($route, ['allow_unlinked_tickets' => true])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->patchJson($route, ['ai_actor' => 'invalid'])->assertUnprocessable();
        foreach ([false, true] as $legacy) {
            DB::table('mcp_tokens')->where('id', $row->id)->update(['allow_unlinked_tickets' => $legacy]);
            $this->patchJson($route, ['allow_unlinked_tickets' => ! $legacy, 'ai_actor' => true])->assertOk();
            $this->assertSame($legacy, (bool) DB::table('mcp_tokens')->where('id', $row->id)->value('allow_unlinked_tickets'));
            $this->assertTrue($row->fresh()->ai_actor);
            $audit = McpAuditLog::where('method', 'token/trust_flags')->latest('id')->firstOrFail();
            $this->assertArrayNotHasKey('allow_unlinked_tickets', $audit->arguments);
        }
    }

    public function test_settings_has_no_retired_switch_for_new_or_legacy_tokens(): void
    {
        $this->actingAs(User::factory()->create())->post(route('settings.mcp-tokens.store'));
        $row = McpToken::latest('id')->firstOrFail();
        foreach ([false, true] as $legacy) {
            DB::table('mcp_tokens')->where('id', $row->id)->update(['allow_unlinked_tickets' => $legacy]);
            $this->get(route('settings.mcp-tokens.show', $row))->assertOk()
                ->assertDontSee('allow_unlinked_tickets', false)
                ->assertDontSee('Allow unlinked-ticket triage')
                ->assertSee('data-flag="ai_actor"', false)
                ->assertDontSee('data-flag="require_explicit_client_scope"', false);
        }
    }
}
