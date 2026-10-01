<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\ClientUnifiSite;
use App\Models\Setting;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Unifi\UnifiClient;
use App\Services\Unifi\UnifiReadOnlyToolset;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Card 6abdcac2, sixth integration: UniFi telemetry reads take client_id and resolve
 * strictly within that client. PINNING ONLY: the audit at 7ce53a04 measured every
 * client tool as already client-strict, but every existing test mapped a single
 * client, so a site-health or ISP read that dropped its client scope shipped green,
 * and the ISP window/type arguments were never checked on the vendor request.
 *
 * Surface: the staff MCP only (McpStaffController -> ChetDataSurfaceToolExecutor ->
 * UnifiReadOnlyToolset). The Assistant, triage and portal publish no unifi tool.
 * unifi_list_sites is account-wide METADATA by design and is pinned here as such.
 *
 * Reads call the vendor live, so every test binds a real UnifiClient over a Guzzle
 * MockHandler and records each request: an empty queue plus a zero history count is
 * the proof that a refusal spent no request.
 *
 * Synthetic data only: "Alpha Synthetic"/"Bravo Synthetic", placeholder hex site ids
 * and console ids, device names such as Test-MBP-AP.
 */
class UnifiClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const A_SITE = 'aaaa00000000000000000a01';

    private const A_HOST = 'AAAA0000000000000000000000000000000000000000000000000000:1';

    private const B_SITE = 'bbbb00000000000000000b01';

    private const B_HOST = 'BBBB0000000000000000000000000000000000000000000000000000:2';

    private const SHARED_HOST = 'CCCC0000000000000000000000000000000000000000000000000000:3';

    private const FOREIGN_SITE = 'ffff00000000000000000f01';

    private const CLIENT_TOOLS = ['unifi_get_site_health', 'unifi_list_devices', 'unifi_get_isp_metrics'];

    private const READS = ['unifi_list_sites', 'unifi_get_site_health', 'unifi_list_devices', 'unifi_get_isp_metrics'];

    private Client $alpha;

    private Client $bravo;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('unifi_api_key', 'k');
        Setting::setValue('unifi_enabled', '1');

        $this->alpha = Client::factory()->create(['name' => 'Alpha Synthetic']);
        $this->bravo = Client::factory()->create(['name' => 'Bravo Synthetic']);

        ClientUnifiSite::create(['client_id' => $this->alpha->id, 'unifi_site_id' => self::A_SITE, 'unifi_host_id' => self::A_HOST]);
        ClientUnifiSite::create(['client_id' => $this->bravo->id, 'unifi_site_id' => self::B_SITE, 'unifi_host_id' => self::B_HOST]);
    }

    /** @param array<int, array<string, mixed>> $payloads */
    private function vendorReturns(array $payloads): void
    {
        $queue = array_map(
            fn (array $p) => new Response(200, ['Content-Type' => 'application/json'], json_encode($p)),
            $payloads,
        );
        $stack = HandlerStack::create(new MockHandler($queue));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $this->app->instance(UnifiClient::class, new UnifiClient(
            ['api_key' => 'k'],
            new GuzzleClient(['base_uri' => 'https://api.ui.com/', 'handler' => $stack]),
        ));
    }

    private function requestCount(): int
    {
        return count($this->history);
    }

    private function request(int $i): RequestInterface
    {
        return $this->history[$i]['request'];
    }

    /** @return array<string, mixed> */
    private function siteRow(string $site, string $host, string $label): array
    {
        return [
            'siteId' => $site,
            'hostId' => $host,
            'meta' => ['name' => $label.'-site', 'desc' => $label.'-desc', 'timezone' => 'UTC'],
            'statistics' => [
                'counts' => ['totalDevice' => $label === 'AL' ? 4 : 40, 'offlineDevice' => $label === 'AL' ? 1 : 9],
                'ispInfo' => ['name' => $label.'-ISP', 'organization' => $label.'-ISP-ORG'],
                'percentages' => ['wanUptime' => $label === 'AL' ? 97 : 12],
                'internetIssues' => [],
                'gateway' => ['shortname' => $label.'-GW'],
            ],
            'permission' => 'admin',
            'isOwner' => true,
        ];
    }

    /** Both clients' sites, Bravo's FIRST so an unscoped read cannot miss it. */
    private function bothSites(): array
    {
        return [
            'data' => [$this->siteRow(self::B_SITE, self::B_HOST, 'BR'), $this->siteRow(self::A_SITE, self::A_HOST, 'AL')],
            'httpStatusCode' => 200,
        ];
    }

    /** @return array<string, mixed> */
    private function deviceGroup(string $host, string $label): array
    {
        return [
            'hostId' => $host,
            'updatedAt' => now()->subMinutes(5)->toIso8601ZuluString(),
            'devices' => [
                ['id' => $label.'-1', 'mac' => $label.'MAC1', 'name' => $label.'-Test-MBP-AP', 'status' => 'online', 'model' => 'U6'],
                ['id' => $label.'-2', 'mac' => $label.'MAC2', 'name' => $label.'-Test-MBP-SW', 'status' => 'offline', 'model' => 'USW'],
            ],
        ];
    }

    /** Both consoles' device groups, Bravo's FIRST. */
    private function bothDeviceGroups(): array
    {
        return [
            'data' => [$this->deviceGroup(self::B_HOST, 'BR'), $this->deviceGroup(self::A_HOST, 'AL')],
            'httpStatusCode' => 200,
        ];
    }

    /** ISP rows for both sites, Bravo's FIRST; the endpoint has no site filter. */
    private function bothIspRows(): array
    {
        $row = fn (string $site, string $host, string $label, int $latency) => [
            'metricType' => '5m', 'siteId' => $site, 'hostId' => $host,
            'periods' => [['metricTime' => '2026-07-23T13:35:00Z', 'data' => ['wan' => [
                'avgLatency' => $latency, 'packetLoss' => 0, 'ispName' => $label.'-ISP', 'ispAsn' => '64512',
            ]]]],
        ];

        return [
            'data' => [$row(self::B_SITE, self::B_HOST, 'BR', 999), $row(self::A_SITE, self::A_HOST, 'AL', 11)],
            'httpStatusCode' => 200,
        ];
    }

    private function mcp(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: self::READS, label: 'unifi-clientid');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function text(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('result.content.0.text');
    }

    /** @return array<string, mixed> */
    private function served(TestResponse $response): array
    {
        $raw = $this->text($response);
        $this->assertFalse((bool) $response->json('result.isError'), "expected a served read, got: {$raw}");

        return json_decode($raw, true);
    }

    private function refusal(TestResponse $response): string
    {
        $raw = $this->text($response);
        $this->assertTrue((bool) $response->json('result.isError'), "expected a refusal, got: {$raw}");

        return $raw;
    }

    private function assertNoBravo(string $raw, string $context): void
    {
        foreach ([self::B_SITE, self::B_HOST, 'Bravo', 'BR-', 'BRMAC'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "{$context}: Bravo's '{$needle}' leaked into Alpha's read");
        }
    }

    // ── strict resolution within client_id, with another client present upstream ──

    public function test_site_health_serves_only_the_clients_own_sites_and_counts(): void
    {
        $this->vendorReturns([$this->bothSites()]);

        $response = $this->mcp('unifi_get_site_health', ['client_id' => $this->alpha->id]);
        $raw = $this->text($response);
        $result = $this->served($response);

        $this->assertSame($this->alpha->id, $result['psa_client_id']);
        $this->assertSame(1, $result['site_count']);
        $this->assertSame([self::A_SITE], array_column($result['sites'], 'site_id'));
        $this->assertSame(97, $result['sites'][0]['wan_uptime_percent']);
        $this->assertSame(4, $result['sites'][0]['counts']['totalDevice']);
        $this->assertSame(1, $result['sites'][0]['counts']['offlineDevice']);
        $this->assertNoBravo($raw, 'site health');
        $this->assertSame(1, $this->requestCount());
    }

    public function test_site_health_for_the_other_client_is_its_own_mirror(): void
    {
        $this->vendorReturns([$this->bothSites()]);

        $result = $this->served($this->mcp('unifi_get_site_health', ['client_id' => $this->bravo->id]));

        $this->assertSame([self::B_SITE], array_column($result['sites'], 'site_id'));
        $this->assertSame(40, $result['sites'][0]['counts']['totalDevice']);
        $this->assertStringNotContainsString(self::A_SITE, json_encode($result));
    }

    public function test_list_devices_serves_only_the_clients_console_and_counts(): void
    {
        $this->vendorReturns([$this->bothSites(), $this->bothDeviceGroups()]);

        $response = $this->mcp('unifi_list_devices', ['client_id' => $this->alpha->id]);
        $raw = $this->text($response);
        $result = $this->served($response);

        $this->assertSame($this->alpha->id, $result['psa_client_id']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(1, $result['offline_count']);
        $this->assertSame(['AL-1', 'AL-2'], array_column($result['devices'], 'id'));
        $this->assertSame([self::A_HOST], array_values(array_unique(array_column($result['devices'], 'host_id'))));
        $this->assertSame([self::A_HOST], array_column($result['consoles'], 'host_id'));
        $this->assertSame([self::A_SITE], $result['consoles'][0]['site_ids']);
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertNoBravo($raw, 'list devices');
    }

    public function test_the_status_filter_is_applied_inside_the_client(): void
    {
        $this->vendorReturns([$this->bothSites(), $this->bothDeviceGroups()]);

        $result = $this->served($this->mcp('unifi_list_devices', ['client_id' => $this->alpha->id, 'status' => 'offline']));

        $this->assertSame(1, $result['count']);
        $this->assertSame(['AL-2'], array_column($result['devices'], 'id'));
        $this->assertSame(1, $result['consoles'][0]['count']);
    }

    public function test_isp_metrics_serve_only_the_clients_site_though_the_vendor_returns_both(): void
    {
        $this->vendorReturns([$this->bothIspRows()]);

        $response = $this->mcp('unifi_get_isp_metrics', ['client_id' => $this->alpha->id]);
        $raw = $this->text($response);
        $result = $this->served($response);

        $this->assertSame($this->alpha->id, $result['psa_client_id']);
        $this->assertSame(1, $result['site_count']);
        $this->assertSame([self::A_SITE], array_column($result['sites'], 'site_id'));
        $this->assertCount(1, $result['sites'][0]['periods']);
        $this->assertSame(11, $result['sites'][0]['periods'][0]['avg_latency_ms']);
        $this->assertNoBravo($raw, 'isp metrics');
        $this->assertStringNotContainsString('999', $raw);
    }

    // ── shared consoles fail closed ─────────────────────────────────────────────

    public function test_a_console_shared_with_another_mapped_client_fails_closed_before_any_request(): void
    {
        ClientUnifiSite::where('client_id', $this->alpha->id)->update(['unifi_host_id' => self::SHARED_HOST]);
        ClientUnifiSite::where('client_id', $this->bravo->id)->update(['unifi_host_id' => self::SHARED_HOST]);
        $this->vendorReturns([]);

        foreach ([$this->alpha, $this->bravo] as $client) {
            $raw = $this->refusal($this->mcp('unifi_list_devices', ['client_id' => $client->id]));
            $this->assertStringContainsString('shared with another PSA client', $raw);
            $this->assertStringNotContainsString('"devices"', $raw);
        }
        $this->assertSame(0, $this->requestCount());
    }

    public function test_a_console_that_also_serves_an_unmapped_foreign_site_fails_closed_without_a_device_read(): void
    {
        $this->vendorReturns([
            ['data' => [$this->siteRow(self::A_SITE, self::A_HOST, 'AL'), $this->siteRow(self::FOREIGN_SITE, self::A_HOST, 'BR')], 'httpStatusCode' => 200],
            ['data' => [$this->deviceGroup(self::A_HOST, 'BR')], 'httpStatusCode' => 200],
        ]);

        $raw = $this->refusal($this->mcp('unifi_list_devices', ['client_id' => $this->alpha->id]));

        $this->assertStringContainsString('at least one is not mapped to this client', $raw);
        $this->assertStringNotContainsString('BRMAC', $raw);
        $this->assertSame(1, $this->requestCount(), 'only the sites walk; the device list is never fetched');
        $this->assertStringEndsWith('/v1/sites', $this->request(0)->getUri()->getPath());
    }

    public function test_a_shared_console_is_skipped_while_the_clients_clean_console_is_still_served(): void
    {
        ClientUnifiSite::create(['client_id' => $this->alpha->id, 'unifi_site_id' => 'aaaa00000000000000000a02', 'unifi_host_id' => self::SHARED_HOST]);
        ClientUnifiSite::create(['client_id' => $this->bravo->id, 'unifi_site_id' => 'bbbb00000000000000000b02', 'unifi_host_id' => self::SHARED_HOST]);
        $this->vendorReturns([
            $this->bothSites(),
            ['data' => [$this->deviceGroup(self::SHARED_HOST, 'BR'), $this->deviceGroup(self::A_HOST, 'AL')], 'httpStatusCode' => 200],
        ]);

        $response = $this->mcp('unifi_list_devices', ['client_id' => $this->alpha->id]);
        $raw = $this->text($response);
        $result = $this->served($response);

        $this->assertSame(['AL-1', 'AL-2'], array_column($result['devices'], 'id'));
        $this->assertSame([self::SHARED_HOST], array_column($result['skipped'], 'host_id'));
        $this->assertStringNotContainsString('BRMAC', $raw);
        $this->assertStringNotContainsString('bbbb00000000000000000b02', $raw);
    }

    // ── another client's keys typed as input are refused, never honoured ────────

    public function test_another_clients_site_or_console_id_typed_as_input_is_refused_with_no_request(): void
    {
        $this->vendorReturns([]);

        foreach (self::CLIENT_TOOLS as $tool) {
            foreach (['site_id' => self::B_SITE, 'host_id' => self::B_HOST, 'unifi_site_id' => self::B_SITE] as $key => $value) {
                $raw = $this->refusal($this->mcp($tool, ['client_id' => $this->alpha->id, $key => $value]));
                $this->assertStringContainsString("Unsupported MCP argument(s): {$key}", $raw, "{$tool} {$key}");
                $this->assertStringContainsString('REFUSED, not ignored', $raw);
            }
        }
        $this->assertSame(0, $this->requestCount());
    }

    // ── missing, malformed, unknown and unmapped client_id fail closed ───────────

    public function test_a_missing_or_malformed_client_id_is_refused_at_the_boundary_with_no_request(): void
    {
        $this->vendorReturns([]);

        foreach (self::CLIENT_TOOLS as $tool) {
            foreach ([null, 'abc', '0', 0, -1, '07x', 1.5] as $bad) {
                $args = $bad === null ? [] : ['client_id' => $bad];
                $raw = $this->refusal($this->mcp($tool, $args));
                $this->assertSame("client_id is required for {$tool}.", $raw, "{$tool} client_id=".json_encode($bad));
            }
        }
        $this->assertSame(0, $this->requestCount());
    }

    public function test_unknown_and_unmapped_client_ids_fail_closed_with_no_request(): void
    {
        $unmapped = Client::factory()->create(['name' => 'Unmapped Synthetic']);
        $this->vendorReturns([]);

        foreach (self::CLIENT_TOOLS as $tool) {
            $raw = $this->refusal($this->mcp($tool, ['client_id' => 999999]));
            $this->assertStringContainsString('PSA client 999999 was not found.', $raw, $tool);

            $raw = $this->refusal($this->mcp($tool, ['client_id' => $unmapped->id]));
            $this->assertStringContainsString('Unmapped Synthetic is not mapped to a UniFi site', $raw, $tool);
            $this->assertNoBravo($raw, "{$tool} unmapped");
        }
        $this->assertSame(0, $this->requestCount());
    }

    public function test_the_executor_refuses_a_null_client_with_its_exact_message(): void
    {
        $this->vendorReturns([]);

        foreach (self::CLIENT_TOOLS as $tool) {
            $result = app(ChetDataSurfaceToolExecutor::class)->execute($tool, ['client_id' => $this->bravo->id], null);
            $this->assertSame(['error' => "client_id is required for {$tool}."], $result, $tool);
        }
        $this->assertSame(0, $this->requestCount());
    }

    public function test_the_toolset_refuses_a_null_client_too(): void
    {
        $this->vendorReturns([]);

        foreach (self::CLIENT_TOOLS as $tool) {
            $result = app(UnifiReadOnlyToolset::class)->execute($tool, [], null);
            $this->assertSame(['error' => 'client_id is required'], $result, $tool);
        }
        $this->assertSame(0, $this->requestCount());
    }

    public function test_the_bound_client_wins_over_an_agent_typed_client_id(): void
    {
        foreach (self::CLIENT_TOOLS as $tool) {
            $this->vendorReturns(match ($tool) {
                'unifi_get_site_health' => [$this->bothSites()],
                'unifi_list_devices' => [$this->bothSites(), $this->bothDeviceGroups()],
                'unifi_get_isp_metrics' => [$this->bothIspRows()],
            });

            $result = app(ChetDataSurfaceToolExecutor::class)->execute($tool, ['client_id' => $this->bravo->id], $this->alpha->id);

            $this->assertSame($this->alpha->id, $result['psa_client_id'] ?? null, $tool.': '.json_encode($result));
            $this->assertNoBravo(json_encode($result), "{$tool} typed client_id");
        }
    }

    // ── ISP filter arguments actually reach the vendor request ──────────────────

    public function test_isp_type_and_duration_reach_the_vendor_request(): void
    {
        $this->vendorReturns([$this->bothIspRows()]);

        $this->served($this->mcp('unifi_get_isp_metrics', ['client_id' => $this->alpha->id, 'type' => '1h', 'duration' => '30d']));

        $this->assertSame(1, $this->requestCount());
        $uri = $this->request(0)->getUri();
        $this->assertStringEndsWith('/v1/isp-metrics/1h', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        $this->assertSame('30d', $query['duration'] ?? null);
        $this->assertArrayNotHasKey('beginTimestamp', $query);
    }

    public function test_the_isp_default_window_is_5m_over_24h(): void
    {
        $this->vendorReturns([$this->bothIspRows()]);

        $this->served($this->mcp('unifi_get_isp_metrics', ['client_id' => $this->alpha->id]));

        $uri = $this->request(0)->getUri();
        $this->assertStringEndsWith('/v1/isp-metrics/5m', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        $this->assertSame(['duration' => '24h'], $query);
    }

    public function test_an_explicit_isp_window_reaches_the_vendor_request_without_a_duration(): void
    {
        $this->vendorReturns([$this->bothIspRows()]);

        $this->served($this->mcp('unifi_get_isp_metrics', [
            'client_id' => $this->alpha->id,
            'type' => '1h',
            'begin_timestamp' => '2026-07-20T00:00:00Z',
            'end_timestamp' => '2026-07-23T00:00:00Z',
        ]));

        $uri = $this->request(0)->getUri();
        $this->assertStringEndsWith('/v1/isp-metrics/1h', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        $this->assertSame(['beginTimestamp' => '2026-07-20T00:00:00Z', 'endTimestamp' => '2026-07-23T00:00:00Z'], $query);
    }

    // ── unifi_list_sites: account-wide METADATA by design ───────────────────────

    public function test_list_sites_is_account_wide_metadata_only_and_ignores_a_typed_client_id(): void
    {
        $this->vendorReturns([$this->bothSites()]);

        $result = $this->served($this->mcp('unifi_list_sites', ['client_id' => $this->alpha->id]));

        $bySite = collect($result['sites'])->keyBy('site_id');
        $this->assertSame([self::B_SITE, self::A_SITE], $bySite->keys()->all(), 'account-wide by design');
        $this->assertSame($this->alpha->id, $bySite[self::A_SITE]['psa_client_id']);
        $this->assertSame($this->bravo->id, $bySite[self::B_SITE]['psa_client_id']);

        $allowed = ['site_id', 'host_id', 'name', 'description', 'timezone', 'permission', 'is_owner', 'psa_client_id', 'psa_client_name'];
        foreach ($result['sites'] as $row) {
            $this->assertSame($allowed, array_keys($row), 'metadata only');
        }
        $encoded = json_encode($result);
        foreach (['ISP', 'wanUptime', 'statistics', 'totalDevice', 'GW'] as $telemetry) {
            $this->assertStringNotContainsString($telemetry, $encoded, "list_sites must not carry {$telemetry}");
        }
    }
}
