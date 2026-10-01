<?php

namespace Tests\Feature\Chet;

use App\Models\McpAuditLog;
use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Services\Chet\TeamsChatReadToolset;
use App\Services\Graph\GraphClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * teams_search_channel (card 5sALzgSC): read-only search over the history
 * get_teams_chat_history reads. Graph rows mirror the chatMessage shape the
 * existing DataSurfaceToolsTest fixtures use (id, createdDateTime, from.user,
 * body.contentType/content). All text is synthetic.
 */
class TeamsSearchChannelToolTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '19:operator-chat@thread.v2';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('teams_bot_enabled', '0');
        Setting::setValue('teams_chet_routing_enabled', '1');
        Setting::setValue('teams_bot_app_id', 'bot-app-id');
        Setting::setValue('teams_bot_tenant_id', 'tenant-1');
        Setting::setValue('teams_chet_conversation_id', self::CHAT);
    }

    private ?string $token = null;

    private function search(array $args, array $tools = ['teams_search_channel']): TestResponse
    {
        $token = $this->token ??= McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'teams_search_channel', 'arguments' => $args],
        ]);
    }

    private function decoded(TestResponse $r): array
    {
        $r->assertOk();
        $this->assertFalse((bool) $r->json('result.isError'), (string) $r->json('result.content.0.text'));

        return json_decode((string) $r->json('result.content.0.text'), true) ?? [];
    }

    private function message(int $i, string $html): array
    {
        return [
            'id' => "m{$i}",
            'createdDateTime' => sprintf('2026-09-30T%02d:00:00Z', $i % 24),
            'messageType' => 'message',
            'from' => ['user' => ['id' => 'u1', 'displayName' => 'Synthetic Tech']],
            'body' => ['contentType' => 'html', 'content' => $html],
        ];
    }

    public function test_case_insensitive_match_includes_bot_posts_and_returns_newest_first(): void
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')->once()
            ->with('chats/'.self::CHAT.'/messages', ['$top' => 50, '$orderby' => 'createdDateTime desc'], TeamsChatReadToolset::SEARCH_MAX_PAGES)
            ->andReturn([
                $this->message(3, '<p>Printer <b>QUEUE</b> cleared</p>'),
                $this->message(2, '<p>lunch?</p>'),
                $this->message(1, '<p>the printer queue is stuck</p>'),
            ]);
        $this->app->instance(GraphClient::class, $graph);

        $out = $this->decoded($this->search(['chat_or_channel' => 'operator', 'query' => 'printer queue']));

        $this->assertSame(self::CHAT, $out['chat_id']);
        $this->assertSame(3, $out['scanned']);
        $this->assertTrue($out['history_exhausted']);
        $this->assertSame(['m3', 'm1'], array_column($out['messages'], 'id'));
    }

    public function test_limit_is_capped_and_the_page_walk_is_bounded(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = $this->message($i, "<p>needle {$i}</p>");
        }
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')->once()
            ->withArgs(fn ($e, $p, int $maxPages): bool => $maxPages === TeamsChatReadToolset::SEARCH_MAX_PAGES && $p['$top'] === 50)
            ->andReturn($rows);
        $this->app->instance(GraphClient::class, $graph);

        $out = $this->decoded($this->search(['chat_or_channel' => self::CHAT, 'query' => 'needle', 'limit' => 999]));

        $this->assertSame(25, $out['count']);
        $this->assertCount(25, $out['messages']);
        $this->assertSame(250, $out['scanned']);
        $this->assertFalse($out['history_exhausted']);
    }

    public function test_search_has_no_side_effects(): void
    {
        OperatorInbox::query()->insert([
            'conversation_id' => self::CHAT, 'text' => 'synthetic inbound', 'ts' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')->once()->andReturn([$this->message(1, 'needle')]);
        $graph->shouldNotReceive('post', 'patch', 'delete');
        $this->app->instance(GraphClient::class, $graph);

        $this->token = McpConfig::rotateStaffToken(allowedTools: ['teams_search_channel'], label: 'chet');
        $before = OperatorInbox::query()->orderBy('id')->get()->toArray();
        $writes = [];
        // The boundary's own bookkeeping (audit row, token last_used_at stamp) is
        // not the tool's; every OTHER write is a side effect and fails the test.
        DB::listen(function ($q) use (&$writes) {
            $boundary = str_contains($q->sql, 'mcp_audit_logs')
                || preg_match('/^update "mcp_tokens" set "last_used_at" = \?, "updated_at" = \? where/', $q->sql) === 1;
            if (preg_match('/^\s*(insert|update|delete)/i', $q->sql) === 1 && ! $boundary) {
                $writes[] = $q->sql;
            }
        });

        $this->decoded($this->search(['chat_or_channel' => 'operator', 'query' => 'needle']));

        $this->assertSame([], $writes, 'search must not write anything but its own audit row');
        $this->assertSame($before, OperatorInbox::query()->orderBy('id')->get()->toArray());
        $this->assertNull(OperatorInbox::query()->first()->delivered_at);
        $this->assertSame(1, McpAuditLog::query()->where('tool_name', 'teams_search_channel')->count());
    }

    public function test_unknown_chat_is_denied_without_a_graph_call(): void
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldNotReceive('getAllPages');
        $this->app->instance(GraphClient::class, $graph);

        $r = $this->search(['chat_or_channel' => '19:not-known@thread.v2', 'query' => 'needle']);
        $r->assertOk();
        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertStringContainsString('not a known Teams conversation', (string) $r->json('result.content.0.text'));
    }

    public function test_matches_redacted_text_only_so_a_secret_is_not_findable(): void
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getAllPages')->once()->andReturn([$this->message(1, '<p>password: synthetic-fixture-value</p>')]);
        $this->app->instance(GraphClient::class, $graph);

        $out = $this->decoded($this->search(['chat_or_channel' => 'operator', 'query' => 'synthetic-fixture-value']));

        $this->assertSame(0, $out['count']);
    }
}
