<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\Tactical\Actions\RunCommandAction;
use App\Services\Tactical\TacticalActionConfirmToken;
use App\Services\Tactical\TacticalActionService;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
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
        $params = ['shell' => 'powershell', 'cmd' => 'Long-Job', 'timeout' => 120];
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
}
