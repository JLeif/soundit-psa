<?php

namespace Tests\Feature\Ai;

use App\Models\Setting;
use App\Services\Ai\AiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AiClientArgumentShapeTest extends TestCase
{
    use RefreshDatabase;

    // The real parser uses assoc=true: stdClass cannot arrive over JSON. This
    // opt-in decoder fault exercises the defensive loop contract, not the wire.
    public static bool $injectObject = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/ArgumentShapeDecoder.php';
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'synthetic-key');
        Log::spy();
    }

    protected function tearDown(): void
    {
        self::$injectObject = false;
        parent::tearDown();
    }

    public static function malformedInputs(): array
    {
        return ['string' => ['PRIVATE-VALUE', 'string'], 'integer' => [5, 'int'], 'object fault' => [new \stdClass, 'stdClass']];
    }

    #[DataProvider('malformedInputs')]
    public function test_non_array_input_is_refused_and_second_round_runs(mixed $input, string $type): void
    {
        foreach ([false, true] as $chat) {
            self::$injectObject = $input instanceof \stdClass;
            [$calls, $progress, $results, $text] = $this->loop(['reason' => ['type' => 'string']], ['input' => $input], $chat);
            $this->assertSame([], $calls);
            $this->assertSame([], $progress);
            $this->assertSame('done', $text);
            $this->assertSame('tool_result', $results[0]['type']);
            $this->assertSame('shape_call', $results[0]['tool_use_id']);
            $this->assertStringContainsString('arguments must be a JSON object', $results[0]['content']);
            $this->assertStringContainsString('REFUSED', $results[0]['content']);
            $this->assertStringNotContainsString('PRIVATE-VALUE', $results[0]['content']);
        }
        Log::shouldHaveReceived('warning')->with('[AiClient] Refused non-object tool arguments', ['tool' => 'shape', 'type' => $type])->twice();
        Log::shouldNotHaveReceived('warning', ['[AiClient] Tool execution failed', \Mockery::any()]);
    }

    public function test_absent_and_null_input_still_execute_as_empty_arrays(): void
    {
        foreach ([[], ['input' => null]] as $input) {
            [$calls, , $results, $text] = $this->loop([], $input);
            $this->assertSame([[]], $calls);
            $this->assertSame('done', $text);
            $this->assertSame(['ok' => true], json_decode($results[0]['content'], true));
        }
        Log::shouldNotHaveReceived('warning');
    }

    public function test_nonempty_list_properties_fail_open(): void
    {
        [$calls, $progress, $results] = $this->loop(['reason'], ['input' => ['reason' => 'fixture']]);
        $this->assertSame([['reason' => 'fixture']], $calls);
        $this->assertSame(['shape'], $progress);
        $this->assertSame(['ok' => true], json_decode($results[0]['content'], true));
        Log::shouldNotHaveReceived('warning');
    }

    public static function emptyMaps(): array
    {
        return ['bare array' => [[]], 'empty object' => [(object) []]];
    }

    #[DataProvider('emptyMaps')]
    public function test_empty_properties_remain_closed(mixed $properties): void
    {
        [$calls, $progress, $results] = $this->loop($properties, ['input' => ['reason' => 'fixture']]);
        $this->assertSame([], $calls);
        $this->assertSame([], $progress);
        $this->assertStringContainsString('accepts no arguments', $results[0]['content']);
    }

    public function test_declared_only_call_still_executes(): void
    {
        [$calls, $progress, $results] = $this->loop(['reason' => ['type' => 'string']], ['input' => ['reason' => 'fixture']]);
        $this->assertSame([['reason' => 'fixture']], $calls);
        $this->assertSame(['shape'], $progress);
        $this->assertSame(['ok' => true], json_decode($results[0]['content'], true));
    }

    public function test_unpublished_name_refusal_precedes_shape_refusal(): void
    {
        [$calls, $progress, $results] = $this->loop([], ['input' => 'PRIVATE-VALUE'], true, 'unpublished');
        $this->assertSame([], $calls);
        $this->assertSame([], $progress);
        $this->assertStringContainsString('not available in this deployment', $results[0]['content']);
        Log::shouldNotHaveReceived('warning', ['[AiClient] Refused non-object tool arguments', \Mockery::any()]);
    }

    private function loop(mixed $properties, array $input, bool $chat = true, string $name = 'shape'): array
    {
        $history = $calls = $progress = [];
        $block = array_merge(['type' => 'tool_use', 'id' => 'shape_call', 'name' => $name], $input);
        $response = fn ($content) => new Response(200, [], json_encode(['content' => $content]));
        $stack = HandlerStack::create(new MockHandler([$response([$block]), $response([['type' => 'text', 'text' => 'done']])]));
        $stack->push(Middleware::history($history));
        $ai = new AiClient(http: new Client(['handler' => $stack]));
        $tools = [['name' => 'shape', 'input_schema' => ['type' => 'object', 'properties' => $properties]]];
        $executor = function ($name, $input) use (&$calls) {
            $calls[] = $input;

            return ['ok' => true];
        };
        if ($chat) {
            $result = $ai->runChatWithTools('system', [['role' => 'user', 'content' => 'fixture']], $tools, $executor, onToolCall: function ($name) use (&$progress) {
                $progress[] = $name;
            });
        } else {
            $result = $ai->runToolLoop('system', 'fixture', $tools, $executor);
        }
        $this->assertCount(2, $history, 'The loop must send tool_result in a second model round.');
        $body = json_decode((string) $history[1]['request']->getBody(), true);

        return [$calls, $progress, $body['messages'][2]['content'], $result->text];
    }
}
