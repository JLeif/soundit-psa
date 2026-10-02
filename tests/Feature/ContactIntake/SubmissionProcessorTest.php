<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\ClientStage;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use App\Services\Prospect\ProspectIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmissionProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function accept(): ContactSubmission
    {
        Setting::setValue('contact_intake_enabled', '1');
        app(SubmissionLedger::class)->accept('website', [
            'submission_id' => (string) Str::uuid(), 'name' => 'Synthetic Visitor',
            'email' => 'visitor@example.test', 'message' => 'SYNTHETIC_UNTRUSTED_TEXT',
            'inquiry' => 'future-slug', 'submitted_at' => '2026-09-24T12:00:00Z',
        ]);

        return ContactSubmission::latest('id')->firstOrFail();
    }

    public function test_later_submission_reuses_the_sole_open_form_ticket(): void
    {
        Bus::fake();
        $processor = app(SubmissionProcessor::class);
        $one = $processor->process($this->accept()->id);
        $two = $processor->process($this->accept()->id);
        $this->assertSame('processed', $one->state);
        $this->assertSame('processed', $two->state);
        $this->assertSame($one->ticket_id, $two->ticket_id);
        $this->assertNotSame($one->ticket_note_id, $two->ticket_note_id);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_notes', 2);
        $this->assertSame(ClientStage::Prospect, Client::sole()->stage);
        $this->assertFalse(Person::sole()->portal_enabled);
        $this->assertNull(Person::sole()->password);
        $ticket = Ticket::findOrFail($one->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertSame(\App\Enums\TicketSource::WebForm, $ticket->source);
        $this->assertStringContainsString('Inquiry: future-slug', $ticket->description);
        $this->assertSame($one->ticket_id, $processor->process($one->id)->ticket_id);
        $this->assertDatabaseCount('tickets', 1);
        Bus::assertNothingDispatched();
    }

    public function test_existing_ticket_receives_only_a_contained_note(): void
    {
        Bus::fake();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'email' => 'visitor@example.test', 'is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::New]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame($ticket->id, $row->ticket_id);
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertTrue(\App\Models\TicketNote::findOrFail($row->ticket_note_id)->isUnverifiedContactIntake());
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_losing_conditional_identity_candidate_rolls_back_without_orphans(): void
    {
        Bus::fake();
        $row = $this->accept();
        $service = app(ProspectIntakeService::class);
        $first = $service->provisionContactIdentity($row->identity_hash, $row->payload);
        $second = $service->provisionContactIdentity($row->identity_hash, $row->payload);
        $this->assertSame($first['client']->id, $second['client']->id);
        $this->assertSame($first['person']->id, $second['person']->id);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertSame($first['client']->id, DB::table('contact_intake_identities')->value('prospect_client_id'));
    }

    /**
     * Card fs0tKV9e (MariaDB two-process canary): the loser of the conditional identity claim
     * runs inside a caller transaction whose REPEATABLE READ snapshot predates the winner's
     * commit. Its conditional UPDATE is a current read and correctly sees the owner, but a
     * plain re-read of the owner returned the old snapshot and threw ModelNotFoundException,
     * leaving the submission pending. SQLite serialises writers, so this is MariaDB/MySQL-only.
     */
    public function test_loser_with_snapshot_older_than_winner_commit_links_to_winner(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Needs two InnoDB connections with REPEATABLE READ; driver is [{$driver}].");
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.contact_intake_winner' => config("database.connections.{$default}")]);
        $winnerDb = DB::connection('contact_intake_winner');
        $hash = hash('sha256', 'snapshot-loser-'.Str::uuid());
        $data = ['name' => 'Synthetic Snapshot '.Str::random(8), 'email' => 'snapshot-loser@example.test', 'phone' => null, 'company' => null];
        // Committed on the second connection, outside RefreshDatabase's wrapping transaction.
        $winnerDb->table('contact_intake_identities')->insert(['identity_hash' => $hash]);
        $this->beforeApplicationDestroyed(function () use ($winnerDb, $hash, $data) {
            $owner = $winnerDb->table('contact_intake_identities')->where('identity_hash', $hash)->first();
            $winnerDb->table('contact_intake_identities')->where('identity_hash', $hash)->delete();
            $winnerDb->table('people')->where('id', $owner?->person_id)->delete();
            $winnerDb->table('clients')->where('name', $data['name'])->delete();
        });
        // The loser's snapshot is fixed by its first consistent read, as findOrFail does in process().
        $this->assertSame(1, DB::table('contact_intake_identities')->where('identity_hash', $hash)->count());
        $service = app(ProspectIntakeService::class);
        DB::setDefaultConnection('contact_intake_winner');
        try {
            $winner = $service->provisionContactIdentity($hash, $data);
        } finally {
            DB::setDefaultConnection($default);
        }
        $loser = $service->provisionContactIdentity($hash, $data);
        $this->assertSame($winner['client']->id, $loser['client']->id);
        $this->assertSame($winner['person']->id, $loser['person']->id);
        $this->assertSame(1, $winnerDb->table('clients')->where('name', $data['name'])->count());
    }
}
