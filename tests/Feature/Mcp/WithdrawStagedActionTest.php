<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\CloseBandEvaluator;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Mcp\WithdrawStagedActionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * withdraw_staged_action (card XUiMXNEH): the drafting token withdraws its own
 * pending proposal. Driven through StaffPsaActionToolExecutor::execute(), the same
 * entry point the staff MCP controller dispatches PSA actions to.
 */
class WithdrawStagedActionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
    }

    /** @return array<string, mixed> */
    private function mcp(string $tool, array $arguments, int $clientId, ?string $tokenLabel): array
    {
        return app(StaffPsaActionToolExecutor::class)->execute(
            $tool,
            $arguments,
            $clientId,
            'mcp-staff:'.($tokenLabel ?? 'none'),
            $tokenLabel,
        );
    }

    private function ticket(): Ticket
    {
        $client = Client::factory()->create(['name' => 'Example Co']);

        return Ticket::factory()->for($client)->create(['subject' => 'Printer offline at example.test']);
    }

    /** Stage a public note through the real staging verb, drafted by $tokenLabel. */
    private function stageNote(Ticket $ticket, string $tokenLabel, string $body = 'We replaced the toner.'): TechnicianRun
    {
        $result = $this->mcp('stage_public_note', [
            'ticket_id' => $ticket->id,
            'reason' => 'Client asked for an update.',
            'body' => $body,
        ], $ticket->client_id, $tokenLabel);
        $this->assertTrue($result['success'] ?? false, json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    /** @return array<string, mixed> */
    private function withdraw(int $runId, ?string $tokenLabel, mixed $reason = 'Wrong body; restaging a corrected one.'): array
    {
        return $this->mcp(WithdrawStagedActionTool::NAME, ['run_id' => $runId, 'reason' => $reason], 0, $tokenLabel);
    }

    public function test_the_drafter_withdraws_its_own_run_and_can_repropose(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');

        $result = $this->withdraw($run->id, 'chet');

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame('withdrawn', $result['state']);
        $run->refresh();
        $this->assertSame(TechnicianRunState::Withdrawn, $run->state);
        $this->assertSame('drafter', $run->proposed_meta['withdrawn_by']);
        $this->assertSame('chet', $run->proposed_meta['withdrawn_by_token']);
        $this->assertSame('Wrong body; restaging a corrected one.', $run->proposed_meta['withdrawn_reason']);
        // The staging meta survives the withdrawal.
        $this->assertSame('chet', $run->proposed_meta['drafted_by_token']);

        // Audited like the other staff MCP writes: one executed action-log row on the run.
        $log = TechnicianActionLog::where('action_type', WithdrawStagedActionTool::NAME)->sole();
        $this->assertSame($run->id, (int) $log->run_id);
        $this->assertSame('executed', $log->result_status);
        $this->assertSame('mcp-staff:chet', $log->actor_label);
        // A self-scoped autonomous act, not an executed approval of the proposal: Auto tier
        // and its own hash (the AssetWatchTool precedent), never the run's content_hash.
        $this->assertSame(TechnicianTier::Auto->value, $log->tier);
        $this->assertNotSame(TechnicianTier::Approve->value, $log->tier);
        $this->assertSame(hash('sha256', 'withdraw_staged_action|'.$run->id), $log->content_hash);
        $this->assertNotSame($run->content_hash, $log->content_hash);

        // A corrected proposal stages as a fresh awaiting run.
        $again = $this->stageNote($ticket, 'chet', 'We replaced the toner and the drum.');
        $this->assertNotSame($run->id, $again->id);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $again->state);
        $this->assertSame(TechnicianRunState::Withdrawn, $run->fresh()->state);
    }

    public function test_another_token_gets_the_not_found_text_and_the_run_is_unchanged(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');
        $before = $run->fresh()->getAttributes();

        $other = $this->withdraw($run->id, 'otherbot');
        $missing = $this->withdraw($run->id + 1000, 'otherbot');

        $this->assertSame(WithdrawStagedActionTool::notFound($run->id), $other['error'] ?? null);
        // Same text as a run that does not exist, so the refusal leaks nothing.
        $this->assertSame(
            str_replace((string) ($run->id + 1000), 'N', (string) ($missing['error'] ?? '')),
            str_replace((string) $run->id, 'N', (string) $other['error']),
        );
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertSame(0, TechnicianActionLog::where('action_type', WithdrawStagedActionTool::NAME)->count());
    }

    public function test_the_prefixed_audit_label_is_not_the_drafter_key(): void
    {
        // proposed_meta.drafted_by holds the PREFIXED form. A caller whose bare label
        // equals that string must not match it.
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');

        $result = $this->withdraw($run->id, 'mcp-staff:chet');

        $this->assertSame(WithdrawStagedActionTool::notFound($run->id), $result['error'] ?? null);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_a_run_with_no_drafting_token_matches_no_caller(): void
    {
        $ticket = $this->ticket();
        $run = $this->rawRun($ticket, TechnicianRunState::AwaitingApproval, ['drafted_by' => 'mcp-staff:chet']);

        $this->assertSame(WithdrawStagedActionTool::notFound($run->id), $this->withdraw($run->id, 'chet')['error'] ?? null);
        $this->assertSame(WithdrawStagedActionTool::notFound($run->id), $this->withdraw($run->id, null)['error'] ?? null);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** @return array<string, array{0: TechnicianRunState}> */
    public static function nonAwaitingStates(): array
    {
        $out = [];
        foreach (TechnicianRunState::cases() as $state) {
            if ($state !== TechnicianRunState::AwaitingApproval) {
                $out[$state->value] = [$state];
            }
        }

        return $out;
    }

    #[DataProvider('nonAwaitingStates')]
    public function test_a_run_not_awaiting_approval_is_refused_and_unchanged(TechnicianRunState $state): void
    {
        $ticket = $this->ticket();
        $run = $this->rawRun($ticket, $state, ['drafted_by_token' => 'chet']);
        $before = $run->fresh()->getAttributes();

        $result = $this->withdraw($run->id, 'chet');

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame(
            "Run #{$run->id} is not awaiting approval (state: {$state->value}), so it cannot be withdrawn; nothing was changed.",
            $result['error'] ?? null,
        );
        // G-14: the refusal must not claim the run was ever awaiting approval.
        $this->assertStringNotContainsString('no longer', (string) $result['error']);
        $this->assertSame($before, $run->fresh()->getAttributes());
    }

    /** @return array<string, array{0: mixed}> */
    public static function blankReasons(): array
    {
        return ['empty' => [''], 'whitespace' => ["  \n "], 'null' => [null], 'array' => [['x']]];
    }

    #[DataProvider('blankReasons')]
    public function test_a_missing_or_blank_reason_is_refused(mixed $reason): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');

        $result = $this->withdraw($run->id, 'chet', $reason);

        $this->assertSame('reason is required and must not be empty.', $result['error'] ?? null);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_a_reason_over_500_characters_is_refused_before_anything_is_written(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');
        $before = $run->fresh()->getAttributes();

        // Multibyte, padded: measured in characters after trim, not bytes.
        $result = $this->withdraw($run->id, 'chet', '  '.str_repeat('é', 501).'  ');

        $this->assertSame('reason must be at most 500 characters.', $result['error'] ?? null);
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, TechnicianActionLog::where('action_type', WithdrawStagedActionTool::NAME)->count());
    }

    public function test_a_reason_of_exactly_500_characters_is_accepted(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');
        $reason = str_repeat('é', 500);

        $result = $this->withdraw($run->id, 'chet', '  '.$reason.'  ');

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame(TechnicianRunState::Withdrawn, $run->fresh()->state);
        $this->assertSame($reason, $run->fresh()->proposed_meta['withdrawn_reason']);
        $this->assertSame(1, TechnicianActionLog::where('action_type', WithdrawStagedActionTool::NAME)->count());
    }

    public function test_the_schema_caps_the_reason_at_500(): void
    {
        $this->assertSame(500, WithdrawStagedActionTool::definition()['input_schema']['properties']['reason']['maxLength'] ?? null);
    }

    public function test_an_approval_that_claims_the_run_between_read_and_update_wins(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');

        // The tool has read the run as awaiting; an approval claims it before the CAS.
        TechnicianRun::retrieved(function (TechnicianRun $read) use ($run): void {
            if ($read->id === $run->id && $read->state === TechnicianRunState::AwaitingApproval) {
                TechnicianRun::whereKey($read->id)->update(['state' => TechnicianRunState::Executing->value]);
            }
        });

        $result = $this->withdraw($run->id, 'chet');

        $this->assertStringContainsString("Run #{$run->id} is not awaiting approval (state: executing)", (string) ($result['error'] ?? ''));
        $this->assertStringNotContainsString('no longer', (string) $result['error']);
        $fresh = TechnicianRun::withoutEvents(fn () => TechnicianRun::query()->whereKey($run->id)->toBase()->first());
        $this->assertSame('executing', $fresh->state);
        $this->assertArrayNotHasKey('withdrawn_by', json_decode((string) $fresh->proposed_meta, true));
        $this->assertSame(0, TechnicianActionLog::where('action_type', WithdrawStagedActionTool::NAME)->count());
    }

    public function test_a_malformed_run_id_is_refused(): void
    {
        foreach ([0, -1, '5', null, 1.5] as $bad) {
            $result = $this->mcp(WithdrawStagedActionTool::NAME, ['run_id' => $bad, 'reason' => 'x'], 0, 'chet');
            $this->assertSame('run_id is required and must be a positive integer.', $result['error'] ?? null);
        }
    }

    public function test_calibration_does_not_count_a_drafter_withdrawal_as_a_veto(): void
    {
        $ticket = $this->ticket();
        $run = $this->rawRun($ticket, TechnicianRunState::AwaitingApproval, ['drafted_by_token' => 'chet'], 'propose_close', 0.9);

        $this->assertTrue($this->withdraw($run->id, 'chet')['success'] ?? false);

        $band = collect(app(CloseBandEvaluator::class)->evaluate())->first(fn ($b) => $b->total > 0);
        $this->assertNotNull($band);
        $this->assertSame(1, $band->total);
        $this->assertSame(0, $band->declined);
        $this->assertSame(0, $band->corrected);
        $this->assertSame(1, $band->other);
    }

    public function test_the_cockpit_shows_withdrawn_by_drafter_with_the_reason(): void
    {
        $ticket = $this->ticket();
        $run = $this->stageNote($ticket, 'chet');
        $this->assertTrue($this->withdraw($run->id, 'chet', 'Sender address was mistyped.')['success'] ?? false);

        $response = $this->actingAs(User::factory()->create())->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertSee('Withdrawn by drafter: Sender address was mistyped.');
        $response->assertSee('data-withdrawn-run="'.$run->id.'"', false);
    }

    public function test_the_cockpit_lane_ignores_other_withdrawals_and_denials(): void
    {
        $ticket = $this->ticket();
        $auto = $this->rawRun($ticket, TechnicianRunState::Withdrawn, ['drafted_by_token' => 'chet']);
        $denied = $this->rawRun($ticket, TechnicianRunState::Denied, ['drafted_by_token' => 'chet', 'withdrawn_by' => 'drafter', 'withdrawn_reason' => 'not shown']);

        $response = $this->actingAs(User::factory()->create())->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertDontSee('Withdrawn by drafter');
        $response->assertDontSee('data-withdrawn-run="'.$auto->id.'"', false);
        $response->assertDontSee('data-withdrawn-run="'.$denied->id.'"', false);
    }

    private function rawRun(Ticket $ticket, TechnicianRunState $state, array $meta, string $actionType = 'stage_public_note', ?float $confidence = null): TechnicianRun
    {
        static $seq = 0;
        $seq++;

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', 'withdraw-test:'.$seq),
            'state' => $state,
            'proposed_content' => 'body',
            'proposed_meta' => $meta,
            'confidence' => $confidence,
            'tokens_used' => 0,
        ]);
    }
}
