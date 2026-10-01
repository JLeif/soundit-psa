<?php

namespace Tests\Feature\Mcp;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometJobService;
use App\Services\Comet\CometReadOnlyToolset;
use App\Services\Triage\TriageToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card 6abdcac2, seventh integration: Comet reads take client_id and resolve
 * strictly within that client.
 *
 * The Comet admin API is server-wide: AdminGetJobsForUser returns every device
 * under a username, and nothing upstream stops one username spanning two
 * clients' machines. So the fences are ours: the client's own asset rows choose
 * the usernames, and each job is kept only when its DeviceID is one of THAT
 * client's devices. Every fixture here shares one username between Alpha and
 * Bravo and puts Bravo's DeviceID (a quota failure) in the vendor payload, so a
 * dropped device filter shows up as Bravo's job in Alpha's answer.
 *
 * Surfaces: the staff MCP (comet_get_backup_posture, comet_list_backup_jobs via
 * ChetDataSurfaceToolExecutor) and triage (comet_get_backup_status,
 * comet_get_backup_jobs, bound to the ticket's client). The Assistant and the
 * portal publish no comet tool.
 *
 * Production change pinned here: CometJobService used to skip the device filter
 * when the asset's comet_device_id was blank (`if ($deviceId && ...)`), serving
 * the username's whole job set. It now refuses before any vendor call.
 *
 * Synthetic data only: "Alpha Synthetic"/"Bravo Synthetic", hosts such as
 * Test-MBP.lan, placeholder usernames and device ids.
 */
class CometClientIdResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_USER = 'shared-synthetic-backup';

    private const BRAVO_HOST = 'BRAVO-ONLY-WS';

    private const BRAVO_DEVICE = 'dev-bravo-only';

    private const ALPHA_HOST = 'Test-MBP.lan';

    private const ALPHA_DEVICE = 'dev-alpha-mbp';

    private const READS = ['comet_get_backup_posture', 'comet_list_backup_jobs'];

    private Client $alpha;

    private Client $bravo;

    private Asset $alphaAsset;

    private Asset $bravoAsset;

    /** @var list<string> usernames the fake vendor was asked about, in order */
    private array $vendorCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'admin');
        Setting::setEncrypted('comet_admin_password', 'pw');

        $this->alpha = Client::factory()->create(['name' => 'Alpha Synthetic', 'comet_group_id' => 'grp-alpha-synthetic']);
        $this->bravo = Client::factory()->create(['name' => 'Bravo Synthetic', 'comet_group_id' => 'grp-bravo-synthetic']);

        $this->alphaAsset = $this->cometAsset($this->alpha, self::ALPHA_HOST, self::ALPHA_DEVICE);
        $this->bravoAsset = $this->cometAsset($this->bravo, self::BRAVO_HOST, self::BRAVO_DEVICE);

        // One username, two clients' devices: Alpha's backup succeeded, Bravo's hit quota.
        $this->vendorReturns([
            self::SHARED_USER => [
                $this->job(self::ALPHA_DEVICE, \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6),
                $this->job(self::BRAVO_DEVICE, \Comet\Def::JOB_STATUS_FAILED_QUOTA, 3),
            ],
        ]);
    }

    private function cometAsset(Client $client, string $hostname, ?string $deviceId, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => $hostname,
            'is_active' => true,
            'comet_username' => self::SHARED_USER,
            'comet_device_id' => $deviceId,
            'comet_backup_enabled' => true,
            'backup_synced_at' => now()->subHours(2),
        ], $overrides));
    }

    /** Vendor-wire JSON through the SDK's own parser (CLAUDE.md fixture rule). */
    private function job(string $deviceId, int $status, int $hoursAgo): \Comet\BackupJobDetail
    {
        return \Comet\BackupJobDetail::createFromJSON(json_encode([
            'Username' => self::SHARED_USER,
            'DeviceID' => $deviceId,
            'Classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP,
            'Status' => $status,
            'StartTime' => now()->subHours($hoursAgo)->timestamp,
            'EndTime' => now()->subHours($hoursAgo - 1)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, list<\Comet\BackupJobDetail>> $byUsername */
    private function vendorReturns(array $byUsername): void
    {
        $this->vendorCalls = [];
        $calls = &$this->vendorCalls;
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')
            ->andReturnUsing(function (string $username) use ($byUsername, &$calls): array {
                $calls[] = $username;

                return $byUsername[$username] ?? [];
            });
    }

    private function mcp(string $tool, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.McpConfig::rotateStaffToken(allowedTools: self::READS, label: 'comet-clientid')])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function mcpResult(string $tool, array $arguments): array
    {
        $response = $this->mcp($tool, $arguments);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), $tool.': '.$response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function mcpError(string $tool, array $arguments): string
    {
        $response = $this->mcp($tool, $arguments);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), $tool.' should have been refused');

        return (string) $response->json('result.content.0.text');
    }

    /** comet_list_backup_jobs takes a hostname; the posture tool declares none. */
    private function hostArg(string $tool, string $hostname): array
    {
        return $tool === 'comet_list_backup_jobs' ? ['hostname' => $hostname] : [];
    }

    private function assertNoBravo(string $payload, string $context): void
    {
        foreach ([self::BRAVO_HOST, self::BRAVO_DEVICE, 'Bravo Synthetic', 'quota', 'Quota', '7003'] as $needle) {
            $this->assertStringNotContainsString($needle, $payload, "{$context}: Bravo's '{$needle}' crossed the client boundary");
        }
    }

    // ── staff MCP: posture ──

    public function test_posture_resolves_strictly_within_client_id_under_a_shared_username(): void
    {
        $result = $this->mcpResult('comet_get_backup_posture', ['client_id' => $this->alpha->id]);

        $this->assertSame($this->alpha->id, $result['psa_client_id']);
        $this->assertSame([$this->alphaAsset->id], array_column($result['devices'], 'asset_id'));
        $this->assertSame('last_backup_succeeded', $result['devices'][0]['job_state'], "Bravo's quota failure must not become Alpha's last backup");
        $this->assertSame(1, $result['summary']['devices_total']);
        $this->assertSame(1, $result['summary']['last_backup_succeeded']);
        $this->assertSame(0, $result['summary']['last_backup_failed']);
        $this->assertNoBravo(json_encode($result), 'posture');
        $this->assertSame([self::SHARED_USER], $this->vendorCalls);

        // Mirror: Bravo's answer is Bravo's device and Bravo's failure, from the same vendor payload.
        $mirror = $this->mcpResult('comet_get_backup_posture', ['client_id' => $this->bravo->id]);
        $this->assertSame([$this->bravoAsset->id], array_column($mirror['devices'], 'asset_id'));
        $this->assertSame('last_backup_failed', $mirror['devices'][0]['job_state']);
        $this->assertStringNotContainsString(self::ALPHA_HOST, json_encode($mirror));
    }

    public function test_posture_alerts_and_lifecycle_counts_are_the_clients_own(): void
    {
        // Separately scoped count sites: each carries its own client predicate.
        Alert::create([
            'client_id' => $this->bravo->id,
            'source' => AlertSource::Comet,
            'source_alert_id' => self::BRAVO_DEVICE.':4',
            'severity' => AlertSeverity::Critical,
            'status' => AlertStatus::Active,
            'title' => 'Backup Failed on '.self::BRAVO_HOST,
            'message' => 'Device: '.self::BRAVO_HOST,
            'hostname' => self::BRAVO_HOST,
            'fired_at' => now()->subHour(),
        ]);
        Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => 'BRAVO-INACTIVE', 'is_active' => false]);
        Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => 'BRAVO-RETIRED'])->delete();
        Asset::factory()->create(['client_id' => $this->bravo->id, 'hostname' => 'BRAVO-UNCONFIGURED', 'is_active' => true]);

        $result = $this->mcpResult('comet_get_backup_posture', ['client_id' => $this->alpha->id]);

        $this->assertSame(0, $result['active_backup_alerts']['count'], "Bravo's open alert is not Alpha's");
        $this->assertSame(0, $result['fleet_coverage']['inactive_assets_excluded']);
        $this->assertSame(0, $result['fleet_coverage']['retired_assets_excluded']);
        $this->assertSame(1, $result['fleet_coverage']['active_assets_total']);
        $this->assertSame(0, $result['fleet_coverage']['backup_not_configured_count']);
        $this->assertNoBravo(json_encode($result), 'posture alerts/counts');
        $this->assertStringNotContainsString('BRAVO-', json_encode($result));

        // Positive control: the same sites DO count Bravo's rows for Bravo.
        $mirror = $this->mcpResult('comet_get_backup_posture', ['client_id' => $this->bravo->id]);
        $this->assertSame(1, $mirror['active_backup_alerts']['count']);
        $this->assertSame(1, $mirror['fleet_coverage']['inactive_assets_excluded']);
        $this->assertSame(1, $mirror['fleet_coverage']['retired_assets_excluded']);
        $this->assertSame(1, $mirror['fleet_coverage']['backup_not_configured_count']);
    }

    // ── staff MCP: job listing ──

    public function test_list_jobs_keeps_only_this_devices_jobs_under_a_shared_username(): void
    {
        $result = $this->mcpResult('comet_list_backup_jobs', ['client_id' => $this->alpha->id, 'hostname' => self::ALPHA_HOST]);

        $this->assertSame($this->alphaAsset->id, $result['asset_id']);
        $this->assertSame(1, $result['job_count'], "the username's other device (Bravo's) must be filtered out");
        $this->assertSame(['Success'], array_column($result['jobs'], 'status'));
        $this->assertNull($result['last_backup_failure'], "Bravo's failure is not Alpha's last failure");
        $this->assertNoBravo(json_encode($result), 'list_backup_jobs');

        // Bravo's own call is served from the shared per-username cache, and is still Bravo's alone.
        $mirror = $this->mcpResult('comet_list_backup_jobs', ['client_id' => $this->bravo->id, 'hostname' => self::BRAVO_HOST]);
        $this->assertSame([self::SHARED_USER], $this->vendorCalls, 'second read is cache-served');
        $this->assertSame(['Failed (quota exceeded)'], array_column($mirror['jobs'], 'status'));
        $this->assertStringNotContainsString(self::ALPHA_HOST, json_encode($mirror));
    }

    public function test_another_clients_hostname_is_not_found_and_nothing_is_fetched(): void
    {
        foreach ([self::BRAVO_HOST, strtolower(self::BRAVO_HOST)] as $hostname) {
            $error = $this->mcpError('comet_list_backup_jobs', ['client_id' => $this->alpha->id, 'hostname' => $hostname]);

            $this->assertStringContainsString('No Comet-registered asset', $error);
            $this->assertStringContainsString('for this client', $error);
            $this->assertStringNotContainsString('quota', $error);
        }
        $this->assertSame([], $this->vendorCalls, "Bravo's username must never be asked about on Alpha's behalf");
    }

    public function test_undeclared_device_and_vendor_keys_are_refused(): void
    {
        foreach (['asset_id' => $this->bravoAsset->id, 'device_id' => self::BRAVO_DEVICE, 'comet_username' => self::SHARED_USER, 'comet_group_id' => 'grp-bravo-synthetic'] as $key => $value) {
            $error = $this->mcpError('comet_list_backup_jobs', ['client_id' => $this->alpha->id, 'hostname' => self::ALPHA_HOST, $key => $value]);
            $this->assertStringContainsString("Unsupported MCP argument(s): {$key}", $error);
        }
        $this->assertSame([], $this->vendorCalls);
    }

    // ── client_id: bound, missing, malformed, unknown, unmapped ──

    public function test_the_bound_client_wins_over_a_typed_one(): void
    {
        $executor = app(ChetDataSurfaceToolExecutor::class);

        $posture = $executor->execute('comet_get_backup_posture', ['client_id' => $this->bravo->id], $this->alpha->id);
        $this->assertSame($this->alpha->id, $posture['psa_client_id']);
        $this->assertNoBravo(json_encode($posture), 'bound-vs-typed posture');

        $jobs = $executor->execute('comet_list_backup_jobs', ['client_id' => $this->bravo->id, 'hostname' => self::BRAVO_HOST], $this->alpha->id);
        $this->assertStringContainsString('No Comet-registered asset', $jobs['error']);
    }

    public function test_a_null_client_is_refused_by_the_executor_and_by_the_toolset(): void
    {
        foreach (self::READS as $tool) {
            $input = ['client_id' => $this->alpha->id, 'hostname' => self::ALPHA_HOST];
            $this->assertSame(
                ['error' => "client_id is required for {$tool}."],
                app(ChetDataSurfaceToolExecutor::class)->execute($tool, $input, null),
            );
            $this->assertSame(['error' => 'client_id is required'], app(CometReadOnlyToolset::class)->execute($tool, ['hostname' => self::ALPHA_HOST], null));
        }
        $this->assertSame([], $this->vendorCalls);
    }

    public function test_missing_or_malformed_client_id_fails_closed_at_the_boundary(): void
    {
        foreach (self::READS as $tool) {
            foreach ([null, 'abc', '0', 0, -1, '07x', 1.5] as $bad) {
                $arguments = $this->hostArg($tool, self::ALPHA_HOST);
                if ($bad !== null) {
                    $arguments['client_id'] = $bad;
                }
                $this->assertSame("client_id is required for {$tool}.", $this->mcpError($tool, $arguments), $tool.' '.var_export($bad, true));
            }
        }
        $this->assertSame([], $this->vendorCalls);
    }

    public function test_unknown_and_unmapped_clients_fail_closed_without_a_vendor_call(): void
    {
        $unmapped = Client::factory()->create(['name' => 'Unmapped Synthetic', 'comet_group_id' => null]);
        $this->cometAsset($unmapped, 'UNMAPPED-LEFTOVER', 'dev-unmapped-leftover');

        foreach (self::READS as $tool) {
            $this->assertStringContainsString('PSA client 999999 was not found.', $this->mcpError($tool, ['client_id' => 999999] + $this->hostArg($tool, self::ALPHA_HOST)));

            $error = $this->mcpError($tool, ['client_id' => $unmapped->id] + $this->hostArg($tool, 'UNMAPPED-LEFTOVER'));
            $this->assertStringContainsString('not mapped to a Comet organization', $error);
            $this->assertStringNotContainsString('UNMAPPED-LEFTOVER', $error);
        }
        $this->assertSame([], $this->vendorCalls);
    }

    // ── triage: bound to the ticket's client ──

    private function triage(Client $client): TriageToolExecutor
    {
        return new TriageToolExecutor(Ticket::factory()->create(['client_id' => $client->id]));
    }

    public function test_triage_serves_only_this_devices_jobs_under_a_shared_username(): void
    {
        $triage = $this->triage($this->alpha);

        $status = $triage->execute('comet_get_backup_status', ['hostname' => self::ALPHA_HOST]);
        $this->assertSame('last_backup_succeeded', $status['job_state'], "Bravo's quota failure must not become Alpha's posture");
        $this->assertNull($status['last_backup_failure_at']);

        $jobs = $triage->execute('comet_get_backup_jobs', ['hostname' => self::ALPHA_HOST]);
        $this->assertSame(1, $jobs['job_count']);
        $this->assertSame(['Success'], array_column($jobs['jobs'], 'status'));
        $this->assertNull($jobs['last_backup_failure']);

        $this->assertNoBravo(json_encode([$status, $jobs]), 'triage');
    }

    public function test_triage_never_reaches_another_clients_hostname_even_with_a_typed_client_id(): void
    {
        $triage = $this->triage($this->alpha);

        foreach (['comet_get_backup_status', 'comet_get_backup_jobs'] as $tool) {
            foreach ([['hostname' => self::BRAVO_HOST], ['hostname' => self::BRAVO_HOST, 'client_id' => $this->bravo->id]] as $input) {
                $result = $triage->execute($tool, $input);
                $this->assertSame(['error' => "No Comet-linked asset found for hostname '".self::BRAVO_HOST."' in this client"], $result, $tool);
            }

            // A typed client_id does not rebind Alpha's own lookup either.
            $own = $triage->execute($tool, ['hostname' => self::ALPHA_HOST, 'client_id' => $this->bravo->id]);
            $this->assertSame(self::ALPHA_HOST, $own['hostname'], $tool);
            $this->assertNoBravo(json_encode($own), $tool.' typed client_id');
        }
        $this->assertSame([self::SHARED_USER, self::SHARED_USER], $this->vendorCalls, 'only the two own-host reads reached the vendor');

        // Positive control: the same hostname IS served on Bravo's own ticket.
        $mirror = $this->triage($this->bravo)->execute('comet_get_backup_jobs', ['hostname' => self::BRAVO_HOST]);
        $this->assertSame(['Failed (quota exceeded)'], array_column($mirror['jobs'], 'status'));
    }

    // ── the device fence fails closed on a blank device id ──

    public function test_a_blank_device_id_is_refused_before_any_vendor_call(): void
    {
        // Base behaviour: `if ($deviceId && ...)` turned the filter OFF for a blank id and
        // served the username's whole job set, Bravo's quota failure included.
        $this->alphaAsset->forceFill(['comet_device_id' => ''])->save();
        $triage = $this->triage($this->alpha);

        $jobs = $triage->execute('comet_get_backup_jobs', ['hostname' => self::ALPHA_HOST]);
        $this->assertSame('unavailable', $jobs['job_state']);
        $this->assertStringContainsString('no Comet device id', $jobs['error']);
        $this->assertStringContainsString('UNKNOWN, not passing', $jobs['error']);
        $this->assertArrayNotHasKey('jobs', $jobs);

        $status = $triage->execute('comet_get_backup_status', ['hostname' => self::ALPHA_HOST]);
        $this->assertSame('unavailable', $status['job_state']);
        $this->assertNull($status['last_backup_failure_at']);

        $this->assertNoBravo(json_encode([$jobs, $status]), 'blank device id');
        $this->assertSame([], $this->vendorCalls, 'no device id means nothing to filter on, so nothing is fetched');
    }

    public function test_the_job_service_refuses_a_whitespace_device_id_and_filters_a_real_one(): void
    {
        $service = new CometJobService(app(CometClient::class));

        foreach (['', '   '] as $blank) {
            $this->alphaAsset->forceFill(['comet_device_id' => $blank])->save();
            $result = $service->getRecentJobs($this->alphaAsset->fresh(), 30);
            $this->assertSame('unavailable', $result['state']);
            $this->assertSame('no_device_id', $result['unavailable_reason']);
            $this->assertSame([], $result['jobs']);
        }
        $this->assertSame([], $this->vendorCalls);

        $this->alphaAsset->forceFill(['comet_device_id' => self::ALPHA_DEVICE])->save();
        $result = $service->getRecentJobs($this->alphaAsset->fresh(), 30);
        $this->assertSame('ok', $result['state']);
        $this->assertSame([5000], array_column($result['jobs'], 'status_code'));
        $this->assertNull($result['last_failure']);
    }
}
