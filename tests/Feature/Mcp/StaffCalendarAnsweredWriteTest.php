<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * #6264: Graph answered a create/update, and projecting the returned event then threw (here
 * 'attendees' is not an array, so array_map throws a TypeError). The write landed, so it is
 * reported as executed with a fault, never as an unknown outcome, and the event id is recorded.
 * #6520: these tests mock GraphClient, so CalendarGraphShapes::assertEvent() does not run here;
 * the mocked answer stands for the array GraphClient returns once assertEvent() has accepted the
 * decoded event (it checks the id only), which is why this shape is reachable in production.
 *
 * #6265: the immediate write path tracks whether the write call was entered, as the approval
 * path does. A non-Graph throwable after that point is an unresolved outcome (audited, logged by
 * class, returned as a tool error); one raised before it still propagates.
 *
 * GraphClient is mocked and Http::preventStrayRequests() is on (G-5). Synthetic values only (G-13).
 */
class StaffCalendarAnsweredWriteTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner6264@example.test';

    private const CREATE = ['user_upn' => self::OWNER, 'subject' => 'Onsite', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'reason' => 'Asked.'];

    /**
     * #6520: models the array GraphClient::createEvent()/updateEvent() returns after
     * CalendarGraphShapes::assertEvent() accepted the decoded stdClass event (assertEvent() checks
     * the id, a string here, and not attendees). projectEvent() cannot read it. GraphClient is
     * mocked in these tests, so assertEvent() itself does not run on this value.
     */
    private const UNREADABLE_EVENT = ['id' => 'EVT-NEW-6264', 'subject' => 'Onsite', 'attendees' => 'not-a-list'];

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create(['name' => 'Gus']);
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode([self::OWNER]));
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /** @param list<MessageLogged> $logged @return list<MessageLogged> */
    private static function matching(array $logged, string $needle): array
    {
        return array_values(array_filter($logged, fn (MessageLogged $m) => str_contains($m->message, $needle)));
    }

    private function staged(array $arguments, string $stagedTool): TechnicianRun
    {
        $ticket = Ticket::factory()->create();
        $staged = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        return TechnicianRun::findOrFail($staged['run_id']);
    }

    /**
     * Rows: [arguments, staged tool, direct tool, write method]. The update carries no body, so it
     * makes no pre-read.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
     */
    public static function answeredWrites(): array
    {
        return [
            'create' => [self::CREATE, 'calendar_stage_create_event', 'calendar_create_event', 'createEvent'],
            'update' => [['user_upn' => self::OWNER, 'event_id' => 'EVT-NEW-6264', 'subject' => 'Moved', 'reason' => 'Asked.'], 'calendar_stage_update_event', 'calendar_update_event', 'updateEvent'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answeredWrites')]
    public function test_an_approved_write_graph_answered_but_the_psa_could_not_read_is_executed_with_a_fault(array $arguments, string $stagedTool, string $directTool, string $writeMethod): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive($writeMethod)->once()->andReturn(self::UNREADABLE_EVENT));
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status, 'the write landed: not outcome_unknown');
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do NOT re-approve it; check the calendar.', $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(
            ['Operator-approved calendar write EXECUTED (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read (TypeError). Do NOT re-approve; check the calendar.'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed_with_fault')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->count(), 'never a failure row');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%UNRESOLVED%')->count());
        $this->assertStringContainsString('calendar event EVT-NEW-6264 in', (string) TicketNote::where('ticket_id', $run->ticket_id)->value('body'), 'the back-link records the event id');
        $records = self::matching($logged(), 'could not be projected');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        // #6515: the line carries the ticket id and, on the approval path, the run id.
        $this->assertSame(['tool' => $directTool, 'ticket_id' => $run->ticket_id, 'run_id' => $run->id, 'exception' => \TypeError::class, 'file' => 'app/Services/Mcp/StaffCalendarToolExecutor.php', 'line' => self::projectionFaultLine()], $records[0]->context);
        $this->assertSame([], self::matching($logged(), 'unresolved'));
        // #6509/#6514/#6518: the 'executed' row is written, then the fault row, as on the immediate path.
        $this->assertSame(
            ['awaiting_approval', 'executed', 'executed_with_fault'],
            TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->pluck('result_status')->all(),
        );

        // The landed write is not re-staged, and the audit-row guard (run_id null) refuses it
        // before the Done-run check is reached.
        $again = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $run->ticket_id], 0, 'mcp-staff:chet', 'chet');
        $this->assertTrue($again['already_executed'] ?? false);
        $this->assertNull($again['run_id']);
        $this->assertSame('This exact calendar write already executed; not staged.', $again['message']);
    }

    /**
     * #6518: the audit-row guard alone refuses a re-stage of a landed write with a projection
     * fault. The run is moved off Done (as another path could), so the Done-run check in
     * stageWrite() no longer catches it; no second run is staged and nothing is re-approvable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('answeredWrites')]
    public function test_an_approved_write_with_a_projection_fault_is_refused_by_the_audit_row_guard(array $arguments, string $stagedTool, string $directTool, string $writeMethod): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive($writeMethod)->once()->andReturn(self::UNREADABLE_EVENT));
        $this->assertSame('executed_with_fault', app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id)->status);
        TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Denied->value]);

        $again = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $run->ticket_id], 0, 'mcp-staff:chet', 'chet');

        $this->assertSame(['success' => true, 'staged' => false, 'idempotent' => true, 'already_executed' => true, 'run_id' => null, 'ticket_id' => $run->ticket_id, 'message' => 'This exact calendar write already executed; not staged.'], $again);
        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state, 'the run is not revived');
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'awaiting_approval')->count(), 'nothing re-staged');
    }

    /**
     * #6511: the audit rows are written before the back-link, so a back-link failure leaves the
     * 'executed' row and (on a projection fault) the fault row, then the bookkeeping error row.
     * The approver text is unchanged.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: string, 3: string, 4: string}>
     */
    public static function backlinkFailures(): array
    {
        return [
            'projection fault' => [self::UNREADABLE_EVENT, ['awaiting_approval', 'executed', 'executed_with_fault', 'error'], 'executed_with_fault', 'Calendar write executed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do NOT re-approve it; check the calendar.',
                'Calendar write EXECUTED (event EVT-NEW-6264) and the run is Done, but the ticket back-link failed (RuntimeException). The event Microsoft Graph returned could not be read either (TypeError). Verify the ticket record manually.'],
            'no fault' => [['id' => 'EVT-NEW-6511', 'subject' => 'Onsite'], ['awaiting_approval', 'executed', 'error'], 'executed', 'Calendar write executed after approval.',
                'Calendar write EXECUTED (event EVT-NEW-6511) and the run is Done, but the ticket back-link failed (RuntimeException). Verify the ticket record manually.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backlinkFailures')]
    public function test_a_back_link_failure_after_an_approved_write_keeps_the_audit_rows(array $event, array $statuses, string $status, string $message, string $errorText): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturn($event));
        TicketNote::creating(function (): void {
            throw new \RuntimeException('synthetic back-link failure');
        });

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame($status, $r->status);
        $this->assertSame($message, $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(0, TicketNote::where('ticket_id', $run->ticket_id)->count(), 'the back-link was not written');
        $rows = TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->get();
        $this->assertSame($statuses, $rows->pluck('result_status')->all());
        $this->assertSame('Operator-approved calendar write executed.', $rows[1]->summary);
        // #6510(a)/#6654: the row names the back-link, never an audit (the rows above were
        // written), carries the event id, and names a projection fault when there is one.
        $this->assertSame($errorText, $rows->last()->summary);
        if ($status === 'executed_with_fault') {
            $this->assertSame('Operator-approved calendar write EXECUTED (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read (TypeError). Do NOT re-approve; check the calendar.', $rows[2]->summary);
        }
    }

    /**
     * contract:1: each audit row is best-effort, as on the immediate path, so a failed 'executed'
     * insert after an approved write drops neither the fault row nor the back-link, which carries
     * the event id. The run stays Done and the approver text is unchanged.
     */
    public function test_an_executed_row_failure_after_an_approved_write_keeps_the_fault_row_and_the_back_link(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturn(self::UNREADABLE_EVENT));
        TechnicianActionLog::creating(function (TechnicianActionLog $row): void {
            if ($row->result_status === 'executed') {
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do NOT re-approve it; check the calendar.', $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(
            ['awaiting_approval', 'executed_with_fault'],
            TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->pluck('result_status')->all(),
            'the fault row is still written and no bookkeeping error row is needed',
        );
        $this->assertStringContainsString('calendar event EVT-NEW-6264 in', (string) TicketNote::where('ticket_id', $run->ticket_id)->value('body'), 'the back-link records the event id');
        $records = self::matching($logged(), 'audit write failed');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['action' => 'calendar_stage_create_event', 'status' => 'executed', 'exception' => \RuntimeException::class], $records[0]->context);
    }

    /**
     * contract:1: the approver label is resolved once after Done, under a guard, so a failed users
     * read drops neither audit row nor the back-link, and is not reported as a close failure. The
     * rows carry the "approver:{id}" fallback.
     */
    public function test_an_approver_lookup_failure_after_an_approved_write_keeps_the_audit_rows_and_the_back_link(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturn(self::UNREADABLE_EVENT));
        $approverId = $this->approver->id;
        User::retrieved(function (User $user) use ($approverId): void {
            if ((int) $user->getKey() === $approverId) {
                throw new \RuntimeException('synthetic approver lookup failure');
            }
        });
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $approverId);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do NOT re-approve it; check the calendar.', $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $rows = TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->get();
        $this->assertSame(['awaiting_approval', 'executed', 'executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame(["approver:{$approverId}", "approver:{$approverId}"], [$rows[1]->actor_label, $rows[2]->actor_label]);
        $this->assertStringContainsString('calendar event EVT-NEW-6264 in', (string) TicketNote::where('ticket_id', $run->ticket_id)->value('body'), 'the back-link records the event id');
        $this->assertSame([], self::matching($logged(), 'closing the run failed'));
        $records = self::matching($logged(), 'approver lookup failed');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'exception' => \RuntimeException::class], $records[0]->context);
    }

    /** Make every read of the approver's users row throw (a users-read fault). */
    private function failApproverLookups(int $approverId): void
    {
        User::retrieved(function (User $user) use ($approverId): void {
            if ((int) $user->getKey() === $approverId) {
                throw new \RuntimeException('synthetic approver lookup failure');
            }
        });
    }

    /** Graph answers $answer; during the call another request takes the claim, so the fence is lost. */
    private function mockFenceLosingCreate(TechnicianRun $run, array $answer): void
    {
        $taken = now()->addMinute()->startOfSecond();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturnUsing(function () use ($run, $taken, $answer) {
            DB::table('technician_runs')->where('id', $run->id)->update(['claimed_at' => $taken]);

            return $answer;
        }));
    }

    /**
     * #6653: on the lost-fence arm a users-read fault neither drops the executed_with_fault row
     * nor sends the arm into the close-failure arm. The row carries the "approver:{id}" fallback.
     */
    public function test_an_approver_lookup_failure_on_the_lost_fence_arm_keeps_the_lost_fence_row(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mockFenceLosingCreate($run, ['id' => 'EVT-NEW-6653', 'subject' => 'Onsite']);
        $approverId = $this->approver->id;
        $this->failApproverLookups($approverId);
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $approverId);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6653), but this request did not close the run, because it no longer holds it (another request may have claimed or closed it). Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is untouched');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->orderBy('id')->get();
        $this->assertSame(['executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame('Calendar write EXECUTED (event EVT-NEW-6653), but this request did not close the run, because it no longer holds it (another request may have claimed or closed it). Do NOT re-approve; check the calendar and the run.', $rows[0]->summary);
        $this->assertSame("approver:{$approverId}", $rows[0]->actor_label);
        $this->assertSame([], self::matching($logged(), 'closing the run failed'));
        $records = self::matching($logged(), 'approver lookup failed');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['run_id' => $run->id, 'exception' => \RuntimeException::class], $records[0]->context);
    }

    /**
     * #6653: advanceTo(Done) throws and the users read throws too. One executed_with_fault
     * 'closing the run failed' row is written and no exception escapes.
     */
    public function test_an_approver_lookup_failure_on_the_close_failure_arm_keeps_the_close_failure_row(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturnUsing(function () {
            DB::beforeExecuting(function (string $sql, array $bindings): void {
                if (str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'technician_runs')
                    && in_array(TechnicianRunState::Done->value, $bindings, true)) {
                    throw new \RuntimeException('synthetic close failure');
                }
            });

            return ['id' => 'EVT-NEW-6653', 'subject' => 'Onsite'];
        }));
        $approverId = $this->approver->id;
        $this->failApproverLookups($approverId);
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $approverId);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6653), but closing the run failed. Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'not reopened');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->orderBy('id')->get();
        $this->assertSame(['executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame('Calendar write EXECUTED (event EVT-NEW-6653), but closing the run failed (RuntimeException). Do NOT re-approve; check the calendar and the run.', $rows[0]->summary);
        $this->assertSame("approver:{$approverId}", $rows[0]->actor_label);
        $this->assertCount(1, self::matching($logged(), 'closing the run failed'));
        $this->assertCount(1, self::matching($logged(), 'approver lookup failed'));
    }

    /**
     * #6510(b): the fence is lost AND the returned event could not be read. The lost-fence row
     * and the approver text name both faults (the projection fault by class in the row).
     */
    public function test_a_lost_fence_with_a_projection_fault_records_both_faults(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mockFenceLosingCreate($run, self::UNREADABLE_EVENT);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but this request did not close the run, because it no longer holds it (another request may have claimed or closed it). The event Microsoft Graph returned could not be read either. Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertSame(
            ['Calendar write EXECUTED (event EVT-NEW-6264), but this request did not close the run, because it no longer holds it (another request may have claimed or closed it). The event Microsoft Graph returned could not be read either (TypeError). Do NOT re-approve; check the calendar and the run.'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->pluck('summary')->all(),
        );
        $this->assertSame(0, TicketNote::where('ticket_id', $run->ticket_id)->count(), 'no back-link on this arm');
    }

    /** advanceTo(Done) throws: every UPDATE of the run to Done fails (the close fails). */
    private static function failTheClose(): void
    {
        DB::beforeExecuting(function (string $sql, array $bindings): void {
            if (str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'technician_runs')
                && in_array(TechnicianRunState::Done->value, $bindings, true)) {
                throw new \RuntimeException('synthetic close failure');
            }
        });
    }

    /**
     * #6510(b)/context:2 (r2): advanceTo(Done) throws AND the returned event could not be read.
     * As on the lost fence, the close-failure row names the projection fault by class and both
     * the row and the approver text carry the event id; nothing reopens the run.
     */
    public function test_a_close_failure_with_a_projection_fault_records_both_faults_and_the_event_id(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturnUsing(function () {
            self::failTheClose();

            return self::UNREADABLE_EVENT;
        }));

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but closing the run failed. The event Microsoft Graph returned could not be read either. Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'not reopened');
        $this->assertSame(
            ['Calendar write EXECUTED (event EVT-NEW-6264), but closing the run failed (RuntimeException). The event Microsoft Graph returned could not be read either (TypeError). Do NOT re-approve; check the calendar and the run.'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->pluck('summary')->all(),
        );
        $this->assertSame(0, TicketNote::where('ticket_id', $run->ticket_id)->count(), 'no back-link on this arm');
    }

    /**
     * #6510(b) (r2): advanceTo(Done) throws with no projection fault. The row and the text carry
     * the event id and claim no unreadable event.
     */
    public function test_a_close_failure_without_a_projection_fault_carries_the_event_id_and_claims_no_fault(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturnUsing(function () {
            self::failTheClose();

            return ['id' => 'EVT-NEW-6510', 'subject' => 'Onsite'];
        }));

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertSame('Calendar write executed (event EVT-NEW-6510), but closing the run failed. Do NOT re-approve it; check the calendar and the run.', $r->message);
        $this->assertStringNotContainsString('could not be read', (string) $r->message);
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->pluck('summary')->all();
        $this->assertSame(['Calendar write EXECUTED (event EVT-NEW-6510), but closing the run failed (RuntimeException). Do NOT re-approve; check the calendar and the run.'], $rows);
        $this->assertStringNotContainsString('could not be read', $rows[0]);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'not reopened');
    }

    /**
     * The users read throws, and so does every Log::error call (the error channel is down). Other
     * levels are swallowed by the double, so the test isolates the guard's own error log.
     */
    private function failApproverLookupsAndTheLogger(int $approverId): void
    {
        $this->failApproverLookups($approverId);
        $logger = \Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')->andThrow(new \RuntimeException('synthetic logger failure'));
        Log::swap($logger);
    }

    /**
     * diff:5/contract:1 (r2): the users read throws AND logging that failure throws. The guard
     * swallows both, so the run still reaches Done, the 'executed' row carries the fallback label,
     * the back-link is written and nothing escapes approveStagedRun.
     */
    public function test_a_throwing_logger_in_the_approver_guard_cannot_stop_the_run_closing(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturn(['id' => 'EVT-NEW-6653', 'subject' => 'Onsite']));
        $approverId = $this->approver->id;
        $this->failApproverLookupsAndTheLogger($approverId);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $approverId);

        $this->assertSame('executed', $r->status);
        $this->assertSame('Calendar write executed after approval.', $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $rows = TechnicianActionLog::where('run_id', $run->id)->orderBy('id')->get();
        $this->assertSame(['awaiting_approval', 'executed'], $rows->pluck('result_status')->all());
        $this->assertSame("approver:{$approverId}", $rows[1]->actor_label);
        $this->assertStringContainsString('calendar event EVT-NEW-6653 in', (string) TicketNote::where('ticket_id', $run->ticket_id)->value('body'));
    }

    /**
     * diff:5/contract:1 (r2): the same double fault on the lost-fence arm. The lost-fence row is
     * written with the fallback label, the other claim is untouched and nothing escapes.
     */
    public function test_a_throwing_logger_in_the_approver_guard_keeps_the_lost_fence_row(): void
    {
        $run = $this->staged(self::CREATE, 'calendar_stage_create_event');
        $this->mockFenceLosingCreate($run, ['id' => 'EVT-NEW-6653', 'subject' => 'Onsite']);
        $approverId = $this->approver->id;
        $this->failApproverLookupsAndTheLogger($approverId);

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $approverId);

        $this->assertSame('executed_with_fault', $r->status);
        $this->assertStringStartsWith('Calendar write executed (event EVT-NEW-6653), but this request did not close the run', (string) $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'the other claim is untouched');
        $rows = TechnicianActionLog::where('run_id', $run->id)->where('result_status', '!=', 'awaiting_approval')->get();
        $this->assertSame(['executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame("approver:{$approverId}", $rows[0]->actor_label);
    }

    /** The source line of projectEvent()'s attendees array_map, where UNREADABLE_EVENT's TypeError is raised. */
    private static function projectionFaultLine(): int
    {
        $lines = file(base_path('app/Services/Mcp/StaffCalendarToolExecutor.php'));
        $hits = array_keys(array_filter($lines, fn (string $l) => str_contains($l, "array_map([\$this, 'projectAttendee']")));
        self::assertCount(1, $hits);

        return $hits[0] + 1;
    }

    /**
     * Rows: [direct tool, arguments, write method, back-link verb]. The update carries no body, so
     * it makes no pre-read.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function immediateAnsweredWrites(): array
    {
        return [
            'create' => ['calendar_create_event', self::CREATE, 'createEvent', 'created'],
            'update' => ['calendar_update_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-NEW-6264', 'subject' => 'Moved', 'reason' => 'Asked.'], 'updateEvent', 'updated'],
        ];
    }

    /**
     * #6264 on the immediate path; #6517(2): the update arm too, which also goes through
     * projectAnsweredEvent() and writes the executed_with_fault row and the warning. #6515: the
     * log line carries the ticket id and no run id (the immediate path has no run).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('immediateAnsweredWrites')]
    public function test_an_immediate_write_graph_answered_but_the_psa_could_not_read_is_reported_landed(string $directTool, array $arguments, string $writeMethod, string $verb): void
    {
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive($writeMethod)->once()->andReturn(self::UNREADABLE_EVENT));
        $logged = $this->captureLogs();

        $result = app(StaffCalendarToolExecutor::class)->execute($directTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame(['id' => 'EVT-NEW-6264'], $result['event']);
        $this->assertSame('The calendar write landed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do not retry; check the calendar.', $result['warning']);
        $rows = TechnicianActionLog::where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertSame(['executed', 'executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame('Calendar write EXECUTED (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read (TypeError). Do NOT retry; check the calendar.', $rows[1]->summary);
        $this->assertStringContainsString("{$verb} calendar event EVT-NEW-6264 in", (string) TicketNote::where('ticket_id', $ticket->id)->value('body'));
        $records = self::matching($logged(), 'could not be projected');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['tool' => $directTool, 'ticket_id' => $ticket->id, 'exception' => \TypeError::class, 'file' => 'app/Services/Mcp/StaffCalendarToolExecutor.php', 'line' => self::projectionFaultLine()], $records[0]->context);
    }

    /**
     * #6265: rows: [direct tool, arguments, write method]. Update carries a body, so its pre-read
     * succeeds first.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function immediateEnteredWrites(): array
    {
        return [
            'create' => ['calendar_create_event', self::CREATE, 'createEvent'],
            'update with a body' => ['calendar_update_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'body' => 'New agenda', 'reason' => 'Asked.'], 'updateEvent'],
            'cancel' => ['calendar_cancel_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'comment' => 'x', 'reason' => 'Resolved.'], 'cancelEvent'],
            'respond' => ['calendar_respond_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'response' => 'accept', 'reason' => 'Asked.'], 'respondEvent'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('immediateEnteredWrites')]
    public function test_an_immediate_write_that_throws_after_the_write_call_was_entered_is_unresolved(string $directTool, array $arguments, string $writeMethod): void
    {
        $ticket = Ticket::factory()->create();
        $failure = new \TypeError('synthetic non-Graph failure '.self::OWNER);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andReturn(['id' => 'EVT-6265', 'isOnlineMeeting' => false])
            ->shouldReceive($writeMethod)->once()->andThrow($failure));
        $logged = $this->captureLogs();

        $result = app(StaffCalendarToolExecutor::class)->execute($directTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        $this->assertSame(['error' => 'The calendar write call was entered and an unexpected error was then raised, so whether the write reached the calendar is unknown. Check the calendar before any retry.'], $result);
        $this->assertSame(
            ['Graph calendar write outcome UNRESOLVED: an unexpected failure was raised after the write call was entered: TypeError'],
            TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'executed')->count());
        $records = self::matching($logged(), 'outcome unresolved');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame('[Calendar] immediate calendar write outcome unresolved: an unexpected failure was raised after the write call was entered', $records[0]->message);
        $this->assertSame(['tool' => $directTool, 'ticket_id' => $ticket->id, 'exception' => \TypeError::class, 'file' => 'tests/Feature/Mcp/StaffCalendarAnsweredWriteTest.php', 'line' => $failure->getLine()], $records[0]->context);
        $this->assertStringNotContainsString(self::OWNER, json_encode($records[0]->context) ?: '');
    }

    /** #6265: before the write call is entered (the update's pre-read throws) nothing was sent, so it propagates as before. */
    public function test_an_immediate_write_that_throws_before_the_write_call_still_propagates(): void
    {
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure'))
            ->shouldReceive('updateEvent')->never());
        $logged = $this->captureLogs();

        $thrown = rescue(fn () => app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'body' => 'New agenda', 'reason' => 'Asked.', 'ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet'), fn ($e) => $e, false);

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->where('summary', 'like', '%UNRESOLVED%')->count());
        $this->assertSame([], self::matching($logged(), 'unresolved'));
    }
}
