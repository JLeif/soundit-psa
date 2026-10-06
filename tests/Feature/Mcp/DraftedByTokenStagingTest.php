<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\DraftedByToken;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card tY39CHiq: the PSA action staging sites that were missing it now record
 * proposed_meta.drafted_by_token, the caller's BARE token label, beside the
 * prefixed drafted_by. These are merge_ticket (propose_merge), merge_asset
 * (propose_asset_merge) and the staged close_ticket (propose_close). So the
 * drafting token can withdraw them through withdraw_staged_action, and no
 * other token can.
 *
 * The vendor executors' sites are pinned in their own suites (CIPP, Huntress,
 * Tactical action and admin, Control D), each in a test that already stages
 * through the real MCP endpoint.
 */
class DraftedByTokenStagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function token(string $grant, string $label = 'opsbot'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: [$grant, 'withdraw_staged_action'], label: $label);
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> site => [grant, tool, staged action_type] */
    public static function sites(): array
    {
        return [
            'merge_ticket (proposeMerge)' => ['merge_ticket:staged', 'merge_ticket', 'propose_merge'],
            'merge_asset (proposeAssetMerge)' => ['merge_asset:staged', 'merge_asset', 'propose_asset_merge'],
            'close_ticket staged (stageClose)' => ['close_ticket:staged', 'close_ticket', 'propose_close'],
        ];
    }

    /** Stage one awaiting run through the real MCP endpoint and return it. */
    private function stage(string $tool, string $actionType, string $token): TechnicianRun
    {
        $client = Client::factory()->create();
        $primary = Ticket::factory()->for($client)->create([
            'status' => TicketStatus::PendingClient, 'closed_at' => null, 'subject' => 'Printer offline',
        ]);

        $arguments = match ($tool) {
            'merge_ticket' => [
                'client_id' => $client->id,
                'primary_ticket_id' => $primary->id,
                'secondary_ticket_id' => Ticket::factory()->for($client)->create([
                    'status' => TicketStatus::InProgress, 'closed_at' => null, 'subject' => 'Duplicate printer issue',
                ])->id,
                'reason' => 'Same printer, same user, same morning.',
            ],
            'merge_asset' => [
                'client_id' => $client->id,
                'survivor_asset_id' => Asset::factory()->for($client)->create()->id,
                'duplicate_asset_id' => Asset::factory()->for($client)->create()->id,
                'ticket_id' => $primary->id,
                'reason' => 'Same serial, re-imaged machine.',
            ],
            'close_ticket' => [
                'ticket_id' => $primary->id,
                'resolution_summary' => 'Held: propose closing this stale ticket.',
                'reason' => 'Held: propose closing this stale ticket.',
            ],
        };

        $res = $this->callTool($token, $tool, $arguments);
        $this->assertFalse((bool) $res->json('result.isError'), (string) $res->json('result.content.0.text'));

        $run = TechnicianRun::where('ticket_id', $primary->id)->where('action_type', $actionType)->sole();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        return $run;
    }

    #[DataProvider('sites')]
    public function test_each_psa_staging_site_records_the_bare_token_label(string $grant, string $tool, string $actionType): void
    {
        $run = $this->stage($tool, $actionType, $this->token($grant));

        $this->assertSame('opsbot', $run->proposed_meta['drafted_by_token'] ?? null);
        $this->assertSame('mcp-staff:opsbot', $run->proposed_meta['drafted_by']);
    }

    #[DataProvider('sites')]
    public function test_the_drafting_token_withdraws_the_staged_run_and_another_token_is_refused(string $grant, string $tool, string $actionType): void
    {
        $drafter = $this->token($grant);
        $run = $this->stage($tool, $actionType, $drafter);

        $other = $this->callTool($this->token($grant, 'otherbot'), 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'Not mine.']);
        $this->assertTrue((bool) $other->json('result.isError'));
        $this->assertStringContainsString("No staged action #{$run->id} drafted by this token was found", (string) $other->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);

        $mine = $this->callTool($drafter, 'withdraw_staged_action', ['run_id' => $run->id, 'reason' => 'Wrong pair; restaging.']);
        $this->assertFalse((bool) $mine->json('result.isError'), (string) $mine->json('result.content.0.text'));
        $this->assertSame(TechnicianRunState::Withdrawn, $run->fresh()->state);
    }

    /** The helper never invents a label: absent or empty leaves the key out, so no token can withdraw. */
    public function test_the_helper_omits_the_key_when_there_is_no_bare_label(): void
    {
        $this->assertSame(['drafted_by' => 'mcp-staff:opsbot', 'drafted_by_token' => 'opsbot'], DraftedByToken::meta('mcp-staff:opsbot', 'opsbot'));
        $this->assertSame(['drafted_by' => 'mcp-legacy'], DraftedByToken::meta('mcp-legacy', null));
        $this->assertSame(['drafted_by' => 'mcp-legacy'], DraftedByToken::meta('mcp-legacy', ''));
    }
}
