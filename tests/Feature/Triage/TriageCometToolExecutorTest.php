<?php

namespace Tests\Feature\Triage;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometClientException;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The comet triage tools speak ONE backup-read vocabulary (psa-enpew.12 /
 * psa-z30dv): `job_state` is the device's backup POSTURE — the same meaning
 * comet_get_backup_posture gives the same field — last_backup_succeeded /
 * last_backup_failed / last_backup_running / last_backup_unknown /
 * no_backup_jobs_observed, degrading to 'unavailable' when the history could
 * not be read (server unreachable OR no synced username — one word for one
 * condition, matching the psa-z30dv toolset). It is never 'ok': "the read
 * worked" relayed as "the backup worked" is exactly the false human relay the
 * round-3 review blocked. `jobs_checked_at` is retained whenever a lookup was
 * actually attempted.
 */
class TriageCometToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithCometAsset(array $assetOverrides = []): Ticket
    {
        $client = Client::factory()->create();
        Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'ACME-SRV-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'dev-1',
        ], $assetOverrides));

        return Ticket::factory()->create(['client_id' => $client->id]);
    }

    /** Vendor-shaped job fixture (SDK object, CLAUDE.md fixture rule). */
    private function backupJob(int $status, int $start): \Comet\BackupJobDetail
    {
        $job = new \Comet\BackupJobDetail;
        $job->Username = 'acme-backup';
        $job->DeviceID = 'dev-1';
        $job->Classification = \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $status;
        $job->StartTime = $start;
        $job->EndTime = $start + 600;

        return $job;
    }

    public function test_backup_jobs_tool_reports_unavailable_with_job_state_and_checked_at(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')
            ->andThrow(new CometClientException('connection refused'));

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('unavailable', $result['job_state']);
        $this->assertNotNull($result['job_state_note']);
        $this->assertNotNull($result['jobs_checked_at'], 'a lookup was attempted — its time must be retained');
        $this->assertStringContainsString('UNKNOWN', $result['error']);
        $this->assertArrayNotHasKey('job_count', $result, 'a failed read must not carry a 0-shaped count');
        $this->assertArrayNotHasKey('job_data_state', $result, 'the old field name is retired');
    }

    public function test_backup_jobs_tool_reports_unavailable_when_no_username_is_synced(): void
    {
        // One dialect (psa-enpew.12): the psa-z30dv posture tool calls a
        // device whose username cannot be looked up 'unavailable' — this
        // surface must not call the same condition 'not_queried'.
        $this->mock(CometClient::class)->shouldNotReceive('getJobsForUser');

        $result = (new TriageToolExecutor($this->ticketWithCometAsset(['comet_username' => null])))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('unavailable', $result['job_state']);
        $this->assertArrayHasKey('jobs_checked_at', $result);
        $this->assertNull($result['jobs_checked_at'], 'nothing was asked, so no checked-at time exists');
        $this->assertStringContainsString('Comet username', $result['error']);
        $this->assertStringContainsString('UNKNOWN', $result['error']);
    }

    public function test_backup_jobs_tool_reports_no_backup_jobs_observed_for_a_real_empty_answer(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('no_backup_jobs_observed', $result['job_state']);
        $this->assertSame(0, $result['job_count']);
        $this->assertNotNull($result['jobs_checked_at']);
        $this->assertStringContainsString('backups may never have run', $result['job_state_note']);
    }

    public function test_backup_jobs_tool_reports_the_failed_posture_not_ok(): void
    {
        // A device whose only recent backup FAILED must not top-line as
        // anything an agent can relay as fine (psa-enpew.12).
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->backupJob(\Comet\Def::JOB_STATUS_FAILED_QUOTA, now()->subHour()->timestamp),
        ]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('last_backup_failed', $result['job_state']);
        $this->assertSame(1, $result['job_count']);
        $this->assertSame('Failed (quota exceeded)', $result['jobs'][0]['status']);
        $this->assertSame('Failed (quota exceeded)', $result['last_backup_failure']['status']);
        $this->assertNull($result['last_backup_success']);
    }

    public function test_backup_status_tool_uses_the_same_job_state_vocabulary(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('no_backup_jobs_observed', $result['job_state']);
        $this->assertArrayNotHasKey('job_data_state', $result, 'the old field name is retired');
        $this->assertStringContainsString('backups may never have run', $result['job_state_note']);
    }

    public function test_backup_status_tool_reports_last_backup_failed_never_ok_when_the_newest_backup_failed(): void
    {
        // THE psa-enpew.12 relay case: an older success exists, the newest
        // backup failed — job_state must carry the failure outright instead
        // of leaving the agent to reconcile sibling timestamps.
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->backupJob(\Comet\Def::JOB_STATUS_STOP_SUCCESS, now()->subDays(3)->timestamp),
            $this->backupJob(\Comet\Def::JOB_STATUS_FAILED_ERROR, now()->subHour()->timestamp),
        ]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('last_backup_failed', $result['job_state']);
        $this->assertNotSame('ok', $result['job_state']);
        $this->assertSame('Failed (error)', $result['last_backup_status']);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_ERROR, $result['last_backup_status_code']);
        $this->assertNotNull($result['last_backup_success_at'], 'the older success stays visible');
        $this->assertNotNull($result['last_backup_failure_at']);
        $this->assertArrayNotHasKey('last_success', $result, 'renamed to the shared posture field names');
        $this->assertArrayNotHasKey('last_failure', $result, 'renamed to the shared posture field names');
    }

    public function test_backup_status_tool_reports_success_posture_with_its_timestamps(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->backupJob(\Comet\Def::JOB_STATUS_FAILED_ERROR, now()->subDays(3)->timestamp),
            $this->backupJob(\Comet\Def::JOB_STATUS_STOP_SUCCESS, now()->subHour()->timestamp),
        ]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('last_backup_succeeded', $result['job_state']);
        $this->assertSame('Success', $result['last_backup_status']);
        $this->assertSame(0, $result['days_since_last_success']);
        $this->assertNotNull($result['last_backup_failure_at'], 'the older failure stays on record');
        $this->assertArrayNotHasKey('job_state_note', $result, 'a clean posture carries no caveat note');
    }

    public function test_backup_status_tool_screams_unavailable_when_the_lookup_fails(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')
            ->andThrow(new CometClientException('timeout'));

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('unavailable', $result['job_state']);
        $this->assertStringContainsString('UNKNOWN', $result['job_state_note']);
        $this->assertNull($result['last_backup_success_at']);
        $this->assertNull($result['last_backup_failure_at']);
    }

    public function test_backup_status_tool_reports_unavailable_when_no_username_is_synced(): void
    {
        $this->mock(CometClient::class)->shouldNotReceive('getJobsForUser');

        $result = (new TriageToolExecutor($this->ticketWithCometAsset(['comet_username' => null])))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('unavailable', $result['job_state']);
        $this->assertStringContainsString('Re-run the Comet backup sync', $result['job_state_note']);
        $this->assertNull($result['jobs_checked_at']);
    }
}
