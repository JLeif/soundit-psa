<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\Offboarding\OffboardingScope;
use App\Services\Mcp\StaffCalendarToolExecutor;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * r2 layer 2 restack controls (card sPMnZ1l4; Jeeves 2026-09-27 22:27Z): each held-ticket
 * refusal the l2 review added (diff:2, contract:1, contract:3) and the getAsset scope
 * (contract:7) must fail without its fix.
 */
class IntakeR2ActionRefusalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function held(array $attrs = []): Ticket
    {
        $ticket = Ticket::factory()->create($attrs + ['status' => TicketStatus::New]);
        $ticket->forceFill(['contact_intake_origin' => true])->save();

        return $ticket->fresh();
    }

    /** diff:2 — Assistant notes/attachment name the hold, never "not found". */
    public function test_assistant_note_and_attachment_reads_refuse_held_ticket_as_held(): void
    {
        $held = $this->held();
        $exec = new AssistantToolExecutor(null, $held->client_id);
        foreach (['get_ticket_notes' => ['ticket_id' => $held->id], 'get_ticket_attachment' => ['ticket_id' => $held->id, 'attachment_id' => 1]] as $tool => $in) {
            $out = json_encode($exec->execute($tool, $in));
            $this->assertStringContainsString('Unverified contact intake', $out, $tool);
            $this->assertStringNotContainsString('not found', $out, $tool);
        }
    }

    /** diff:2 — Triage get_ticket_notes on a held sibling ticket names the hold. */
    public function test_triage_note_read_of_held_sibling_refuses_as_held(): void
    {
        $current = Ticket::factory()->create(['status' => TicketStatus::New]);
        $held = $this->held(['client_id' => $current->client_id]);
        $out = json_encode((new TriageToolExecutor($current))->execute('get_ticket_notes', ['ticket_id' => $held->id]));
        $this->assertStringContainsString('Unverified contact intake', $out);
        $this->assertStringNotContainsString('not found', $out);
    }

    /** contract:1 — staging a calendar write on a held ticket names the hold, not non-existence. */
    public function test_calendar_stage_on_held_ticket_refuses_as_held(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));
        $held = $this->held();
        $out = json_encode(app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', [
            'user_upn' => 'charlie@soundit.co', 'subject' => 'x', 'start' => '2026-07-29T15:00:00',
            'end' => '2026-07-29T16:00:00', 'ticket_id' => $held->id, 'reason' => 'Synthetic.',
        ], 0, 'mcp-staff:chet'));
        $this->assertStringContainsString('unverified contact intake held for staff verification', $out);
        $this->assertStringNotContainsString('does not resolve to an existing ticket', $out);
    }

    /** contract:3 — the offboarding reader names the hold, not a staff-authorization failure. */
    public function test_offboarding_reader_refuses_held_ticket_as_held(): void
    {
        $held = $this->held();
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $run = new TechnicianRun;
        $run->forceFill(['ticket_id' => $held->id, 'client_id' => $held->client_id]);
        try {
            app(OffboardingScope::class)->reader($admin->id, $run);
            $this->fail('reader() accepted a held ticket');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unverified contact intake', $e->getMessage());
            $this->assertStringNotContainsString('authorized same-client staff reader', $e->getMessage());
        }
    }

    /** contract:7 — get_asset never lists a held ticket linked to the asset. */
    public function test_get_asset_omits_held_linked_tickets(): void
    {
        $normal = Ticket::factory()->create(['status' => TicketStatus::New, 'subject' => 'NORMAL_LINKED_SUBJECT']);
        $held = $this->held(['client_id' => $normal->client_id, 'subject' => 'HELD_LINKED_SUBJECT']);
        $asset = Asset::factory()->create(['client_id' => $normal->client_id]);
        $asset->tickets()->attach([$normal->id, $held->id]);
        $out = json_encode((new AssistantToolExecutor(null, $normal->client_id))
            ->execute('get_asset', ['asset_id' => $asset->id, 'expand' => ['tickets']]));
        $this->assertStringContainsString('NORMAL_LINKED_SUBJECT', $out);
        $this->assertStringNotContainsString('HELD_LINKED_SUBJECT', $out);
        $this->assertStringContainsString('"tickets_count":1', $out);
    }
}
