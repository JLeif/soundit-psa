<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\ClientStage;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationReconcileService;
use App\Services\Huntress\HuntressService;
use App\Services\TicketService;
use Carbon\CarbonInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HuntressEscalationReconcileService — poll/reconcile resolve path (bd psa-oe19).
 *
 * Escalations that resolve upstream (status → resolved / resolved_at set) don't fire the
 * CW-Manage status webhook, so the bridged PSA ticket strands open — the escalation analogue
 * of the incident gap (psa-kq1u). This service resolves it, under the SAME safety discipline.
 *
 * Only immutable CW candidates correlated with verified events authorize polling.
 * Former subject/window and text-id positives remain refusal controls; lifecycle,
 * retry and human-touch controls use real candidate/event promotion.
 * Subject text and legacy metadata are not authority.
 *
 * Only the Huntress HTTP boundary is faked; reconcile/scope/correspondence/guards are real.
 */
class HuntressEscalationReconcileTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->systemUser = User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $this->systemUser->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function mappedClient(int $orgId = 42): Client
    {
        return Client::factory()->create(['huntress_organization_id' => $orgId]);
    }

    /**
     * A real ESCALATION ticket: subject carries "… Escalation | <core>". `metaEscalationId`
     * simulates the ingest-fix clean path; `sourceAlertId` the URL/hash on the linked alert.
     */
    private function escalationTicket(
        Client $client,
        string $subject = 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
        TicketStatus $status = TicketStatus::InProgress,
        ?int $metaEscalationId = null,
        ?string $sourceAlertId = null,
    ): Ticket {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => $status->value,
            'client_id' => $client->id,
            'subject' => $subject,
            'description' => 'Huntress escalation notice. Please review.',
            'closed_at' => null,
        ]);

        // Standard system audit note (system-generated — not a human touch).
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Submitted via Huntress incident report.',
            'note_type' => NoteType::StatusChange->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => $sourceAlertId ?? md5('synth-'.$ticket->id),
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => $subject,
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'fired_at' => $ticket->created_at,
            'metadata' => ['escalation_id' => $metaEscalationId],
        ]);

        return $ticket;
    }

    /** An escalation row as returned by getEscalations / getEscalation. */
    private function escalationRow(
        int $id,
        ?int $orgId,
        string $status,
        CarbonInterface $createdAt,
        string $subject,
        ?string $resolvedAt = null,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'resolved_at' => $resolvedAt,
            'severity' => 'high',
            'subject' => $subject,
            'type' => 'Escalation',
            'subtype' => 'SecurityControls',
            'created_at' => $createdAt->toIso8601String(),
            'updated_at' => $createdAt->toIso8601String(),
            'organizations' => $orgId !== null ? [['id' => $orgId, 'name' => 'Blue Org']] : [],
        ];
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $escalationsByOrg  org id → rows
     * @param  array<int, array<string, mixed>>  $escalationsById  id → single escalation
     */
    private function service(array $escalationsByOrg = [], array $escalationsById = []): HuntressEscalationReconcileService
    {
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getEscalations')->never();
        if ($escalationsById === []) {
            $client->shouldReceive('getEscalation')->never();
        } else {
            $client->shouldReceive('getEscalation')
                ->andReturnUsing(fn (int $id) => $escalationsById[$id] ?? throw new \LogicException('Unexpected synthetic id'));
        }

        return new HuntressEscalationReconcileService(
            $client,
            app(TicketService::class),
            app(AlertService::class),
        );
    }

    private function bind(Ticket $ticket, int $id): void
    {
        Setting::setValue('huntress_webhooks_enabled', '1');
        Setting::setValue('huntress_webhook_account_id', '11');
        \Illuminate\Support\Facades\DB::table('huntress_webhook_events')->insert([
            'delivery_id' => 'synthetic-'.$ticket->id, 'content_hash' => str_repeat('a', 64),
            'event_type' => 'escalation.created', 'record_type' => 'escalation',
            'record_id' => $id, 'account_id' => 11, 'organization_ids' => '[42]',
            'resolved' => false, 'record_created_at' => $ticket->created_at,
            'correlation_at' => $ticket->created_at, 'received_at' => now(),
        ]);
        app(\App\Services\Huntress\HuntressLinkService::class)->capture(
            Alert::where('ticket_id', $ticket->id)->firstOrFail(), $ticket,
            'https://synthetic.huntress.io/org/42/escalations/'.$id, $ticket->created_at->toDateTimeString());
        $this->assertEquals($id, Alert::where('ticket_id', $ticket->id)->first()->huntress_record_id);
    }

    private function assertResolved(Ticket $ticket): void
    {
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::Resolved->value)
            ->first();
        $this->assertNotNull($note, 'expected an attributed resolve status-change note');
        $this->assertSame($this->systemUser->id, $note->author_id);
        $this->assertStringContainsStringIgnoringCase('huntress', $note->body);
        $this->assertStringContainsStringIgnoringCase('escalation', $note->body);
    }

    private function assertStaysOpen(Ticket $ticket): void
    {
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ── correspondence: org + subject + window (the legacy no-id path) ──

    public function test_unique_org_subject_window_without_signed_link_never_resolves(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(701, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_resolves_when_only_resolved_at_is_set_status_absent(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(702, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality', resolvedAt: now()->toIso8601String()),
        ]];

        $this->bind($ticket, 702);
        $this->service([], [702 => $escalations[42][0]])->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    /** THE motivating safety case: account-level "Failed to Deliver" has no org + no id → skip. */
    public function test_account_level_failed_to_deliver_is_never_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, 'Huntress High Escalation | Failed to Deliver');

        // A RESOLVED account-level escalation with the exact subject sits in the window — but it
        // carries no organizations[], so there is no positive org correspondence. Must skip.
        $escalations = [42 => [
            $this->escalationRow(710, null, 'resolved', $ticket->created_at, 'Failed to Deliver'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    /** A coincidental sibling close must not resolve a ticket whose own escalation is still open. */
    public function test_sibling_close_with_own_escalation_still_open_is_ambiguous_skip(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Two escalations, same org/subject/window: one resolved sibling + the ticket's own,
        // still open. Uniqueness-across-statuses makes this ambiguous → skip.
        $escalations = [42 => [
            $this->escalationRow(720, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
            $this->escalationRow(721, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_two_resolved_escalations_same_subject_in_window_are_ambiguous_skip(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(730, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
            $this->escalationRow(731, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_subject_mismatch_is_not_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(740, 42, 'resolved', $ticket->created_at, 'Completely Unrelated Escalation Reason'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_match_outside_the_window_is_not_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Correct org + subject, but created 3h from the ticket → a later escalation, not this one.
        $escalations = [42 => [
            $this->escalationRow(750, 42, 'resolved', $ticket->created_at->copy()->addHours(3), 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_escalation_for_a_different_org_is_excluded(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Even if returned in the org-42 response, an escalation whose organizations[] is org 99
        // carries no correspondence to this org-42 ticket.
        $escalations = [42 => [
            $this->escalationRow(760, 99, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_unique_but_still_open_escalation_leaves_ticket_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(770, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    // ── correspondence: exact id fast path (ingest-captured escalation id) ──

    public function test_metadata_id_without_signed_link_never_resolves(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, metaEscalationId: 555);

        $result = $this->service()->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_id_bearing_ticket_with_still_open_escalation_stays_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, metaEscalationId: 556);

        $this->bind($ticket, 556);
        $this->service([], [556 => ['id' => 556, 'status' => 'sent', 'organizations' => [['id' => 42]]]])->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_404_skips_the_ticket_through_real_client_exception_wrapping(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $this->bind($ticket, 555);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new HuntressClient([], new GuzzleClient([
            'base_uri' => 'https://api.huntress.io/v1/',
            'handler' => $stack,
        ]));
        $service = new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class));

        $result = $service->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        $this->assertCount(1, $history, 'a stale id must not reach the org listing');
        $this->assertSame('/v1/escalations/555', $history[0]['request']->getUri()->getPath());
        $this->assertSame(1, $result->errors);
        $this->assertContains("#{$ticket->id}: fetch_failed", $result->errorMessages);
        // No tombstone: the next run retries the id (a purge may be reversed upstream).
        $this->assertSame(0, $service->reconcile()->updated);
        $this->assertCount(2, $history);
        $this->assertSame('/v1/escalations/555', $history[1]['request']->getUri()->getPath());
    }

    /**
     * THE mis-close vector the 404 path must not open. Once the by-id read fails we can no
     * longer confirm WHICH org row is this ticket's, so a unique org+subject+window match
     * stops implying ownership and a lone survivor may be a sibling. Note the claim we do
     * NOT make, and which the class docblock refutes: a 404 is not evidence this ticket's
     * escalation is absent from the org listing.
     *
     * No sibling is fixtured, deliberately. `shouldNotReceive('getEscalations')` asserts the
     * listing is never consulted AT ALL for a stale-id ticket, which is strictly stronger
     * than asserting some particular sibling goes unmatched — it holds whatever the org
     * contains. That assertion is the whole test; the resolution assertions below would pass
     * against pre-change code too. This is a guard against reintroducing the fallback, not a
     * demonstration that today's code was broken.
     */
    public function test_404_does_not_close_the_ticket_off_a_sibling_escalation(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $client = Mockery::mock(HuntressClient::class);
        $this->bind($ticket, 555);
        $client->shouldReceive('getEscalation')->once()->with(555)->andThrow(new HuntressClientException('Not found', 404));
        $client->shouldNotReceive('getEscalations');

        $result = (new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class)))->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    #[DataProvider('non404Failures')]
    public function test_other_throwables_count_errors_and_skip_without_fallback(\Throwable $failure): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $client = Mockery::mock(HuntressClient::class);
        $this->bind($ticket, 555);
        $client->shouldReceive('getEscalation')->twice()->with(555)->andThrow($failure);
        $client->shouldNotReceive('getEscalations');
        $service = new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class));

        foreach (range(1, 2) as $attempt) {
            $result = $service->reconcile();
            $this->assertSame(0, $result->updated);
            $this->assertSame(1, $result->errors);
            $this->assertContains("#{$ticket->id}: fetch_failed", $result->errorMessages);
            $this->assertStringNotContainsString($failure->getMessage(), implode(' ', $result->errorMessages));
        }

        $this->assertStaysOpen($ticket);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        // Vendor exception bodies must not enter diagnostics or logs.
        Log::shouldNotHaveReceived('warning');
    }

    public static function non404Failures(): array
    {
        return [
            'server error' => [new HuntressClientException('Unavailable', 503)],
            'unauthorized' => [new HuntressClientException('Unauthorized', 401)],
            'rate limited' => [new HuntressClientException('Rate limited', 429)],
            'transport' => [new HuntressClientException('Timeout')],
            'unrelated 404 code' => [new \RuntimeException('Not an HTTP error', 404)],
            'error' => [new \Error('Unexpected failure')],
        ];
    }

    public function test_source_alert_url_without_signed_link_never_resolves(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket(
            $client,
            sourceAlertId: 'https://dashboard.huntress.io/org/42/escalations/777',
        );

        $result = $this->service()->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    // ── scope ──────────────────────────────────────────────────────────────

    public function test_incident_ticket_is_never_touched(): void
    {
        $client = $this->mappedClient(42);
        // Incident tickets are the incident reconciler's job — even though a resolved escalation
        // for the org sits in the window, an "Incident on <host>" ticket is out of scope here.
        $incident = $this->escalationTicket($client, 'Huntress EDR Critical Incident Report | Incident on DESKTOP-ARL0EQ1 (Blue Org)');

        $escalations = [42 => [
            $this->escalationRow(780, 42, 'resolved', $incident->created_at, 'Incident on DESKTOP-ARL0EQ1'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($incident);
        $this->assertSame(0, $result->updated);
    }

    public function test_product_notice_without_escalation_marker_is_never_touched(): void
    {
        $client = $this->mappedClient(42);
        $notice = $this->escalationTicket($client, 'Huntress Product Notice | Scheduled Maintenance');

        $escalations = [42 => [
            $this->escalationRow(790, 42, 'resolved', $notice->created_at, 'Scheduled Maintenance'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($notice);
    }

    public function test_ignores_non_huntress_tickets(): void
    {
        $client = $this->mappedClient(42);
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'client_id' => $client->id,
            'subject' => 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
            'closed_at' => null,
        ]);

        $escalations = [42 => [
            $this->escalationRow(800, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    // ── guards ─────────────────────────────────────────────────────────────

    public function test_is_idempotent_across_runs(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
        $escalations = [42 => [$this->escalationRow(810, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];

        $this->bind($ticket, 810);
        $this->service([], [810 => $escalations[42][0]])->reconcile();
        $this->service([], [810 => $escalations[42][0]])->reconcile();

        $resolveNotes = TicketNote::where('ticket_id', $ticket->id)
            ->where('status_to', TicketStatus::Resolved->value)
            ->count();
        $this->assertSame(1, $resolveNotes);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_skips_a_ticket_a_human_has_taken_over(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Handling this manually.',
            'note_type' => NoteType::Note->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $escalations = [42 => [$this->escalationRow(820, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];
        $this->bind($ticket, 820);
        $result = $this->service([], [820 => $escalations[42][0]])->reconcile();
        $this->assertContains("#{$ticket->id}: human_touched", $result->skippedMessages);

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_skips_a_ticket_with_an_end_user_reply(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'Client Person',
            'body' => 'Any update?',
            'note_type' => NoteType::Reply->value,
            'who_type' => WhoType::EndUser->value,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        $escalations = [42 => [$this->escalationRow(830, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];
        $this->bind($ticket, 830);
        $result = $this->service([], [830 => $escalations[42][0]])->reconcile();
        $this->assertContains("#{$ticket->id}: human_touched", $result->skippedMessages);

        $this->assertStaysOpen($ticket);
    }

    public function test_gracefully_skips_when_client_has_no_org_mapping_and_no_id(): void
    {
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $stuck = $this->escalationTicket($unmapped);

        $client = $this->mappedClient(42);
        $resolvable = $this->escalationTicket($client);
        $escalations = [42 => [$this->escalationRow(840, 42, 'resolved', $resolvable->created_at, 'Endpoints Missing Key EDR Functionality')]];

        $this->bind($resolvable, 840);
        $result = $this->service([], [840 => $escalations[42][0]])->reconcile();

        $this->assertStaysOpen($stuck);
        $this->assertSame(TicketStatus::Resolved, $resolvable->fresh()->status);
        $this->assertSame(1, $result->updated);
    }

    // ── ingest fix: capture the escalation id when the payload carries an escalations URL ──

    public function test_ingest_captures_escalation_id_from_payload_url(): void
    {
        $client = Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'huntress_organization_id' => 42,
        ]);

        // The escalations URL is what we capture. The infection_reports URL is a test artifact:
        // it steers createTicketFromCw's dedup down the URL branch instead of the subject-hash
        // branch, whose whereRaw('MD5(subject)…') has no SQLite equivalent (prod is MariaDB).
        app(HuntressService::class)->createTicketFromCw([
            'summary' => 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
            'initialDescription' => 'Escalation https://dashboard.huntress.io/org/42/escalations/9182 '
                .'re: https://dashboard.huntress.io/org/42/infection_reports/9183',
            'company' => ['id' => $client->id],
        ]);

        $alert = Alert::where('source', AlertSource::Huntress->value)
            ->where('client_id', $client->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame(9182, $alert->metadata['escalation_id']);
    }

    // ── command ────────────────────────────────────────────────────────────

    public function test_command_fails_cleanly_when_huntress_is_not_configured(): void
    {
        $this->artisan('huntress:reconcile-escalations')->assertExitCode(1);
    }
}
