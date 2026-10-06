<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * MeshWriteClient::listCustomerRules() — the page walk must be COMPLETE.
 *
 * Shape (measured on the production Partner Hub 2026-10-06, read-only): the
 * rule list answers `{count, next, previous, results}` and caps a page at 100
 * rows whatever `_size` asks for, with `next` pointing at `_from=<rows so far>`.
 * The defect this file pins: the walk used to stop on "fewer rows than the
 * 200 we asked for", i.e. always after page 1, so every rule past row 100 was
 * invisible to the create re-read, the reaper, edit/remove and the list tool.
 *
 * Every fixture is synthetic; tenant ids, senders and comments are invented.
 */
class MeshWriteClientPagingTest extends TestCase
{
    private const NEXT = 'https://hub-us.example.test/api/rule-allows-blocks/?_from=%d&_size=100';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /** @param array<int, Response> $queue */
    private function client(array $queue): MeshWriteClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new MeshWriteClient(['api_key' => 'k'], new GuzzleClient([
            'base_uri' => 'https://hub-us.example.test/',
            'handler' => $stack,
            'http_errors' => true,
        ]));
    }

    /** @return array<int, array<string, int|string>> the _from/_size of every request made, in order */
    private function requestedPages(): array
    {
        return array_map(static function (array $entry): array {
            parse_str($entry['request']->getUri()->getQuery(), $q);

            return ['_from' => (int) $q['_from'], '_size' => (int) $q['_size']];
        }, $this->history);
    }

    private static function rule(string $id, string $tenant, string $sender, string $comment): array
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

    /** @return array<int, array<string, mixed>> $n rows belonging to some other tenant */
    private static function others(int $n, string $prefix): array
    {
        return array_map(
            static fn (int $i): array => self::rule("{$prefix}-{$i}", 'tenant-OTHER', "o{$i}@other.example", 'not ours'),
            range(1, $n),
        );
    }

    private static function page(array $results, ?int $count, ?string $next): Response
    {
        $body = ['next' => $next, 'previous' => null, 'results' => $results];
        if ($count !== null) {
            $body['count'] = $count;
        }

        return new Response(200, [], json_encode($body));
    }

    /**
     * 100 + 100 + 30 rows, count 230, with `next` links; the target tenant's
     * only rule is the last row of page 3.
     *
     * @return array<int, Response>
     */
    private static function threePages(): array
    {
        $page3 = self::others(29, 'p3');
        $page3[] = self::rule('target-rule', 'tenant-T', 'friend@sender.example', 'PSA allow TARGET0001');

        return [
            self::page(self::others(100, 'p1'), 230, sprintf(self::NEXT, 100)),
            self::page(self::others(100, 'p2'), 230, sprintf(self::NEXT, 200)),
            self::page($page3, 230, null),
        ];
    }

    private function assertWalkedThreeFullPages(): void
    {
        $this->assertSame([
            ['_from' => 0, '_size' => 100],
            ['_from' => 100, '_size' => 100],
            ['_from' => 200, '_size' => 100],
        ], $this->requestedPages());
    }

    public function test_the_requested_page_size_is_the_measured_vendor_cap(): void
    {
        $this->assertSame(100, MeshWriteClient::LIST_PAGE_SIZE);
    }

    public function test_list_finds_a_rule_on_page_three(): void
    {
        $rules = $this->client(self::threePages())->listCustomerRules('tenant-T');

        $this->assertSame(['target-rule'], array_column($rules, 'id'));
        $this->assertWalkedThreeFullPages();
    }

    public function test_find_by_comment_finds_a_rule_on_page_three(): void
    {
        $match = $this->client(self::threePages())
            ->findRuleByComment('tenant-T', 'friend@sender.example', 'PSA allow TARGET0001');

        $this->assertSame('target-rule', $match['id'] ?? null);
        $this->assertWalkedThreeFullPages();
    }

    public function test_find_by_id_finds_a_rule_on_page_three(): void
    {
        $match = $this->client(self::threePages())->findRuleById('tenant-T', 'target-rule');

        $this->assertSame('target-rule', $match['id'] ?? null);
        $this->assertWalkedThreeFullPages();
    }

    /**
     * A short page is not the last page when the vendor says there is more:
     * the walk continues, and `_from` advances by the rows actually returned
     * (60), not by the size requested (100) — otherwise rows 60..99 are skipped.
     */
    public function test_a_short_non_final_page_does_not_end_the_walk_and_from_advances_by_rows_returned(): void
    {
        $page2 = self::others(9, 'p2');
        $page2[] = self::rule('late-rule', 'tenant-T', 'late@sender.example', 'PSA allow LATE000001');

        $rules = $this->client([
            self::page(self::others(60, 'p1'), 70, sprintf(self::NEXT, 60)),
            self::page($page2, 70, null),
        ])->listCustomerRules('tenant-T');

        $this->assertSame(['late-rule'], array_column($rules, 'id'));
        $this->assertSame([
            ['_from' => 0, '_size' => 100],
            ['_from' => 60, '_size' => 100],
        ], $this->requestedPages());
    }

    /** `next` alone carries the walk when the vendor omits `count`. */
    public function test_next_alone_continues_the_walk_when_count_is_absent(): void
    {
        $page2 = [self::rule('next-rule', 'tenant-T', 'n@sender.example', 'PSA allow NEXT000001')];

        $rules = $this->client([
            self::page(self::others(100, 'p1'), null, sprintf(self::NEXT, 100)),
            self::page($page2, null, null),
        ])->listCustomerRules('tenant-T');

        $this->assertSame(['next-rule'], array_column($rules, 'id'));
        $this->assertCount(2, $this->history);
    }

    /** `count` alone carries the walk when a non-final page omits `next`. */
    public function test_count_alone_continues_the_walk_when_next_is_missing(): void
    {
        $page2 = [self::rule('count-rule', 'tenant-T', 'c@sender.example', 'PSA allow COUNT00001')];

        $rules = $this->client([
            self::page(self::others(100, 'p1'), 101, null),
            self::page($page2, 101, null),
        ])->listCustomerRules('tenant-T');

        $this->assertSame(['count-rule'], array_column($rules, 'id'));
        $this->assertCount(2, $this->history);
    }

    public function test_a_single_short_final_page_makes_one_request(): void
    {
        $rows = self::others(4, 'p1');
        $rows[] = self::rule('only-rule', 'tenant-T', 'o@sender.example', 'PSA allow ONLY000001');

        $rules = $this->client([self::page($rows, 5, null)])->listCustomerRules('tenant-T');

        $this->assertSame(['only-rule'], array_column($rules, 'id'));
        $this->assertCount(1, $this->history);
    }

    /**
     * The vendor still says "more" when the ceiling is reached: the call
     * throws, and the target rule already seen on page 1 is NOT handed back as
     * if the list were complete.
     */
    public function test_hitting_the_page_ceiling_before_the_list_ends_throws_and_returns_nothing(): void
    {
        $queue = [];
        for ($i = 0; $i < MeshWriteClient::LIST_PAGE_CEILING; $i++) {
            $rows = self::others(100, "p{$i}");
            if ($i === 0) {
                $rows[0] = self::rule('seen-early', 'tenant-T', 'e@sender.example', 'PSA allow EARLY00001');
            }
            $queue[] = self::page($rows, 99999, sprintf(self::NEXT, ($i + 1) * 100));
        }
        $client = $this->client($queue);

        $returned = null;
        try {
            $returned = $client->listCustomerRules('tenant-T');
            $this->fail('a walk stopped by the ceiling must throw, not return a partial list');
        } catch (MeshClientException $e) {
            $this->assertStringContainsString('page ceiling', $e->getMessage());
            $this->assertStringNotContainsString('other.example', $e->getMessage(), 'no vendor rows in the message');
        }

        $this->assertNull($returned);
        $this->assertCount(MeshWriteClient::LIST_PAGE_CEILING, $this->history);
    }

    /** Boundary: a list that ends exactly on the last allowed page is complete. */
    public function test_a_list_that_ends_exactly_at_the_ceiling_is_complete(): void
    {
        $total = MeshWriteClient::LIST_PAGE_CEILING * 100;
        $queue = [];
        for ($i = 0; $i < MeshWriteClient::LIST_PAGE_CEILING; $i++) {
            $last = $i === MeshWriteClient::LIST_PAGE_CEILING - 1;
            $rows = self::others(100, "p{$i}");
            if ($last) {
                $rows[99] = self::rule('last-row', 'tenant-T', 'l@sender.example', 'PSA allow LAST000001');
            }
            $queue[] = self::page($rows, $total, $last ? null : sprintf(self::NEXT, ($i + 1) * 100));
        }

        $rules = $this->client($queue)->listCustomerRules('tenant-T');

        $this->assertSame(['last-row'], array_column($rules, 'id'));
    }

    /**
     * `next` runs out and the vendor answers an empty page while its own
     * `count` says rows are missing: that is an incomplete read and throws.
     */
    public function test_fewer_rows_than_count_when_the_list_runs_out_throws(): void
    {
        $page1 = self::others(99, 'p1');
        $page1[] = self::rule('seen-early', 'tenant-T', 'e@sender.example', 'PSA allow EARLY00001');
        $client = $this->client([
            self::page($page1, 230, sprintf(self::NEXT, 100)),
            self::page(self::others(100, 'p2'), 230, null),
            self::page([], 230, null),
        ]);

        $returned = null;
        try {
            $returned = $client->listCustomerRules('tenant-T');
            $this->fail('a walk that saw fewer rows than count must throw');
        } catch (MeshClientException $e) {
            $this->assertStringContainsString('200 rows but Mesh reported 230', $e->getMessage());
        }

        $this->assertNull($returned);
        $this->assertCount(3, $this->history);
    }

    /** The same incomplete read reaches findRuleByComment as a throw, not a null "not found". */
    public function test_find_by_comment_propagates_an_incomplete_read_rather_than_answering_not_found(): void
    {
        $client = $this->client([
            self::page(self::others(100, 'p1'), 230, null),
            self::page([], 230, null),
        ]);

        $this->expectException(MeshClientException::class);
        $client->findRuleByComment('tenant-T', 'friend@sender.example', 'PSA allow TARGET0001');
    }
}
