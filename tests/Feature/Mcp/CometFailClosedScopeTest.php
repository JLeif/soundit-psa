<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Comet\CometClient;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card 6abe578e, Comet C-1 / C-2: one fail-closed rule on BOTH Comet read surfaces
 * (staff MCP comet_list_backup_jobs via ChetDataSurfaceToolExecutor, and triage
 * comet_get_backup_status / comet_get_backup_jobs), shared via CometClientScope.
 *
 *  C-1: two Comet-linked rows of ONE client sharing a hostname (case-insensitive) fail
 *       closed with an error listing this client's candidate asset ids — never the
 *       lower-id row's history. No vendor call is made.
 *  C-2: triage on a client with no comet_group_id refuses exactly as the MCP toolset
 *       does, even when a leftover linked asset still matches. No vendor call.
 *
 * Fixture shape from the card-6abdcac2 audit probe (synthetic): Alpha owns ALPHA-DUP and
 * alpha-dup under one username with different device ids (success vs failure), an
 * unmapped client owns UNMAPPED-LEFTOVER. The vendor is the SDK client, mocked; no live
 * calls (Http::preventStrayRequests). Every refusal is paired with the single-match
 * control on the same surface.
 */
class CometFailClosedScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $vendorCalls = [];

    private Client $alpha;

    private Client $unmapped;

    private Asset $dup1;

    private Asset $dup2;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        Setting::setValue('comet_server_url', 'https://comet.example.test');
        Setting::setEncrypted('comet_admin_user', 'admin');
        Setting::setEncrypted('comet_admin_password', 'pw');

        $this->alpha = Client::factory()->create(['name' => 'Alpha Synthetic', 'comet_group_id' => 'grp-alpha']);
        $this->unmapped = Client::factory()->create(['name' => 'Unmapped Synthetic', 'comet_group_id' => null]);
        $base = ['comet_backup_enabled' => true, 'backup_synced_at' => now()->subHours(2), 'is_active' => true];
        Asset::factory()->create($base + ['client_id' => $this->alpha->id, 'hostname' => 'Test-MBP.lan', 'comet_username' => 'alpha-single', 'comet_device_id' => 'dev-alpha-1']);
        $this->dup1 = Asset::factory()->create($base + ['client_id' => $this->alpha->id, 'hostname' => 'ALPHA-DUP', 'comet_username' => 'alpha-only', 'comet_device_id' => 'dev-alpha-dup-1']);
        $this->dup2 = Asset::factory()->create($base + ['client_id' => $this->alpha->id, 'hostname' => 'alpha-dup', 'comet_username' => 'alpha-only', 'comet_device_id' => 'dev-alpha-dup-2']);
        Asset::factory()->create($base + ['client_id' => $this->unmapped->id, 'hostname' => 'UNMAPPED-LEFTOVER', 'comet_username' => 'leftover-user', 'comet_device_id' => 'dev-leftover']);

        $jobs = [
            'alpha-single' => [$this->job('alpha-single', 'dev-alpha-1', \Comet\Def::JOB_STATUS_STOP_SUCCESS, 6)],
            'alpha-only' => [
                $this->job('alpha-only', 'dev-alpha-dup-1', \Comet\Def::JOB_STATUS_STOP_SUCCESS, 5),
                $this->job('alpha-only', 'dev-alpha-dup-2', \Comet\Def::JOB_STATUS_FAILED_ERROR, 4),
            ],
            'leftover-user' => [$this->job('leftover-user', 'dev-leftover', \Comet\Def::JOB_STATUS_STOP_SUCCESS, 2)],
        ];
        $calls = &$this->vendorCalls;
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturnUsing(function (string $u) use ($jobs, &$calls) {
            $calls[] = $u;

            return $jobs[$u] ?? [];
        });
    }

    private function job(string $user, string $device, int $status, int $hoursAgo): \Comet\BackupJobDetail
    {
        return \Comet\BackupJobDetail::createFromJSON(json_encode([
            'Username' => $user, 'DeviceID' => $device,
            'Classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'Status' => $status,
            'StartTime' => now()->subHours($hoursAgo)->timestamp, 'EndTime' => now()->subHours($hoursAgo - 1)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function mcpList(int $clientId, string $hostname): array
    {
        return app(ChetDataSurfaceToolExecutor::class)->execute('comet_list_backup_jobs', ['client_id' => $clientId, 'hostname' => $hostname], $clientId);
    }

    private function triage(Client $client, string $tool, string $hostname): array
    {
        return (new TriageToolExecutor(Ticket::factory()->create(['client_id' => $client->id])))->execute($tool, ['hostname' => $hostname]);
    }

    private function assertAmbiguity(array $result): void
    {
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('more than one Comet-linked device', $result['error']);
        $this->assertSame([$this->dup1->id, $this->dup2->id], array_column($result['candidates'] ?? [], 'asset_id'));
        $this->assertArrayNotHasKey('jobs', $result);
        $this->assertArrayNotHasKey('job_state', $result);
    }

    // ── C-1 ──

    public function test_c1_mcp_list_backup_jobs_fails_closed_on_a_same_client_duplicate_hostname(): void
    {
        $this->assertAmbiguity($this->mcpList($this->alpha->id, 'alpha-dup'));
        $this->assertSame([], $this->vendorCalls, 'an ambiguous hostname must not reach the vendor');

        $single = $this->mcpList($this->alpha->id, 'test-mbp.lan');
        $this->assertArrayNotHasKey('error', $single);
        $this->assertSame(1, $single['job_count']);
    }

    public function test_c1_triage_backup_status_and_jobs_fail_closed_on_a_same_client_duplicate_hostname(): void
    {
        foreach (['comet_get_backup_status', 'comet_get_backup_jobs'] as $tool) {
            $this->assertAmbiguity($this->triage($this->alpha, $tool, 'ALPHA-DUP'));
        }
        $this->assertSame([], $this->vendorCalls, 'an ambiguous hostname must not reach the vendor');

        $status = $this->triage($this->alpha, 'comet_get_backup_status', 'Test-MBP.lan');
        $this->assertSame('last_backup_succeeded', $status['job_state']);
        $jobs = $this->triage($this->alpha, 'comet_get_backup_jobs', 'Test-MBP.lan');
        $this->assertSame(1, $jobs['job_count']);
    }

    public function test_c1_an_unlinked_namesake_does_not_make_the_linked_row_ambiguous(): void
    {
        Asset::factory()->create(['client_id' => $this->alpha->id, 'hostname' => 'test-mbp.lan', 'comet_device_id' => null, 'comet_backup_enabled' => false]);

        $this->assertSame(1, $this->mcpList($this->alpha->id, 'Test-MBP.lan')['job_count']);
        $this->assertSame(1, $this->triage($this->alpha, 'comet_get_backup_jobs', 'Test-MBP.lan')['job_count']);
    }

    // ── C-2 ──

    public function test_c2_triage_refuses_an_unmapped_client_exactly_as_the_mcp_toolset_does(): void
    {
        $mcp = $this->mcpList($this->unmapped->id, 'UNMAPPED-LEFTOVER');
        $this->assertStringContainsString('is not mapped to a Comet organization', $mcp['error']);

        foreach (['comet_get_backup_status', 'comet_get_backup_jobs'] as $tool) {
            $result = $this->triage($this->unmapped, $tool, 'UNMAPPED-LEFTOVER');
            $this->assertSame(['error' => $mcp['error']], $result, "{$tool} must refuse with the MCP toolset's own text");
        }
        $this->assertSame([], $this->vendorCalls, 'an unmapped client\'s leftover row must never reach the vendor');
    }

    public function test_c2_mapping_the_client_restores_triage(): void
    {
        $this->unmapped->forceFill(['comet_group_id' => 'grp-now-mapped'])->save();

        $result = $this->triage($this->unmapped, 'comet_get_backup_status', 'UNMAPPED-LEFTOVER');
        $this->assertSame('last_backup_succeeded', $result['job_state']);
    }
}
