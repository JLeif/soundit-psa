<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\Actions\RebootAction;
use App\Services\Tactical\Actions\RunCommandAction;
use App\Services\Tactical\TacticalActionConfirmToken;
use App\Services\Tactical\TacticalActionService;
use App\Services\Tactical\TacticalClient;
use App\Services\Technician\Scheduled\TacticalScheduledAction;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3971 at the bus: the real RunCommandAction through TacticalActionService,
 * with the transport failing the way cURL fails. The two handler contexts are
 * the fields TacticalClientCmdTimeoutTest reads from real cURL on loopback
 * (errno 28 both times; request_size > 0 only when the request was written).
 */
class TacticalCmdOutcomeUnknownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-test-key');
    }

    private function asset(): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'WORKSTATION-01']);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'WORKSTATION-01',
            'status' => 'online',
        ]);

        return $asset->refresh();
    }

    /** @param array<string, mixed> $context */
    private function curlTimeout(array $context): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'agents/AGENT-1/cmd/'),
            null,
            $context,
        );
    }

    private function dispatchCmdFailingWith(\Throwable $e): \App\Services\Tactical\Actions\TacticalActionResult
    {
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => HandlerStack::create(new MockHandler([$e]))]);
        $bus = new TacticalActionService(new TacticalClient($http));
        $action = new RunCommandAction;
        $actor = User::factory()->create();
        $asset = $this->asset();
        $params = ['shell' => 'powershell', 'cmd' => 'Long-Job', 'timeout' => 25];
        $token = TacticalActionConfirmToken::issue($action->key(), 'AGENT-1', $actor->id, $action->payloadHash($action->validateParams($params)));

        return $bus->dispatch($action, $asset, $actor, $params, $token);
    }

    public function test_a_timeout_after_the_command_was_sent_is_outcome_unknown_and_says_it_may_have_run(): void
    {
        $result = $this->dispatchCmdFailingWith($this->curlTimeout(['errno' => 28, 'request_size' => 149]));

        $this->assertSame('outcome_unknown', $result->status);
        $this->assertFalse($result->isOffline());
        $this->assertStringContainsString('may have run', (string) $result->message);

        $row = TacticalActionLog::sole();
        $this->assertSame('outcome_unknown', $row->result_status, 'the audit column accepts the new status');
        $this->assertStringContainsString('may have run', (string) $row->message);
    }

    public function test_a_timeout_before_anything_was_sent_stays_offline(): void
    {
        $result = $this->dispatchCmdFailingWith($this->curlTimeout(['errno' => 28, 'request_size' => 0]));

        $this->assertSame('offline', $result->status);
        $this->assertSame('offline', TacticalActionLog::sole()->result_status);
    }

    public function test_a_refused_connection_stays_offline(): void
    {
        $result = $this->dispatchCmdFailingWith(new ConnectException(
            'cURL error 7: Failed to connect',
            new Request('POST', 'agents/AGENT-1/cmd/'),
            null,
            ['errno' => 7, 'request_size' => 0],
        ));

        $this->assertSame('offline', $result->status);
    }

    public function test_a_failure_with_no_handler_context_stays_offline(): void
    {
        // A context without the cURL keys proves nothing about whether the
        // request was sent, so it is not promoted to outcome_unknown.
        $result = $this->dispatchCmdFailingWith(new ConnectException(
            'Connection timed out',
            new Request('POST', 'agents/AGENT-1/cmd/'),
        ));

        $this->assertSame('offline', $result->status);
    }

    public function test_a_non_command_action_that_times_out_after_send_keeps_its_offline_classification(): void
    {
        // #3971 is scoped to run_command; other verbs are classified as before.
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => HandlerStack::create(new MockHandler([$this->curlTimeout(['errno' => 28, 'request_size' => 149])]))]);
        $bus = new TacticalActionService(new TacticalClient($http));
        $action = new RebootAction;
        $actor = User::factory()->create();

        $result = $bus->dispatch($action, $this->asset(), $actor, [], TacticalActionConfirmToken::issue($action->key(), 'AGENT-1', $actor->id));

        $this->assertSame('offline', $result->status);
    }

    public function test_after_an_outcome_unknown_the_same_command_is_held_whatever_its_timeout_or_actor(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            $this->curlTimeout(['errno' => 28, 'request_size' => 149]),
            new Response(200, [], (string) json_encode('done')),
        ]));
        $stack->push(Middleware::history($history));
        $bus = new TacticalActionService(new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack])));
        $asset = $this->asset();
        $dispatch = function (string $cmd, int $timeout) use ($bus, $asset): \App\Services\Tactical\Actions\TacticalActionResult {
            $action = new RunCommandAction;
            $actor = User::factory()->create();
            $params = ['shell' => 'powershell', 'cmd' => $cmd, 'timeout' => $timeout];
            $token = TacticalActionConfirmToken::issue($action->key(), 'AGENT-1', $actor->id, $action->payloadHash($action->validateParams($params)));

            return $bus->dispatch($action, $asset, $actor, $params, $token);
        };

        $this->assertSame('outcome_unknown', $dispatch('Long-Job', 25)->status);

        // Another timeout and another actor: still the same command on the same device.
        $held = $dispatch('Long-Job', 30);
        $this->assertSame('blocked', $held->status);
        $this->assertStringContainsString('may have run', (string) $held->message);
        $this->assertStringContainsString('It was not sent again', (string) $held->message);
        $this->assertCount(1, $history, 'the held command never reached the transport');
        $this->assertSame(1, TacticalActionLog::where('result_status', 'blocked')->count());

        // Only the matching command is held: a different one still goes out.
        $this->assertSame('ok', $dispatch('Other-Job', 25)->status);
        $this->assertCount(2, $history);
    }

    public function test_the_scheduled_lane_is_held_after_an_outcome_unknown_from_another_lane(): void
    {
        // The scheduled lane sends run_command as a TacticalScheduledAction, not a
        // RunCommandAction; the hold matches the action key, so it is held too.
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            $this->curlTimeout(['errno' => 28, 'request_size' => 149]),
            new Response(200, [], (string) json_encode('done')),
        ]));
        $stack->push(Middleware::history($history));
        $bus = new TacticalActionService(new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack])));
        $asset = $this->asset();
        $params = ['cmd' => 'Long-Job', 'shell' => 'powershell', 'timeout' => 25];

        $direct = new RunCommandAction;
        $actor = User::factory()->create();
        $first = $bus->dispatch($direct, $asset, $actor, $params, TacticalActionConfirmToken::issue($direct->key(), 'AGENT-1', $actor->id, $direct->payloadHash($direct->validateParams($params))));
        $this->assertSame('outcome_unknown', $first->status);

        $scheduled = new TacticalScheduledAction('tactical_stage_command');
        $token = TacticalActionConfirmToken::issue($scheduled->key(), 'AGENT-1', null, $scheduled->payloadHash($scheduled->validateParams($params)));
        $held = $bus->dispatch($scheduled, $asset, null, $params, $token, 'scheduled:1');

        $this->assertSame('blocked', $held->status);
        $this->assertStringContainsString('It was not sent again', (string) $held->message);
        $this->assertCount(1, $history, 'the scheduled send never reached the transport');
    }

    public function test_the_asset_and_ticket_page_lanes_are_held_after_an_outcome_unknown(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            $this->curlTimeout(['errno' => 28, 'request_size' => 149]),
            new Response(200, [], (string) json_encode('done')),
        ]));
        $stack->push(Middleware::history($history));
        $this->app->instance(TacticalClient::class, new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack])));
        $user = User::factory()->create();
        $asset = $this->asset();
        $ticket = Ticket::factory()->create();
        $ticket->assets()->attach($asset->id);
        $body = ['hostname' => 'WORKSTATION-01', 'shell' => 'powershell', 'cmd' => 'Long-Job', 'timeout' => 25];

        $first = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), $body);
        $this->assertStringContainsString('may have run', (string) $first->json('error'));

        $fromAsset = $this->actingAs($user)->postJson(route('assets.run-tactical-command', $asset), $body);
        $this->assertStringContainsString('It was not sent again', (string) $fromAsset->json('error'));

        $fromTicket = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), ['asset_id' => $asset->id] + $body);
        $this->assertStringContainsString('It was not sent again', (string) $fromTicket->json('error'));

        $this->assertCount(1, $history, 'neither page retry reached the transport');
    }

    public function test_only_a_timeout_the_old_maximum_accepted_is_told_the_maximum_was_lowered(): void
    {
        $messageFor = function (int $timeout): string {
            try {
                (new RunCommandAction)->validateParams(['shell' => 'powershell', 'cmd' => 'Long-Job', 'timeout' => $timeout]);
            } catch (\App\Services\Tactical\Actions\InvalidActionParams $e) {
                return $e->getMessage();
            }

            $this->fail("timeout {$timeout} should be rejected.");
        };

        $this->assertStringContainsString('lowered from 600 to 30 seconds', $messageFor(31));
        $this->assertStringContainsString('lowered from 600 to 30 seconds', $messageFor(600));
        $this->assertStringNotContainsString('lowered', $messageFor(601), 'the old maximum never accepted it either');
        $this->assertStringNotContainsString('lowered', $messageFor(5));
    }
}
