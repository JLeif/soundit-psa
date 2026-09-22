<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntakeMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Ticket $older;

    private Ticket $newer;

    private TechnicianRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
        $client = Client::factory()->create();
        $this->older = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress, 'subject' => 'Existing issue']);
        $this->newer = Ticket::factory()->for($client)->create(['status' => TicketStatus::New, 'subject' => 'New intake']);
        $this->run = TechnicianRun::create([
            'ticket_id' => $this->newer->id, 'client_id' => $client->id,
            'action_type' => 'intake_route', 'content_hash' => hash('sha256', 'intake merge test'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_meta' => ['decision' => 'attach', 'attached' => false,
                'created_ticket_id' => $this->newer->id, 'suggested_ticket_id' => $this->older->id],
        ]);
        $this->actingAs($this->actor);
    }

    private function merge(?int $survivor = null)
    {
        return $this->postJson('/cockpit/runs/'.$this->run->id.'/intake-merge', [
            'survivor_ticket_id' => $survivor ?? $this->older->id, 'confirmed' => 1,
            'suggested_ticket_id' => $this->older->id,
        ]);
    }

    private function references(Ticket $ticket): array
    {
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'author_id' => $this->actor->id,
            'body' => 'Synthetic history', 'note_type' => 'note', 'is_private' => true, 'noted_at' => now()]);
        $call = PhoneCall::create(['call_uuid' => 'synthetic-'.$ticket->id, 'from_number' => '+15555550100', 'ticket_id' => $ticket->id]);
        $email = Email::create(['graph_id' => 'synthetic-'.$ticket->id, 'ticket_id' => $ticket->id,
            'from_address' => 'sender@example.test', 'subject' => 'Synthetic history', 'received_at' => now()]);

        return [$note, $call, $email];
    }

    public function test_older_survives_history_moves_pending_runs_supersede_and_repeat_is_noop(): void
    {
        $references = $this->references($this->newer);
        $pending = $this->run->replicate();
        $pending->content_hash = hash('sha256', 'other pending run');
        $pending->action_type = 'send_reply';
        $pending->save();
        $this->merge()->assertOk()->assertJsonPath('status', 'merged')->assertJsonMissingPath('undo');
        foreach ($references as $reference) {
            $this->assertSame($this->older->id, $reference->fresh()->ticket_id);
        }
        $this->assertSame($this->older->id, $this->newer->fresh()->parent_ticket_id);
        $this->assertSame(TechnicianRunState::Superseded, $pending->fresh()->state);
        $this->assertSame(TechnicianRunState::Done, $this->run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $this->run->id,
            'action_type' => 'propose_merge', 'result_status' => 'executed', 'approver_user_id' => $this->actor->id]);
        $this->merge()->assertOk()->assertJsonPath('status', 'already_handled');
        $this->assertDatabaseCount('technician_action_logs', 1);
    }

    public function test_operator_can_flip_to_newer_survivor(): void
    {
        $references = $this->references($this->older);
        $this->merge($this->newer->id)->assertOk()->assertJsonPath('status', 'merged');
        foreach ($references as $reference) {
            $this->assertSame($this->newer->id, $reference->fresh()->ticket_id);
        }
        $this->assertSame($this->newer->id, $this->older->fresh()->parent_ticket_id);
    }

    public static function refusals(): array
    {
        return array_map(fn ($case) => [$case], [
            'missing', 'closed-survivor', 'resolved-survivor', 'closed-loser',
            'merged-survivor', 'merged-loser', 'cross-client-survivor', 'cross-client-loser',
            'self', 'unrelated-survivor', 'loser-has-children', 'stale-created-id', 'not-attach', 'already-attached',
        ]);
    }

    #[DataProvider('refusals')]
    public function test_click_time_refusal_preserves_tickets_and_releases_claim(string $case): void
    {
        // Render first, then change the underlying pair: render-time validation is insufficient.
        $this->get(route('cockpit.index'))->assertOk();
        $other = Ticket::factory()->create(['client_id' => $this->older->client_id]);
        $meta = $this->run->proposed_meta;
        $survivor = $this->older->id;
        switch ($case) {
            case 'missing': $this->older->delete();
                break;
            case 'closed-survivor': $this->older->update(['status' => TicketStatus::Closed]);
                break;
            case 'resolved-survivor': $this->older->update(['status' => TicketStatus::Resolved]);
                break;
            case 'closed-loser': $this->newer->update(['status' => TicketStatus::Closed]);
                break;
            case 'merged-survivor': $this->older->update(['parent_ticket_id' => $other->id]);
                break;
            case 'merged-loser': $this->newer->update(['parent_ticket_id' => $other->id]);
                break;
            case 'cross-client-survivor': $this->older->update(['client_id' => Client::factory()->create()->id]);
                break;
            case 'cross-client-loser': $this->newer->update(['client_id' => Client::factory()->create()->id]);
                break;
            case 'self': $meta['suggested_ticket_id'] = $this->newer->id;
                $survivor = $this->newer->id;
                break;
            case 'unrelated-survivor': $survivor = $other->id;
                break;
            case 'loser-has-children': $other->update(['parent_ticket_id' => $this->newer->id]);
                break;
            case 'stale-created-id': $meta['created_ticket_id'] = $other->id;
                break;
            case 'not-attach': $meta['decision'] = 'new';
                break;
            case 'already-attached': $meta['attached'] = true;
                break;
        }
        $this->run->update(['proposed_meta' => $meta]);
        $before = Ticket::query()->get()->toArray();
        $this->merge($survivor)->assertOk()->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'gate_declined')->assertSee('Merge refused');
        $this->assertSame($before, Ticket::query()->get()->toArray());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->run->fresh()->state);
        $this->assertDatabaseCount('technician_action_logs', 0);
    }

    public function test_kill_switch_declines_through_real_gate(): void
    {
        Setting::setValue('technician_kill_switch', '1');
        $this->merge()->assertOk()->assertJsonPath('status', 'gate_declined');
        $this->assertNull($this->newer->fresh()->parent_ticket_id);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['run_id' => $this->run->id, 'result_status' => 'held']);
    }

    public function test_confirmation_and_explicit_survivor_are_required(): void
    {
        $this->postJson('/cockpit/runs/'.$this->run->id.'/intake-merge', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmed', 'survivor_ticket_id']);
        $this->assertNull($this->newer->fresh()->parent_ticket_id);
    }

    public function test_merge_form_shows_history_default_and_both_choices_without_undo(): void
    {
        $this->references($this->older);
        $options = app(CockpitQuery::class)->intakeReview()->first()->intake_merge_options;
        $this->assertSame($this->older->id, $options->first()['id']);
        $this->assertSame(3, $options->first()['references']);
        $response = $this->get(route('cockpit.index'))->assertOk()
            ->assertSee('Keep this ticket (survivor)')->assertSee('Existing issue')->assertSee('New intake')
            ->assertSee('Confirm merge into selected survivor')->assertSee('This cannot be undone.');
        preg_match('~<form[^>]*intake-merge.*?</form>~s', $response->getContent(), $form);
        $this->assertNotEmpty($form);
        $this->assertStringContainsString('value="'.$this->older->id.'" selected', $form[0]);
        $this->assertStringNotContainsString('data-undo', $form[0]);
        $this->assertStringContainsString('data-undo-action="dismiss-intake"', $response->getContent());
    }

    public function test_changed_suggestion_cannot_silently_change_loser_after_confirmation(): void
    {
        $other = Ticket::factory()->create(['client_id' => $this->older->client_id]);
        $meta = $this->run->proposed_meta;
        $meta['suggested_ticket_id'] = $other->id;
        $this->run->update(['proposed_meta' => $meta]);
        $this->merge($this->newer->id)->assertOk()->assertJsonPath('status', 'gate_declined');
        $this->assertNull($other->fresh()->parent_ticket_id);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->run->fresh()->state);
    }

    public function test_excluded_client_is_refused(): void
    {
        Setting::setValue('technician_excluded_client_ids', json_encode([$this->run->client_id]));
        $this->merge()->assertOk()->assertJsonPath('status', 'gate_declined');
        $this->assertNull($this->newer->fresh()->parent_ticket_id);
    }

    public function test_guest_cannot_merge(): void
    {
        auth()->logout();
        $this->merge()->assertUnauthorized();
        $this->assertNull($this->newer->fresh()->parent_ticket_id);
    }

    public function test_wrong_action_is_not_claimed(): void
    {
        $this->run->update(['action_type' => 'propose_merge']);
        $this->merge()->assertOk()->assertJsonPath('status', 'already_handled');
        $this->assertNull($this->newer->fresh()->parent_ticket_id);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->run->fresh()->state);
    }

    public function test_default_is_history_not_age(): void
    {
        $this->references($this->newer);
        $options = app(CockpitQuery::class)->intakeReview()->first()->intake_merge_options;
        $this->assertSame($this->newer->id, $options->first()['id']);
    }
}
