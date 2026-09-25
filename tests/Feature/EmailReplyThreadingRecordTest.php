<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pins the operator record on EmailService::sendReply's no-graph_id fallback arm.
 *
 * The clause this replaces ("reply will not thread") predicted the behaviour of the
 * recipient's mail client, which no arm of this method can observe: a client that
 * threads on subject threads it anyway. The record now names the route taken and the
 * headers omitted, both of which ARE observable here, so these controls assert the
 * route and the omission rather than an outcome at the other end.
 *
 * Every level is captured, not only warning, so demoting the record cannot pass:
 * Log::log() is the level-first generic entry (Logger.php:155) and is captured too.
 */
class EmailReplyThreadingRecordTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{level:string,message:string,context:array}> */
    private array $records = [];

    /** @var list<array{path:string,payload:array}> */
    private array $posts = [];

    private function captureAllLevels(): void
    {
        $this->records = [];
        Log::listen(function ($message) {
            $this->records[] = [
                'level' => $message->level,
                'message' => $message->message,
                'context' => $message->context,
            ];
        });
    }

    private function bindGraphSpy(): void
    {
        $this->posts = [];
        $spy = new class($this->posts) extends GraphClient
        {
            public function __construct(public array &$sink) {}

            public function post(string $path, array $payload = [], array $opts = []): array
            {
                $this->sink[] = ['path' => $path, 'payload' => $payload];

                return ['id' => 'graph-sent'];
            }
        };
        $this->app->instance(GraphClient::class, $spy);
    }

    private function inbound(?string $graphId): Email
    {
        return Email::create([
            'graph_id' => $graphId,
            'direction' => 'inbound',
            'from_address' => 'someone@example.test',
            'from_name' => 'Someone',
            'subject' => 'Printer is down',
            'body_preview' => 'preview',
            'body_text' => 'preview',
            'body_html' => '<p>preview</p>',
            'conversation_id' => 'conv-abc',
            'internet_message_id' => '<orig@example.test>',
            'has_attachments' => false,
            'importance' => 'normal',
            'received_at' => now(),
            'is_read' => false,
        ]);
    }

    private function reply(Email $original): Email
    {
        Setting::updateOrCreate(['key' => 'graph_mailbox'], ['value' => 'help@example.test']);

        return $this->app->make(EmailService::class)->sendReply($original, 'we are on it');
    }

    private function fallbackRecord(): array
    {
        $matches = array_values(array_filter(
            $this->records,
            fn (array $r) => str_contains($r['message'], '[EmailService] Replying to email without graph_id')
        ));

        $this->assertCount(1, $matches, 'expected exactly one no-graph_id reply record');

        // A record an operator is meant to act on must not be demoted below warning.
        // Every level reaches this listener (including the level-first generic entry
        // Log::log($level, $message, $context)), so asserting the level here is what
        // makes a demotion fail rather than pass silently.
        $this->assertSame(
            'warning',
            $matches[0]['level'],
            'the no-graph_id reply record must stay at warning: a demoted record is not read'
        );

        return $matches[0];
    }

    public function test_the_fallback_record_names_the_route_and_omitted_headers_and_predicts_nothing(): void
    {
        $this->bindGraphSpy();
        $this->captureAllLevels();

        $this->reply($this->inbound(null));

        $record = $this->fallbackRecord();

        // The route and the omission are observable here, so they are what is asserted.
        $this->assertSame('sendMail', $record['context']['send_path']);
        $this->assertFalse($record['context']['threading_headers']);

        // And the payload actually sent agrees with what the record claims about it.
        $this->assertCount(1, $this->posts);
        $this->assertSame('users/help@example.test/sendMail', $this->posts[0]['path']);
        $this->assertArrayNotHasKey('internetMessageHeaders', $this->posts[0]['payload']['message']);

        // No clause may predict the recipient's mail client: nothing here can observe it.
        $this->assertStringNotContainsString('will not thread', $record['message']);
    }

    public function test_the_record_survives_a_send_that_throws_because_it_states_the_route_not_a_delivery(): void
    {
        $this->app->instance(GraphClient::class, new class extends GraphClient
        {
            public function __construct() {}

            public function post(string $path, array $payload = [], array $opts = []): array
            {
                throw new GraphClientException('simulated transport failure');
            }
        });
        $this->captureAllLevels();

        $outboundBefore = Email::where('direction', 'outbound')->count();

        try {
            $this->reply($this->inbound(null));
            $this->fail('expected the failing send to throw');
        } catch (GraphClientException) {
            // expected
        }

        // The record is emitted before the send, so it must not claim a completed delivery.
        $record = $this->fallbackRecord();
        $this->assertSame('sendMail', $record['context']['send_path']);
        $this->assertStringNotContainsString('sent via', $record['message']);

        // And the send genuinely did not land: no outbound row was stored.
        $this->assertSame($outboundBefore, Email::where('direction', 'outbound')->count());
    }

    public function test_psa_side_grouping_is_untouched_by_the_fallback_route(): void
    {
        $this->bindGraphSpy();
        $this->captureAllLevels();

        $stored = $this->reply($this->inbound(null));

        // EmailService groups its own threads on conversation_id first; the fallback
        // route preserves it, which is why the omitted headers affect only the far end.
        $this->assertSame('conv-abc', $stored->conversation_id);
        $this->assertSame('<orig@example.test>', $stored->in_reply_to);
    }

    public function test_the_native_reply_route_emits_no_such_record(): void
    {
        $this->bindGraphSpy();
        $this->captureAllLevels();

        $this->reply($this->inbound('graph-123'));

        $this->assertSame('users/help@example.test/messages/graph-123/reply', $this->posts[0]['path']);
        $this->assertSame(
            [],
            array_values(array_filter(
                $this->records,
                fn (array $r) => str_contains($r['message'], 'without graph_id')
            )),
            'the native reply route must not emit the fallback record'
        );
    }
}
