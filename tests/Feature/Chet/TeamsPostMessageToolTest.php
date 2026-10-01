<?php

namespace Tests\Feature\Chet;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * teams_post_message (card 5sALzgSC) at the REAL production config:
 * teams_bot_enabled=0, zero personas, teams_chet_routing_enabled=1.
 * Bot Framework is faked at the HTTP layer, so assertions read the activity
 * exactly as it would leave the box. All message text is synthetic.
 */
class TeamsPostMessageToolTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_URL = 'https://smba.trafficmanager.net/amer/';

    private string $token;

    private User $operator;

    /** @var array<int, array<string, mixed>> */
    private array $activities = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('teams_bot_enabled', '0');
        Setting::setValue('teams_chet_routing_enabled', '1');
        Setting::setValue('teams_bot_app_id', 'bot-app-id');
        Setting::setValue('teams_bot_tenant_id', 'tenant-1');
        Setting::setEncrypted('teams_bot_client_secret', 'synthetic-secret');
        Setting::setValue('teams_chet_conversation_id', '19:operator-chat@thread.v2');
        Setting::setValue('teams_escalation_conversation_id', '19:day-to-day@thread.v2');
        Setting::setValue('teams_escalation_service_url', self::SERVICE_URL);

        $this->operator = User::factory()->create(['name' => 'Op Erator', 'email' => 'operator@example.test', 'microsoft_id' => 'oid-operator']);
        Setting::setValue('technician_escalation_judgment_user', (string) $this->operator->id);

        $this->token = McpConfig::rotateStaffToken(allowedTools: ['teams_post_message', 'post_to_operator'], label: 'chet');

        $this->activities = [];
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'bf-token', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/members/')) {
                return Http::response(['id' => '29:member-operator', 'name' => 'Op Erator']);
            }
            if (str_ends_with($request->url(), '/activities')) {
                $this->activities[] = ['url' => $request->url(), 'body' => $request->data()];

                return Http::response(['id' => '1727740000000'], 201);
            }

            return Http::response([], 404);
        });

        Mail::fake();
        // OperatorDelivery's email leg is EmailService::sendNew (Graph sendMail), not
        // Laravel Mail, so both are pinned: neither may be reached.
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->never());
        $this->mock(TeamsNotifier::class, fn (MockInterface $m) => $m->shouldReceive('post')->never());
    }

    private function postTool(array $args, ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token)])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'teams_post_message', 'arguments' => $args],
            ]);
    }

    private function decoded(TestResponse $r): array
    {
        $r->assertOk();

        return json_decode((string) $r->json('result.content.0.text'), true) ?? [];
    }

    private function onlyActivity(): array
    {
        $this->assertCount(1, $this->activities, 'exactly one Teams activity');

        return $this->activities[0]['body'];
    }

    public function test_markdown_list_link_and_blank_line_reach_the_teams_payload_verbatim(): void
    {
        $body = "Done with the synthetic printer check.\n\n- **step one** (ok)\n- step_two `code`\n\nDetails: [the runbook](https://example.test/runbook)";

        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => $body]));

        $this->assertTrue($out['posted']);
        $this->assertSame('1727740000000', $out['remote_message_id']);
        $activity = $this->onlyActivity();
        $this->assertSame($body, $activity['text']);
        $this->assertStringContainsString("\n\n- **step one** (ok)\n", $activity['text']);
        $this->assertStringContainsString('[the runbook](https://example.test/runbook)', $activity['text']);
        $this->assertSame('markdown', $activity['textFormat']);
        $this->assertStringContainsString(rawurlencode('19:operator-chat@thread.v2'), $this->activities[0]['url']);
    }

    public function test_no_email_is_dispatched_and_no_category_prefix_is_added(): void
    {
        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => 'Plain synthetic update.']));

        $this->assertTrue($out['posted']);
        $this->assertSame('Plain synthetic update.', $this->onlyActivity()['text']);
        foreach (['Escalation', 'Steer request', 'Daily report', 'Reply'] as $label) {
            $this->assertStringNotContainsString($label, $this->onlyActivity()['text']);
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_mention_is_off_by_default(): void
    {
        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => 'No ping please.']));

        $this->assertFalse($out['mentioned']);
        $activity = $this->onlyActivity();
        $this->assertArrayNotHasKey('entities', $activity);
        $this->assertStringNotContainsString('<at>', $activity['text']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/members/'));
    }

    public function test_mention_true_mentions_the_operator_with_a_server_built_entity(): void
    {
        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => 'Need eyes.', 'mention' => true]));

        $this->assertTrue($out['mentioned']);
        $activity = $this->onlyActivity();
        $this->assertSame('<at>Op Erator</at> Need eyes.', $activity['text']);
        $this->assertSame([[
            'type' => 'mention',
            'mentioned' => ['id' => '29:member-operator', 'name' => 'Op Erator'],
            'text' => '<at>Op Erator</at>',
        ]], $activity['entities']);
    }

    public function test_secret_scan_still_withholds_the_whole_body(): void
    {
        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => "Status:\n\npassword: synthetic-fixture-value"]));

        $this->assertTrue($out['text_withheld']);
        $this->assertContains('credential', $out['scan_classes']);
        $text = $this->onlyActivity()['text'];
        $this->assertStringNotContainsString('synthetic-fixture-value', $text);
        $this->assertSame('[message detail withheld - see the cockpit]', $text);
    }

    public function test_unknown_target_is_refused_and_nothing_is_sent(): void
    {
        foreach (['19:some-other-chat@thread.v2', 'general', '', 'operator-chat'] as $target) {
            $r = $this->postTool(['chat_or_channel' => $target, 'body' => 'hello']);
            $r->assertOk();
            $this->assertTrue((bool) $r->json('result.isError'), "target '{$target}' must be refused");
        }

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/activities'));
        $this->assertSame([], $this->activities);
    }

    public function test_allowed_targets_by_key_and_by_exact_conversation_id(): void
    {
        $this->decoded($this->postTool(['chat_or_channel' => 'escalation', 'body' => 'one']));
        $this->decoded($this->postTool(['chat_or_channel' => '19:operator-chat@thread.v2', 'body' => 'two']));

        $this->assertCount(2, $this->activities);
        $this->assertStringContainsString(rawurlencode('19:day-to-day@thread.v2'), $this->activities[0]['url']);
        $this->assertStringContainsString(rawurlencode('19:operator-chat@thread.v2'), $this->activities[1]['url']);
    }

    public function test_body_cannot_forge_a_mention_html_image_or_script_link(): void
    {
        $body = '<at>Op Erator</at> hi <img src=x> ![px](https://example.test/p.png) [x](javascript:alert(1)) [ok](https://example.test/) a < b';

        $out = $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => $body]));

        $activity = $this->onlyActivity();
        $this->assertArrayNotHasKey('entities', $activity);
        $this->assertArrayNotHasKey('attachments', $activity);
        $this->assertArrayNotHasKey('channelData', $activity);
        $this->assertSame(['type', 'text', 'textFormat'], array_keys($activity));
        $text = $activity['text'];
        $this->assertStringNotContainsString('<at>', $text);
        $this->assertStringNotContainsString('</at>', $text);
        $this->assertStringNotContainsString('<img', $text);
        $this->assertStringNotContainsString('![', $text);
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringContainsString('[ok](https://example.test/)', $text);
        $this->assertStringContainsString('a < b', $text);
        $this->assertSame(['html_tags' => 3, 'images' => 1, 'links' => 1], $out['markdown_neutralized']);
    }

    public function test_ticket_fields_are_escaped_but_the_body_is_not(): void
    {
        $client = Client::factory()->create(['name' => 'Acme [x](https://evil.test)']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => '*Printer* <b>down</b>']);

        $this->decoded($this->postTool(['chat_or_channel' => 'operator', 'body' => '**Fixed** (see notes)', 'ticket_id' => $ticket->id]));

        $text = $this->onlyActivity()['text'];
        $this->assertStringStartsWith('**Fixed** (see notes)', $text);
        $this->assertStringEndsWith("\n\nTicket #{$ticket->id} (Acme x https://evil.test - Printer b down /b)", $text);
    }

    public function test_tool_is_not_callable_without_an_explicit_grant(): void
    {
        $legacy = McpConfig::rotateStaffToken();
        $r = $this->postTool(['chat_or_channel' => 'operator', 'body' => 'hi'], $legacy);
        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertSame([], $this->activities);
    }

    public function test_post_to_operator_still_emails_and_prefixes(): void
    {
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendNew')->once()->andReturnNull());

        $r = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'post_to_operator', 'arguments' => ['category' => 'escalation', 'message' => 'Urgent synthetic thing']],
        ]);
        $r->assertOk();

        $this->assertStringContainsString('Escalation: Urgent synthetic thing', $this->onlyActivity()['text']);
    }
}
