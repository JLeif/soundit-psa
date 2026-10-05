<?php

namespace Tests\Feature\Email;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Email;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AttachmentService;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Forward-as-attachment (card gHRatMtd). Outlook's "Forward as attachment" reaches the
 * mailbox as a Graph #microsoft.graph.itemAttachment with no contentBytes; before this
 * change downloadEmailAttachments() skipped it silently, so a forwarded phishing sample
 * never reached the ticket.
 *
 * Fixtures follow the vendor's own examples in attachment-get (Microsoft Graph v1.0):
 * Example 1 (fileAttachment), Example 2 (itemAttachment: contentType null, no contentBytes),
 * Example 5 (referenceAttachment: name/size/contentType, no link), and Example 9 (the
 * $value of a message item is MIME text). Hosts are example.test, IPs RFC 5737.
 *
 * GraphClient is Guzzle, so the wire is a MockHandler (the GraphClientPathSafetyTest idiom);
 * Http::preventStrayRequests() additionally refuses any Laravel HTTP client call.
 */
class EmailItemAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private const MIME = "From: Payroll Team <payroll@phish.example.test>\r\n"
        ."Return-Path: <bounce@phish.example.test>\r\n"
        ."Received: from mail.phish.example.test (192.0.2.10) by mx.example.test\r\n"
        ."Authentication-Results: mx.example.test; spf=fail smtp.mailfrom=phish.example.test\r\n"
        ."To: User <user@example.test>\r\n"
        ."Subject: Urgent: verify your payroll account\r\n"
        ."Content-Type: text/plain; charset=\"us-ascii\"\r\n"
        ."MIME-Version: 1.0\r\n\r\n"
        ."Click http://198.51.100.7/login to keep your pay.\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', self::MAILBOX);
    }

    /** A GraphClient whose wire is $responses in order, with a pre-seeded token. */
    private function graph(array $responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);

        $graph = new GraphClient([
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache);
        $this->app->instance(GraphClient::class, $graph);

        return $graph;
    }

    /** The message read ($expand=attachments) carrying $attachments. */
    private function expandResponse(array $attachments): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'MSG-1',
            'attachments' => $attachments,
        ]));
    }

    private function itemAttachment(array $overrides = []): array
    {
        return $overrides + [
            '@odata.type' => '#microsoft.graph.itemAttachment',
            'id' => 'ATT-ITEM-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Urgent: verify your payroll account',
            'contentType' => null,
            'size' => 32005,
            'isInline' => false,
        ];
    }

    private function fileAttachment(): array
    {
        return [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'id' => 'ATT-FILE-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Invoice Template.docx',
            'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 13068,
            'isInline' => false,
            'contentId' => null,
            'contentLocation' => null,
            'contentBytes' => base64_encode('docx-bytes'),
        ];
    }

    private function referenceAttachment(): array
    {
        return [
            '@odata.type' => '#microsoft.graph.referenceAttachment',
            'id' => 'ATT-REF-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Sales Invoice Template.docx',
            'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 1060,
            'isInline' => true,
        ];
    }

    private function email(array $overrides = []): Email
    {
        return Email::create($overrides + [
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'FW: suspicious',
            'body_text' => 'Is this legit?',
            'received_at' => now(),
        ]);
    }

    private function ticket(): Ticket
    {
        $client = Client::create(['name' => 'Example Client']);

        return Ticket::create([
            'client_id' => $client->id,
            'subject' => 'Suspicious email',
            'type' => TicketType::Incident,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
        ]);
    }

    private function valueRequests(): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/$value'),
        ));
    }

    /**
     * The not-stored warning fired exactly once at WARNING with $reason and ids only, and no
     * other level (or a generic log()/write()) carried the same message. Needs Log::spy().
     */
    private function assertNotStoredWarning(string $reason, string $attachmentId): void
    {
        $msg = '[AttachmentService] Email attachment content not stored';

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($msg, $reason, $attachmentId) {
            return $message === $msg
                && ($context['reason'] ?? null) === $reason
                && ($context['attachment_id'] ?? null) === $attachmentId
                && ! array_intersect(array_keys($context), ['name', 'subject', 'content', 'body']);
        })->once();

        foreach (['emergency', 'alert', 'critical', 'error', 'notice', 'info', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level, [$msg, \Mockery::any()]);
        }
        Log::shouldNotHaveReceived('log', [\Mockery::any(), $msg, \Mockery::any()]);
        Log::shouldNotHaveReceived('write', [\Mockery::any(), $msg, \Mockery::any()]);
    }

    public function test_item_attachment_is_stored_as_eml_through_value(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $email = $this->email();

        $stored = app(AttachmentService::class)->downloadEmailAttachments($email, $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertSame('urgent-verify-your-payroll-account.eml', $a->filename);
        $this->assertSame(strlen(self::MIME), $a->size_bytes);
        $this->assertFalse($a->is_inline);
        $this->assertSame(self::MIME, Storage::disk('local')->get($a->storage_path));

        $value = $this->valueRequests();
        $this->assertCount(1, $value);
        $this->assertSame(
            '/v1.0/users/support%40example.test/messages/MSG-1/attachments/ATT-ITEM-1/$value',
            $value[0]['request']->getUri()->getPath(),
        );
        $this->assertSame('Bearer test-token', $value[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_item_without_a_usable_name_is_named_forwarded_message(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['name' => '!!!'])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame('forwarded-message.eml', $stored[0]->filename);
    }

    public function test_item_on_linked_ticket_lands_on_the_note(): void
    {
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $ticket = $this->ticket();
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('email_id', $email->id)->firstOrFail();
        $a = Attachment::where('attachable_type', TicketNote::class)->where('attachable_id', $note->id)->sole();
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertStringEndsWith('.eml', $a->filename);
    }

    /** @return array<string, array{0: Response}> */
    public static function failingValueResponses(): array
    {
        return [
            '404' => [new Response(404, [], json_encode(['error' => ['code' => 'ErrorItemNotFound']]))],
            '500' => [new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError']]))],
        ];
    }

    #[DataProvider('failingValueResponses')]
    public function test_value_fetch_failure_warns_stores_nothing_and_email_still_ingests(Response $failure): void
    {
        Log::spy();
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            $failure,
        ]);
        $ticket = $this->ticket();
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertNotNull(TicketNote::where('email_id', $email->id)->first());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNotStoredWarning('fetch_failed', 'ATT-ITEM-1');
    }

    public function test_item_declared_over_ceiling_is_refused_before_any_fetch(): void
    {
        Log::spy();
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $this->assertSame([], $this->valueRequests());
        $this->assertSame(0, Attachment::count());
        $this->assertNotStoredWarning('over_size_ceiling', 'ATT-ITEM-1');
    }

    public function test_item_whose_returned_bytes_exceed_ceiling_is_refused(): void
    {
        Log::spy();
        // Graph's declared size is under the ceiling; the bytes it returns are not.
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => 1000])]),
            new Response(200, [], str_repeat('a', AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1)),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNotStoredWarning('over_size_ceiling', 'ATT-ITEM-1');
    }

    public function test_item_exactly_at_ceiling_is_stored(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES])]),
            new Response(200, [], str_repeat('a', AttachmentService::MAX_ITEM_ATTACHMENT_BYTES)),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
    }

    public function test_reference_attachment_records_a_named_placeholder_and_is_not_fetched(): void
    {
        Log::spy();
        $graph = $this->graph([$this->expandResponse([$this->referenceAttachment()])]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('text/plain', $a->mime_type);
        $this->assertSame('sales-invoice-templatedocx-linked-file.txt', $a->filename);
        $this->assertSame(
            "Linked cloud attachment (not downloaded): Sales Invoice Template.docx\n",
            Storage::disk('local')->get($a->storage_path),
        );
        $this->assertSame([], $this->valueRequests());
        $this->assertNotStoredWarning('reference_not_downloaded', 'ATT-REF-1');
    }

    public function test_file_attachment_behaves_as_before_and_does_not_warn(): void
    {
        Log::spy();
        $graph = $this->graph([$this->expandResponse([$this->fileAttachment()])]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('invoice-template.docx', $a->filename);
        $this->assertSame('Invoice Template.docx', $a->original_filename);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $a->mime_type);
        $this->assertSame('docx-bytes', Storage::disk('local')->get($a->storage_path));
        $this->assertSame([], $this->valueRequests());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_mixed_message_keeps_every_kind(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->fileAttachment(), $this->itemAttachment(), $this->referenceAttachment()]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame(
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'message/rfc822', 'text/plain'],
            array_map(fn ($a) => $a->mime_type, $stored),
        );
    }

    public function test_redelivery_of_a_ticketed_email_does_not_store_the_item_twice(): void
    {
        // Both responses are queued for one $expand + one $value. A second download would
        // need a third and fourth response; the MockHandler throws on an empty queue.
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $ticket = $this->ticket();
        $email = $this->email(['internet_message_id' => '<m1@example.test>']);
        app(EmailService::class)->linkEmailToTicket($email, $ticket);
        $this->assertSame(1, Attachment::count());

        // Redelivery: the webhook path re-imports the same internet_message_id.
        $again = app(EmailService::class)->importSingleMessage([
            'id' => 'MSG-1',
            'internetMessageId' => '<m1@example.test>',
            'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
            'subject' => 'FW: suspicious',
            'body' => ['content' => '<p>Is this legit?</p>'],
            'hasAttachments' => true,
            'receivedDateTime' => now()->toIso8601String(),
        ]);
        // And the poll path's retry branch sees an already-ticketed row.
        app(EmailService::class)->processInbound($email->fresh());

        $this->assertSame($email->id, $again->id);
        $this->assertSame(1, Email::count());
        $this->assertSame(1, Attachment::count());
        $this->assertCount(1, $this->valueRequests());
    }
}
