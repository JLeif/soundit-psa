<?php

namespace Tests\Feature\Huntress;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressReadOnlyToolset;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card tIP4JoIG part B (as ruled by Jeeves 10/01): Huntress org lookups for a PSA client
 * go through the foreign key (clients.huntress_organization_id) via client_id; the name
 * filter is a case-insensitive SUBSTRING match applied PSA-side for unmapped orgs, never
 * sent upstream (Huntress's own `name` parameter is exact-match).
 *
 * The REAL HuntressClient runs over a Guzzle MockHandler with request history, so the
 * assertions read the actual query string that would go on the wire. Envelope shapes
 * follow api.huntress.io/v1/swagger_doc.json GET /v1/organizations (required
 * `organizations` + `pagination`; Pagination.next_page_token is a string).
 */
class HuntressOrganizationLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
    }

    /** @param array<int, Response> $queue */
    private function toolset(array $queue): HuntressReadOnlyToolset
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['base_uri' => 'https://api.huntress.io/v1/', 'handler' => $stack]);
        app()->instance(HuntressClient::class, new HuntressClient(['api_key' => 'k', 'api_secret' => 's'], $http));

        return app(HuntressReadOnlyToolset::class);
    }

    /** @param array<int, array<string, mixed>> $orgs */
    private function page(array $orgs, ?string $next = null): Response
    {
        $pagination = $next === null ? new \stdClass : ['next_page_token' => $next, 'next_page_url' => 'https://api.huntress.io/v1/organizations?page_token='.$next];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'organizations' => $orgs,
            'pagination' => $pagination,
        ]));
    }

    private function org(int $id, string $name, string $key): array
    {
        return ['id' => $id, 'name' => $name, 'key' => $key, 'agents_count' => 3, 'account_id' => 5,
            'created_at' => '2025-01-01T00:00:00Z', 'updated_at' => '2025-01-02T00:00:00Z'];
    }

    /** @return array<int, array<string, string>> query params of every request sent */
    private function sentQueries(): array
    {
        return array_map(function (array $entry): array {
            parse_str($entry['request']->getUri()->getQuery(), $q);

            return $q;
        }, $this->history);
    }

    // ── name: PSA-side case-insensitive substring ─────────────────────────────

    public function test_a_partial_name_matches_and_the_name_is_not_sent_upstream(): void
    {
        $toolset = $this->toolset([$this->page([
            $this->org(1, 'Bluefin Dental Group', 'bluefin'),
            $this->org(2, 'Harbor Freight Partners', 'harbor'),
        ])]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Bluefin'], null);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['organizations'][0]['id']);
        $this->assertTrue($result['scan_complete']);
        $this->assertSame('name_substring_case_insensitive', $result['match']);
        foreach ($this->sentQueries() as $q) {
            $this->assertArrayNotHasKey('name', $q, 'name must never be sent upstream (Huntress matches it exactly)');
        }
        $this->assertSame('100', $this->sentQueries()[0]['limit']);
    }

    public function test_the_name_match_is_case_insensitive_and_matches_mid_string(): void
    {
        $toolset = $this->toolset([$this->page([
            $this->org(1, 'Q & R Builders', 'qr'),
            $this->org(2, 'Bluefin Dental Group', 'bluefin'),
        ])]);

        $upper = $toolset->execute('huntress_list_organizations', ['name' => 'q & r'], null);
        $this->assertSame(1, $upper['count']);
        $this->assertSame(1, $upper['organizations'][0]['id']);

        $toolset = $this->toolset([$this->page([$this->org(2, 'Bluefin Dental Group', 'bluefin')])]);
        $mid = $toolset->execute('huntress_list_organizations', ['name' => 'DENTAL'], null);
        $this->assertSame(1, $mid['count']);
    }

    public function test_no_match_returns_count_zero_with_an_honest_note(): void
    {
        $toolset = $this->toolset([$this->page([$this->org(1, 'Bluefin Dental Group', 'bluefin')])]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Nonexistent'], null);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(0, $result['count']);
        $this->assertTrue($result['scan_complete']);
        $this->assertStringContainsString('No Huntress organization name contains', $result['note']);
        $this->assertStringContainsString('says nothing about whether a PSA client is mapped', $result['note']);
    }

    public function test_the_name_scan_follows_pages_and_finds_a_match_on_a_later_page(): void
    {
        $toolset = $this->toolset([
            $this->page([$this->org(1, 'Harbor Freight Partners', 'harbor')], 'tok-2'),
            $this->page([$this->org(2, 'Bluefin Dental Group', 'bluefin')]),
        ]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'blu'], null);

        $this->assertSame(1, $result['count']);
        $this->assertSame(2, $result['organizations_scanned']);
        $this->assertCount(2, $this->history);
        $this->assertSame('tok-2', $this->sentQueries()[1]['page_token']);
    }

    public function test_paging_stops_at_the_cap_and_refuses_to_assert_absence(): void
    {
        $queue = [];
        for ($i = 1; $i <= 25; $i++) {
            $queue[] = $this->page([$this->org($i, "Org {$i}", "k{$i}")], "tok-{$i}");
        }
        $toolset = $this->toolset($queue);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Bluefin'], null);

        $this->assertCount(20, $this->history, 'the scan is capped at 20 pages');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('absence cannot be asserted', $result['error']);
    }

    public function test_a_match_found_before_the_cap_is_returned_but_flagged_incomplete(): void
    {
        $queue = [$this->page([$this->org(1, 'Bluefin Dental Group', 'bluefin')], 'tok-1')];
        for ($i = 2; $i <= 25; $i++) {
            $queue[] = $this->page([$this->org($i, "Org {$i}", "k{$i}")], "tok-{$i}");
        }
        $toolset = $this->toolset($queue);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'bluefin'], null);

        $this->assertCount(20, $this->history);
        $this->assertSame(1, $result['count']);
        $this->assertFalse($result['scan_complete']);
    }

    public function test_an_unrecognised_envelope_fails_closed_not_as_no_match(): void
    {
        $toolset = $this->toolset([new Response(200, [], json_encode(['data' => [$this->org(1, 'Bluefin', 'a')]]))]);
        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Bluefin'], null);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('unrecognised shape', $result['error']);

        $toolset = $this->toolset([new Response(200, [], json_encode(['organizations' => [$this->org(1, 'X', 'x')], 'pagination' => ['next_page_token' => 7]]))]);
        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Bluefin'], null);
        $this->assertArrayHasKey('error', $result, 'a non-string cursor is drift, not the last page');

        $toolset = $this->toolset([new Response(200, [], json_encode(['organizations' => [$this->org(1, 'X', 'x')]]))]);
        $result = $toolset->execute('huntress_list_organizations', ['name' => 'Bluefin'], null);
        $this->assertArrayHasKey('error', $result, 'pagination is required by the producer schema');
    }

    public function test_a_repeated_cursor_fails_closed(): void
    {
        $toolset = $this->toolset([
            $this->page([$this->org(1, 'Org 1', 'k1')], 'same'),
            $this->page([$this->org(2, 'Org 2', 'k2')], 'same'),
        ]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'bluefin'], null);

        $this->assertArrayHasKey('error', $result);
        $this->assertCount(2, $this->history);
    }

    public function test_matched_names_still_pass_through_the_sanitizer(): void
    {
        $toolset = $this->toolset([$this->page([$this->org(1, 'Bluefin Dental Group', 'bluefin')])]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'bluefin'], null);

        $this->assertNotSame('Bluefin Dental Group', $result['organizations'][0]['name'], 'org names are fenced by the sanitizer');
        $this->assertStringContainsString('Bluefin Dental Group', $result['organizations'][0]['name']);
    }

    // ── key: unchanged upstream behaviour ─────────────────────────────────────

    public function test_the_key_filter_is_still_sent_upstream(): void
    {
        $toolset = $this->toolset([$this->page([$this->org(1, 'Bluefin Dental Group', 'bluefin')])]);

        $result = $toolset->execute('huntress_list_organizations', ['key' => 'bluefin'], null);

        $this->assertSame(1, $result['count']);
        $this->assertSame('bluefin', $this->sentQueries()[0]['key']);
        $this->assertArrayNotHasKey('name', $this->sentQueries()[0]);
    }

    // ── client_id: resolve through the mapping ────────────────────────────────

    public function test_client_id_resolves_the_org_through_the_psa_mapping(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => 42]);
        $toolset = $this->toolset([
            new Response(200, [], json_encode(['organization' => $this->org(42, 'Gamma Holdings', 'gamma')])),
            new Response(200, [], json_encode(['organization' => $this->org(42, 'Gamma Holdings', 'gamma')])),
        ]);

        $list = $toolset->execute('huntress_list_organizations', [], $client->id);
        $this->assertSame(1, $list['count']);
        $this->assertSame(42, $list['organizations'][0]['id']);
        $this->assertSame($client->id, $list['organizations'][0]['psa_client_id']);
        $this->assertSame('psa_client_mapping', $list['match']);

        $get = $toolset->execute('huntress_get_organization', [], $client->id);
        $this->assertSame(42, $get['id']);

        $this->assertSame('/v1/organizations/42', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/v1/organizations/42', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_an_unmapped_client_is_an_error_and_makes_no_call(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => null]);
        $toolset = $this->toolset([]);

        foreach (['huntress_list_organizations', 'huntress_get_organization', 'huntress_list_incident_reports', 'huntress_list_escalations'] as $tool) {
            $result = $toolset->execute($tool, [], $client->id);
            $this->assertArrayHasKey('error', $result, $tool);
            $this->assertStringContainsString('not mapped to a Huntress organization', $result['error']);
        }
        $this->assertCount(0, $this->history);
    }

    public function test_client_id_scopes_incident_and_escalation_lists_to_the_mapped_org(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => 42]);
        $toolset = $this->toolset([
            new Response(200, [], json_encode(['incident_reports' => [], 'pagination' => new \stdClass])),
            new Response(200, [], json_encode(['escalations' => [], 'pagination' => new \stdClass])),
        ]);

        $toolset->execute('huntress_list_incident_reports', [], $client->id);
        $toolset->execute('huntress_list_escalations', [], $client->id);

        $this->assertSame('42', $this->sentQueries()[0]['organization_id']);
        $this->assertSame('42', $this->sentQueries()[1]['organization_id']);
    }

    public function test_client_id_conflicting_with_organization_id_is_refused(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => 42]);
        Client::factory()->create(['name' => 'Delta Co', 'huntress_organization_id' => 43]);
        $toolset = $this->toolset([]);

        foreach (['huntress_get_organization', 'huntress_list_incident_reports', 'huntress_list_escalations'] as $tool) {
            $result = $toolset->execute($tool, ['organization_id' => 43], $client->id);
            $this->assertArrayHasKey('error', $result, $tool);
        }
        $this->assertCount(0, $this->history);
    }

    public function test_client_id_with_a_name_filter_is_refused(): void
    {
        $client = Client::factory()->create(['name' => 'Gamma LLC', 'huntress_organization_id' => 42]);
        $toolset = $this->toolset([]);

        $result = $toolset->execute('huntress_list_organizations', ['name' => 'blu'], $client->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertCount(0, $this->history);
    }

    public function test_the_tool_description_states_the_substring_rule_and_what_zero_means(): void
    {
        $def = collect(HuntressReadOnlyToolset::definitions())->firstWhere('name', 'huntress_list_organizations');

        $this->assertStringContainsString('case-insensitive substring', $def['description']);
        $this->assertStringContainsString('does NOT mean a client is unmapped', $def['description']);
        $this->assertArrayHasKey('client_id', $def['input_schema']['properties']);
    }
}
