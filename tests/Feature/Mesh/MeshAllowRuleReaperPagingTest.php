<?php

namespace Tests\Feature\Mesh;

use App\Models\MeshAllowRule;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The reaper's resolveRuleId() over the real MeshWriteClient page walk.
 *
 * An unresolved row whose rule sits on page 2 of the partner-wide list (the
 * vendor caps a page at 100 rows, measured 2026-10-06) must become resolved.
 * Before the paging fix the walk stopped after page 1, so these rows stayed
 * unresolved forever. And an incomplete list read must leave the row
 * unresolved or reap_failed, never reaped and never removed.
 *
 * A routing handler plays the vendor: the list is served by `_from`, DELETE
 * answers 200, and the detail read answers 404 once the rule is deleted.
 * Synthetic data only.
 */
class MeshAllowRuleReaperPagingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'tenant-synthetic-1';

    /** @var array<int, array<string, mixed>> */
    private array $upstream = [];

    private ?int $reportedCount = null;

    /** @var array<int, string> */
    private array $deleted = [];

    private function bindClient(): void
    {
        $handler = function (RequestInterface $request) {
            $path = ltrim($request->getUri()->getPath(), '/');

            if ($request->getMethod() === 'GET' && $path === 'api/rule-allows-blocks/') {
                parse_str($request->getUri()->getQuery(), $q);
                $from = (int) ($q['_from'] ?? 0);
                $size = min((int) ($q['_size'] ?? 100), 100);
                $all = array_values($this->upstream);
                $slice = array_slice($all, $from, $size);
                $end = $from + count($slice);
                $count = $this->reportedCount ?? count($all);

                return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], json_encode([
                    'count' => $count,
                    'next' => $end < count($all) ? "https://mesh.invalid/api/rule-allows-blocks/?_from={$end}&_size=100" : null,
                    'previous' => null,
                    'results' => $slice,
                ])));
            }

            $id = trim(substr($path, strlen('api/rule-allows-blocks/')), '/');
            if ($request->getMethod() === 'DELETE') {
                $this->deleted[] = $id;
                unset($this->upstream[$id]);

                return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], '{}'));
            }

            return new \GuzzleHttp\Promise\FulfilledPromise(isset($this->upstream[$id])
                ? new Response(200, [], json_encode($this->upstream[$id]))
                : new Response(404, [], '{"detail":"Not found."}'));
        };

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://mesh.invalid/',
            'handler' => HandlerStack::create($handler),
            'http_errors' => true,
        ]);

        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    private function seedUpstream(string $targetId, string $sender, string $comment): void
    {
        $this->upstream = [];
        for ($i = 1; $i <= 130; $i++) {
            $this->upstream["other-{$i}"] = $this->row("other-{$i}", 'tenant-OTHER', "o{$i}@other.example", 'not ours');
        }
        // Row 121 of 130: page 2 at the vendor's 100-row cap.
        $keys = array_keys($this->upstream);
        $rebuilt = [];
        foreach ($keys as $n => $key) {
            if ($n === 120) {
                $rebuilt[$targetId] = $this->row($targetId, self::TENANT, $sender, $comment);
            }
            $rebuilt[$key] = $this->upstream[$key];
        }
        $this->upstream = $rebuilt;
    }

    private function row(string $id, string $tenant, string $sender, string $comment): array
    {
        return [
            'id' => $id,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => true,
            'active' => true,
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => $tenant, 'name' => 'Tenant'],
        ];
    }

    private function unresolvedRow(string $sender, string $comment, mixed $expiresAt): MeshAllowRule
    {
        return MeshAllowRule::create([
            'mesh_customer_id' => self::TENANT,
            'sender' => $sender,
            'comment' => $comment,
            'mesh_rule_id' => null,
            'expires_at' => $expiresAt,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
            'last_error' => 'Allow rule created, but its Mesh rule id could not be recovered by re-read.',
        ]);
    }

    public function test_an_unresolved_permanent_row_whose_rule_is_on_page_two_is_resolved_and_settled(): void
    {
        $this->seedUpstream('rule-page-two', 'perm@sender.example', 'PSA allow PERM000001');
        $this->bindClient();
        $record = $this->unresolvedRow('perm@sender.example', 'PSA allow PERM000001', null);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame('rule-page-two', $record->mesh_rule_id);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertNull($record->last_error);
        $this->assertSame(0, $counts['unresolved']);
        $this->assertSame([], $this->deleted, 'a permanent rule is identified, never deleted');
    }

    public function test_an_expired_unresolved_row_whose_rule_is_on_page_two_is_resolved_and_reaped(): void
    {
        $this->seedUpstream('rule-page-two', 'temp@sender.example', 'PSA allow TEMP000001');
        $this->bindClient();
        $record = $this->unresolvedRow('temp@sender.example', 'PSA allow TEMP000001', now()->subDay());

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame('rule-page-two', $record->mesh_rule_id);
        $this->assertSame(['rule-page-two'], $this->deleted);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->state);
        $this->assertSame(1, $counts['reaped']);
    }

    /**
     * Vendor `count` larger than the rows it actually serves: the list read
     * throws, and neither caller arm treats that as "the rule is gone".
     */
    public function test_an_incomplete_list_read_leaves_rows_unsettled_and_deletes_nothing(): void
    {
        $this->seedUpstream('rule-page-two', 'x@sender.example', 'PSA allow XXXX000001');
        // Drop the target from what is served, and claim more rows than exist.
        unset($this->upstream['rule-page-two']);
        $this->reportedCount = 500;
        $this->bindClient();

        $permanent = $this->unresolvedRow('x@sender.example', 'PSA allow XXXX000001', null);
        $expired = $this->unresolvedRow('x@sender.example', 'PSA allow XXXX000001', now()->subDay());

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame([], $this->deleted);
        $this->assertSame(0, $counts['reaped']);

        $permanent->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $permanent->state);
        $this->assertNull($permanent->mesh_rule_id);

        $expired->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $expired->state);
        $this->assertNull($expired->mesh_rule_id);
        $this->assertNull($expired->reaped_at);
        $this->assertStringContainsString("Could not read the tenant's rule list", (string) $expired->last_error);
    }
}
