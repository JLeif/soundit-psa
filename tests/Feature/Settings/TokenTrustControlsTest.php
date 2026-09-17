<?php

namespace Tests\Feature\Settings;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Http\Controllers\Api\McpStaffController;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpStaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenTrustControlsTest extends TestCase
{
    use RefreshDatabase;

    private function callTool(string $plain, string $tool, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function page(McpToken $row): TestResponse
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.mcp-tokens.show', $row))->assertOk();
    }

    public function test_attribution_label_matches_exact_controller_cohort_and_note_behavior(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = Ticket::factory()->create();
        foreach ([false, true] as $ai) {
            $plain = McpConfig::rotateStaffToken(allowedTools: ['add_ticket_note'], label: 'actor', aiActor: $ai);
            $row = McpToken::where('label', 'actor')->firstOrFail();
            $this->page($row)->assertSee('AI attribution for notes and wiki writes')
                ->assertSee('Uses the configured AI user for add_ticket_note, wiki_add_fact, wiki_create_page and wiki_update_page.')
                // B4.2 (#2056): the flag IS the token lane's staging authority for controld_onboard_client, so the help no longer says it "grants no permissions".
                ->assertSee('This does not change attribution for replies or other tools. It is also the token lane')
                ->assertDontSee('grants no permissions')
                ->assertDontSee('Notes, replies, and changes made with this token');
            $request = Request::create('/');
            $request->attributes->set('mcp_staff_token', McpConfig::resolveStaffToken($plain));
            $predicate = new \ReflectionMethod(McpStaffController::class, 'usesAiActorForWrite');
            foreach (['add_ticket_note', 'wiki_add_fact', 'wiki_create_page', 'wiki_update_page', 'send_reply', 'propose_close', 'send_email', 'find_staff'] as $tool) {
                $this->assertSame($ai && in_array($tool, ['add_ticket_note', 'wiki_add_fact', 'wiki_create_page', 'wiki_update_page']), $predicate->invoke(new McpStaffController, $request, $tool), $tool);
            }
            $response = $this->callTool($plain, 'add_ticket_note', ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'body' => 'Attribution guard.'])->assertOk();
            $this->assertFalse((bool) $response->json('result.isError'), $response->json('result.content.0.text'));
            $note = TicketNote::findOrFail(json_decode($response->json('result.content.0.text'), true)['note_id']);
            $this->assertSame($actor->id, $note->author_id);
            $this->assertTrue((bool) $note->ai_authored);
        }
    }

    public function test_attribution_on_requires_configuration_while_off_retains_fallback(): void
    {
        $fallback = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $args = ['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'body' => 'Fallback guard.'];
        $plain = McpConfig::rotateStaffToken(allowedTools: ['add_ticket_note'], label: 'actor', aiActor: false);
        $row = McpToken::where('label', 'actor')->firstOrFail();
        $this->page($row)->assertSee('On requires a valid configured AI user; off uses the service-account resolver, which may fall back to the first user.')
            ->assertSee('Assistant notes remain AI-authored in either case.');
        $off = $this->callTool($plain, 'add_ticket_note', $args)->assertOk();
        $this->assertFalse((bool) $off->json('result.isError'));
        $note = TicketNote::findOrFail(json_decode($off->json('result.content.0.text'), true)['note_id']);
        $this->assertSame($fallback->id, $note->author_id);
        $plain = McpConfig::rotateStaffToken(allowedTools: ['add_ticket_note'], label: 'actor', aiActor: true);
        $on = $this->callTool($plain, 'add_ticket_note', $args)->assertOk();
        $this->assertTrue((bool) $on->json('result.isError'));
        $this->assertStringContainsString('AI actor user is not configured', $on->json('result.content.0.text'));
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    public function test_lifecycle_label_matches_call_gate_and_controls(): void
    {
        // Keep the service enabled so a revoked credential exercises auth, not 503.
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'other-active');
        $plain = McpConfig::mintDraftToken('lifecycle');
        $row = McpToken::where('label', 'lifecycle')->firstOrFail();
        $row->update(['tools' => ['find_staff']]);
        $this->page($row)->assertSee('Only active tokens can authenticate. Draft, paused and revoked tokens cannot call tools.')
            ->assertSee('Activate token')->assertSee('It cannot authenticate, even if tools have been configured.');
        $this->callTool($plain, 'find_staff', [])->assertStatus(401);
        $this->post(route('settings.mcp-tokens.activate', $row))->assertRedirect();
        $this->callTool($plain, 'find_staff', [])->assertOk();
        $this->get(route('settings.mcp-tokens.show', $row))->assertOk()->assertSee('Pause');
        $this->post(route('settings.mcp-tokens.pause', $row))->assertRedirect();
        $this->callTool($plain, 'find_staff', [])->assertStatus(401);
        $this->get(route('settings.mcp-tokens.show', $row))->assertOk()->assertSee('Resume');
        $this->post(route('settings.mcp-tokens.resume', $row))->assertRedirect();
        $this->callTool($plain, 'find_staff', [])->assertOk();
        $this->delete(route('settings.mcp-tokens.revoke', $row))->assertRedirect();
        $this->callTool($plain, 'find_staff', [])->assertStatus(401);
        $this->get(route('settings.mcp-tokens.show', $row))->assertOk()->assertSee('Revoked');
    }

    public function test_tool_grant_label_matches_ungranted_refusal_and_staged_downgrade(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['close_ticket:staged'], label: 'modes');
        $row = McpToken::where('label', 'modes')->firstOrFail();
        $this->page($row)->assertSee('Tool grants and execution modes')->assertSee('Configure in Tools')
            ->assertSee('A staged-only grant cannot execute immediately; immediate grants do not bypass confirmation or tool-specific restrictions.');
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);
        $args = ['ticket_id' => $ticket->id, 'reason' => 'Synthetic mode guard.', 'resolution_summary' => 'Synthetic resolution.', 'confirm' => true, 'staged' => false];
        $denied = $this->callTool($plain, 'add_ticket_note', $args + ['client_id' => $ticket->client_id, 'body' => 'Not granted.'])->assertOk();
        $this->assertTrue((bool) $denied->json('result.isError'));
        $this->assertStringContainsString('Tool not allowed', $denied->json('result.content.0.text'));
        $held = $this->callTool($plain, 'close_ticket', $args)->assertOk();
        $this->assertFalse((bool) $held->json('result.isError'), $held->json('result.content.0.text'));
        $this->assertSame(TicketStatus::New, $ticket->fresh()->status);
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('state', TechnicianRunState::AwaitingApproval)->count());
    }

    public static function legacyValues(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('legacyValues')]
    public function test_retired_column_input_and_named_arguments_are_inert(bool $legacy): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'compat', requireExplicitClientScope: ! $legacy);
        $row = McpToken::where('label', 'compat')->firstOrFail();
        DB::table('mcp_tokens')->where('id', $row->id)->update(['require_explicit_client_scope' => $legacy]);
        $this->page($row)->assertDontSee('require_explicit_client_scope')->assertDontSee('Recommended')
            ->assertDontSee('Every tool call must name the client')->assertSee('Tokens are not bound to a client.');
        $this->get(route('settings.mcp-tokens.index'))->assertOk()->assertDontSee('Client-scoped');
        $this->patchJson(route('settings.mcp-tokens.trust-flags', $row), [
            'require_explicit_client_scope' => ! $legacy, 'ai_actor' => true,
        ])->assertOk();
        $this->assertSame((int) $legacy, (int) $row->fresh()->require_explicit_client_scope);
        $this->assertTrue($row->fresh()->ai_actor);
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'compat', requireExplicitClientScope: ! $legacy);
        $this->assertSame((int) $legacy, (int) $row->fresh()->require_explicit_client_scope);
        $this->assertFalse(property_exists(McpConfig::resolveStaffToken($plain), 'requireExplicitClientScope'));
        $dto = new McpStaffToken(allowedTools: ['find_staff'], requireExplicitClientScope: $legacy);
        $this->assertFalse(property_exists($dto, 'requireExplicitClientScope'));
        $this->assertTrue($dto->allows('find_staff'));
    }

    #[DataProvider('legacyValues')]
    public function test_ticket_scope_label_matches_both_legacy_values(bool $legacy): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $plain = McpConfig::rotateStaffToken(allowedTools: ['add_ticket_note', 'propose_close', 'send_reply'], label: 'scope');
        $row = McpToken::where('label', 'scope')->firstOrFail();
        DB::table('mcp_tokens')->where('id', $row->id)->update(['require_explicit_client_scope' => $legacy]);
        $this->page($row)->assertSee('a supplied client must match the ticket; omission derives the ticket client where supported.')
            ->assertSee('Tools that require identifiers still require them.');
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        foreach (['add_ticket_note', 'propose_close', 'send_reply'] as $tool) {
            $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New]);
            $args = ['ticket_id' => $ticket->id, 'body' => 'Synthetic internal message.', 'reason' => 'Synthetic reason.', 'confidence' => 0.95];
            $wrong = $this->callTool($plain, $tool, $args + ['client_id' => $other->id])->assertOk();
            $this->assertTrue((bool) $wrong->json('result.isError'), $tool);
            $this->assertStringContainsString('different client', $wrong->json('result.content.0.text'));
            $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
            $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
            $malformed = $this->callTool($plain, $tool, $args + ['client_id' => 'bad'])->assertOk();
            $this->assertTrue((bool) $malformed->json('result.isError'));
            $this->assertStringContainsString('client_id is required', $malformed->json('result.content.0.text'));
            $omitted = $this->callTool($plain, $tool, $args)->assertOk();
            if ($tool === 'add_ticket_note') {
                $this->assertTrue((bool) $omitted->json('result.isError'));
                $this->assertStringContainsString('client_id is required', $omitted->json('result.content.0.text'));
                $valid = $this->callTool($plain, $tool, $args + ['client_id' => $client->id])->assertOk();
                $this->assertFalse((bool) $valid->json('result.isError'), $valid->json('result.content.0.text'));
                $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
            } else {
                $this->assertFalse((bool) $omitted->json('result.isError'), $omitted->json('result.content.0.text'));
                $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('state', TechnicianRunState::AwaitingApproval)->count());
                $this->assertSame(TicketStatus::New, $ticket->fresh()->status);
            }
        }
    }
}
