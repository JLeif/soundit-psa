<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * close_ticket / stage_close_ticket argument allow-list and audit key names (card crRnwaQJ).
 *
 * On 2026-09-25 a caller sent `resolution` instead of `resolution_summary`. The call was
 * refused only because resolution_summary was missing. The refusal did not name
 * `resolution`, and the audit row dropped it. These tests pin the named refusal, the
 * key-name-only audit, and that the refusal writes nothing.
 */
class CloseTicketArgumentAllowListTest extends TestCase
{
    use RefreshDatabase;

    /** A value that must never reach the audit row or the refusal text. */
    private const CLIENT_TEXT = 'CLIENT-TEXT-8f3a Mrs Example said the printer is fixed';

    private const CLOSE_ACCEPTED = 'ticket_id, resolution_summary, reason, status, confidence, staged';

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
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

    private function quietTicket(): Ticket
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'Contact',
            'email' => 'client@example.test',
            'is_active' => true,
        ]);

        // PendingClient + no recent client note = eligible for a direct close, so any refusal
        // below comes from the argument checks, not the close-eligibility envelope.
        return Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'status' => TicketStatus::PendingClient,
            'closed_at' => null,
        ]);
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    private function refusal(TestResponse $response): string
    {
        return (string) (json_decode($this->text($response), true)['error'] ?? '');
    }

    private function lastAudit(string $tool): McpAuditLog
    {
        return McpAuditLog::query()->where('tool_name', $tool)->latest('id')->firstOrFail();
    }

    public function test_a_misnamed_resolution_key_is_refused_by_name_and_writes_nothing(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        // The 2026-09-25 shape: resolution in place of resolution_summary.
        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'status' => 'closed',
            'reason' => 'Client confirmed.',
            'resolution' => self::CLIENT_TEXT,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(
            'Unsupported argument(s): resolution. close_ticket accepts only: '.self::CLOSE_ACCEPTED.'.',
            $this->refusal($response),
        );
        $this->assertStringNotContainsString('CLIENT-TEXT-8f3a', $this->text($response));
        $this->assertNothingWritten($ticket);
    }

    public function test_the_audit_row_names_the_unknown_key_and_never_its_value(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'reason' => 'Client confirmed.',
            'resolution' => self::CLIENT_TEXT,
        ])->assertOk();

        $audit = $this->lastAudit('close_ticket');
        $this->assertSame('error', $audit->status);
        $this->assertSame(['resolution'], $audit->arguments['unknown_keys'] ?? null);
        $this->assertSame(1, $audit->arguments['unknown_key_count'] ?? null);
        // The known keys keep their existing shape beside it.
        $this->assertSame($ticket->id, $audit->arguments['ticket_id'] ?? null);
        $this->assertSame(mb_strlen('Client confirmed.'), $audit->arguments['reason_length'] ?? null);
        // The value is nowhere in the row: not the arguments, not the error message.
        $this->assertStringNotContainsString('CLIENT-TEXT-8f3a', json_encode($audit->arguments));
        $this->assertStringNotContainsString('CLIENT-TEXT-8f3a', (string) $audit->error_message);
        // The refusal runs before ticketForClient(), which is what links a call to its ticket,
        // so the row's ticket_id column is null. The ticket id is still in its arguments.
        $this->assertNull($audit->ticket_id);
    }

    public function test_the_staged_path_refuses_an_unknown_key_by_its_own_name_and_creates_no_run(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Held close.',
            'reason' => 'Held close.',
            'staged' => true,
            'resolution' => self::CLIENT_TEXT,
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(
            'Unsupported argument(s): resolution. stage_close_ticket accepts only: '.self::CLOSE_ACCEPTED.'.',
            $this->refusal($response),
        );
        $this->assertNothingWritten($ticket);

        $audit = $this->lastAudit('stage_close_ticket');
        $this->assertSame(['resolution'], $audit->arguments['unknown_keys'] ?? null);
        $this->assertStringNotContainsString('CLIENT-TEXT-8f3a', json_encode($audit->arguments));
    }

    public function test_the_retired_stage_close_ticket_alias_refuses_an_unknown_key_too(): void
    {
        $token = $this->token(['close_ticket:staged']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'stage_close_ticket', [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Held close.',
            'reason' => 'Held close.',
            'note' => 'x',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringStartsWith('Unsupported argument(s): note. stage_close_ticket accepts only:', $this->refusal($response));
        $this->assertNothingWritten($ticket);
    }

    public function test_only_declared_keys_still_close_the_ticket(): void
    {
        // Over-reach control: every declared key at once must pass the allow-list.
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'status' => 'closed',
            'resolution_summary' => 'Fixed the driver.',
            'reason' => 'Client confirmed.',
            'confidence' => 'high',
            'staged' => false,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertArrayNotHasKey('unknown_keys', $this->lastAudit('close_ticket')->arguments);
    }

    public function test_only_declared_keys_still_stage_a_held_close(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'status' => 'resolved',
            'resolution_summary' => 'Held close.',
            'reason' => 'Held close.',
            'confidence' => 'low',
            'staged' => true,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), $this->text($response));
        $this->assertSame(1, TechnicianRun::query()->where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count());
        $this->assertArrayNotHasKey('unknown_keys', $this->lastAudit('stage_close_ticket')->arguments);
    }

    public function test_the_missing_resolution_summary_refusal_is_unchanged_when_every_key_is_known(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'reason' => 'Client confirmed.',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame('resolution_summary is required — a ticket is never closed silently.', $this->refusal($response));
        $this->assertArrayNotHasKey('unknown_keys', $this->lastAudit('close_ticket')->arguments);
        $this->assertNothingWritten($ticket);
    }

    public function test_unknown_key_names_are_bounded_in_the_refusal_and_the_audit(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $arguments = [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Fixed.',
            'reason' => 'Client confirmed.',
            // Sent FIRST, so an unsorted list would name it first. Sorted, it is 12th and cut.
            str_repeat('z', 200) => self::CLIENT_TEXT,
        ];
        foreach ([11, 3, 1, 2, 4, 5, 6, 7, 8, 9, 10] as $i) {
            $arguments[sprintf('extra_%02d', $i)] = self::CLIENT_TEXT;
        }

        $response = $this->callTool($token, 'close_ticket', $arguments);

        $this->assertTrue((bool) $response->json('result.isError'));
        $refusal = $this->refusal($response);
        $this->assertStringStartsWith('Unsupported argument(s): extra_01, extra_02,', $refusal);
        $this->assertStringContainsString('extra_10 (+2 more). close_ticket accepts only:', $refusal);
        $this->assertStringNotContainsString('extra_11', $refusal);
        $this->assertStringNotContainsString('zzz', $refusal);

        $audit = $this->lastAudit('close_ticket');
        $this->assertSame(array_map(fn ($i) => sprintf('extra_%02d', $i), range(1, 10)), $audit->arguments['unknown_keys'] ?? null);
        $this->assertSame(12, $audit->arguments['unknown_key_count'] ?? null);
        $this->assertStringNotContainsString('CLIENT-TEXT-8f3a', json_encode($audit->arguments));
        $this->assertNothingWritten($ticket);
    }

    public function test_an_over_long_key_name_is_cut_to_64_characters(): void
    {
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Fixed.',
            'reason' => 'Client confirmed.',
            str_repeat('k', 200) => 'v',
        ]);

        $this->assertStringStartsWith('Unsupported argument(s): '.str_repeat('k', 64).'. ', $this->refusal($response));
        $this->assertSame([str_repeat('k', 64)], $this->lastAudit('close_ticket')->arguments['unknown_keys'] ?? null);
        $this->assertSame(1, $this->lastAudit('close_ticket')->arguments['unknown_key_count'] ?? null);
    }

    public function test_the_allow_list_answers_before_the_ticket_lookup_on_both_tools(): void
    {
        // A ticket_id that resolves to nothing: if the allow-list ran after ticketForClient()
        // the answer would be 'Ticket not found...'. Called on the executor directly because
        // the controller refuses an unresolvable ticket_id before dispatch.
        $this->quietTicket(); // AI actor configured; the ticket itself is not the target
        $executor = app(StaffPsaActionToolExecutor::class);

        foreach (['close_ticket', 'stage_close_ticket'] as $tool) {
            $result = $executor->execute($tool, [
                'ticket_id' => 999999,
                'resolution' => self::CLIENT_TEXT,
            ], 1, 'mcp-staff:test');

            $this->assertSame(
                ['error' => "Unsupported argument(s): resolution. {$tool} accepts only: ".self::CLOSE_ACCEPTED.'.'],
                $result,
                $tool,
            );
        }
        $this->assertSame(0, TechnicianRun::query()->count());
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_a_wrong_case_key_is_named_the_same_way_by_the_refusal_and_the_audit(): void
    {
        // The executor reads keys by exact name, so `Status` is not `status` and is refused.
        // The audit must name it in unknown_keys as well.
        $token = $this->token(['close_ticket']);
        $ticket = $this->quietTicket();

        $response = $this->callTool($token, 'close_ticket', [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Fixed.',
            'reason' => 'Client confirmed.',
            'Status' => 'resolved',
        ]);

        $this->assertStringStartsWith('Unsupported argument(s): Status. close_ticket accepts only:', $this->refusal($response));
        $audit = $this->lastAudit('close_ticket');
        $this->assertSame(['Status'], $audit->arguments['unknown_keys'] ?? null);
        $this->assertNothingWritten($ticket);
    }

    public function test_a_staged_key_that_reaches_the_executor_is_refused_not_ignored(): void
    {
        // callTool() unsets `staged` before dispatch, so this can only come from a caller that
        // skips the controller. It must not be read as "hold" and then ignored by a direct close.
        $ticket = $this->quietTicket();

        $result = app(StaffPsaActionToolExecutor::class)->execute('close_ticket', [
            'ticket_id' => $ticket->id,
            'resolution_summary' => 'Fixed.',
            'reason' => 'Client confirmed.',
            'staged' => true,
        ], (int) $ticket->client_id, 'mcp-staff:test');

        $this->assertSame(
            ['error' => 'Unsupported argument(s): staged. close_ticket accepts only: ticket_id, resolution_summary, reason, status, confidence.'],
            $result,
        );
        $this->assertNothingWritten($ticket);
    }

    /** Nothing written: no status change, no note, no action log, no held run. */
    private function assertNothingWritten(Ticket $ticket): void
    {
        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::PendingClient, $fresh->status, 'refused call must not move the ticket');
        $this->assertNull($fresh->resolution, 'refused call must not write a resolution');
        $this->assertSame(0, TicketNote::query()->where('ticket_id', $ticket->id)->count(), 'refused call must not write a note');
        $this->assertSame(0, TechnicianActionLog::query()->where('ticket_id', $ticket->id)->count(), 'refused call must not write an action log');
        $this->assertSame(0, TechnicianRun::query()->where('ticket_id', $ticket->id)->count(), 'refused call must not create a run');
    }
}
