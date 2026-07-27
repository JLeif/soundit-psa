<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * search_tools (psa-cplo3.1) — Chet's READ verb over his own tool surface.
 *
 * A query-filtered projection of the SAME per-caller classification
 * list_tool_surface returns ({@see McpToolSurface::classify()}): the agent
 * asks "do I have anything for unifi?" and gets back matching catalog tools
 * with their grant state, instead of blind-filing a request_tool gap it can
 * never read back. A transport built-in like whoami / list_tool_surface:
 * always listed, always callable, no explicit grant required.
 *
 * Disclosure parity is the security posture (review adjudicates whether to
 * tighten): search_tools may reveal exactly what list_tool_surface already
 * reveals to the same caller — names, categories, one-line descriptions,
 * states — and nothing more. Matching runs only over those disclosed fields,
 * so a query cannot probe text the surface listing does not itself show.
 */
class McpSearchToolsTest extends TestCase
{
    use RefreshDatabase;

    private const UNIFI_GRANTED_TOOLS = [
        'unifi_get_site_health',
        'unifi_list_devices',
        'unifi_get_isp_metrics',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function search(string $token, array $arguments = []): array
    {
        $response = $this->callTool($token, 'search_tools', $arguments);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @return array<string, string> tool name => grant_state */
    private function grantStatesByName(array $payload): array
    {
        return collect($payload['matches'])->pluck('grant_state', 'name')->all();
    }

    private function configureUnifi(): void
    {
        Setting::setValue('unifi_enabled', '1');
        Setting::setEncrypted('unifi_api_key', 'k');
    }

    public function test_search_tools_is_listed_and_callable_without_an_explicit_grant(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $names = collect(
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
                ->json('result.tools'),
        )->pluck('name');

        $this->assertContains('search_tools', $names);

        $payload = $this->search($token, ['query' => 'ticket']);

        $this->assertArrayHasKey('matches', $payload);
        $this->assertArrayHasKey('match_count', $payload);
        $this->assertArrayHasKey('states', $payload);
        $this->assertNotEmpty($payload['matches']);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'search_tools',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    /**
     * THE acceptance test (Jeeves, psa-cplo3 Part 1): a caller granted the
     * UniFi reads searches "unifi" and gets them back marked granted — the
     * answer to "do I already have this capability?" that request_tool's
     * write-only ledger could never give.
     */
    public function test_unifi_query_returns_the_granted_unifi_reads_marked_granted(): void
    {
        $this->configureUnifi();
        $token = McpConfig::rotateStaffToken(allowedTools: self::UNIFI_GRANTED_TOOLS, label: 'chet');

        $states = $this->grantStatesByName($this->search($token, ['query' => 'unifi']));

        foreach (self::UNIFI_GRANTED_TOOLS as $name) {
            $this->assertSame(McpToolSurface::STATE_GRANTED, $states[$name] ?? null, $name);
        }

        // A live sibling the token was NOT granted shows as an enablement ask,
        // never as callable and never hidden.
        $this->assertSame(McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states['unifi_list_sites'] ?? null);
    }

    public function test_config_off_matches_show_unavailable_config(): void
    {
        // UniFi is granted but never configured: the match must say the remedy
        // is configuration, not pretend the capability is callable or absent.
        $token = McpConfig::rotateStaffToken(allowedTools: self::UNIFI_GRANTED_TOOLS, label: 'chet');

        $states = $this->grantStatesByName($this->search($token, ['query' => 'unifi']));

        $this->assertNotEmpty($states);
        foreach ($states as $name => $state) {
            $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $state, $name);
        }
    }

    public function test_empty_query_and_no_match_return_empty_results_not_errors(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        // Empty query: an empty result with guidance — not an error, and not
        // a dump of the full catalog.
        $payload = $this->search($token, ['query' => '   ']);
        $this->assertSame([], $payload['matches']);
        $this->assertSame(0, $payload['match_count']);
        $this->assertArrayHasKey('note', $payload);

        // Missing query argument entirely behaves the same way.
        $payload = $this->search($token);
        $this->assertSame([], $payload['matches']);
        $this->assertSame(0, $payload['match_count']);

        // No match: empty, with copy that treats the miss as a KEYWORD miss,
        // never proof the capability is absent — matching is literal substring,
        // so "wifi controller" cannot find "unifi". The honest recovery path
        // (psa-cplo3.1 R1): try another keyword and/or list_tool_surface
        // (the full catalog) BEFORE request_tool, reserving no-such-tool
        // framing for confirmed full-surface absence. Still never a bare []
        // a caller could misread as a clean all-clear.
        $payload = $this->search($token, ['query' => 'no_such_capability_zzz']);
        $this->assertSame([], $payload['matches']);
        $this->assertSame(0, $payload['match_count']);
        $note = (string) ($payload['note'] ?? '');
        $this->assertStringContainsString('keyword', $note);
        $this->assertStringContainsString('list_tool_surface', $note);
        $this->assertStringContainsString('request_tool', $note);
        // Reads-first ordering: the keyword-retry / full-surface advice comes
        // before the request_tool build-request remedy.
        $this->assertLessThan(strpos($note, 'request_tool'), strpos($note, 'list_tool_surface'));
        // A keyword miss is no longer presented as proof of nonexistence.
        $this->assertStringNotContainsString('does not exist', $note);
    }

    public function test_matching_is_case_insensitive_and_covers_descriptions_and_categories(): void
    {
        $this->configureUnifi();
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        // Case-insensitive name match.
        $states = $this->grantStatesByName($this->search($token, ['query' => 'UNIFI']));
        $this->assertArrayHasKey('unifi_list_devices', $states);

        // Description-only match: "technicians" appears in find_staff's
        // one-line description but in no part of its name.
        $states = $this->grantStatesByName($this->search($token, ['query' => 'technicians']));
        $this->assertArrayHasKey('find_staff', $states);

        // Category match: find_staff sits in the bridge category; its name and
        // description never mention the word.
        $states = $this->grantStatesByName($this->search($token, ['query' => 'bridge']));
        $this->assertArrayHasKey('find_staff', $states);
    }

    /**
     * Disclosure parity, pinned by construction: every search match must be
     * byte-identical (name, category, state, one-line description) to the
     * same caller's list_tool_surface entry, carry no extra keys (no schemas,
     * no config), and stay within the one-line description bounds.
     */
    public function test_matches_carry_exactly_the_list_tool_surface_disclosure(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $surfaceResponse = $this->callTool($token, 'list_tool_surface');
        $surface = collect(json_decode((string) $surfaceResponse->json('result.content.0.text'), true)['tools'])
            ->keyBy('name');

        $payload = $this->search($token, ['query' => 'ticket']);
        $this->assertNotEmpty($payload['matches']);
        $this->assertSame(count($payload['matches']), $payload['match_count']);

        foreach ($payload['matches'] as $match) {
            $this->assertSame(['name', 'category', 'grant_state', 'description'], array_keys($match));

            $entry = $surface->get($match['name']);
            $this->assertIsArray($entry, $match['name']);
            $this->assertSame($entry['category'], $match['category'], $match['name']);
            $this->assertSame($entry['state'], $match['grant_state'], $match['name']);
            $this->assertSame($entry['description'], $match['description'], $match['name']);
            $this->assertLessThanOrEqual(200, mb_strlen($match['description']), $match['name']);
            $this->assertStringNotContainsString("\n", $match['description'], $match['name']);
        }
    }
}
