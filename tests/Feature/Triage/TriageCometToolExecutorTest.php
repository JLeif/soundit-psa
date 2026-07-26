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
 * The comet triage tools speak ONE backup-read vocabulary (psa-enpew.8 /
 * psa-z30dv): the state field is `job_state` — the same name
 * comet_get_backup_posture uses — with the shared values unavailable /
 * not_queried / no_backup_jobs_observed / ok, and `jobs_checked_at` is
 * retained whenever a lookup was actually attempted. Two dialects for the
 * same condition is how an agent reads a failed lookup as an all-clear.
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
        $this->assertNotNull($result['jobs_checked_at'], 'a lookup was attempted — its time must be retained');
        $this->assertStringContainsString('UNKNOWN', $result['error']);
        $this->assertArrayNotHasKey('job_count', $result, 'a failed read must not carry a 0-shaped count');
        $this->assertArrayNotHasKey('job_data_state', $result, 'the old field name is retired');
    }

    public function test_backup_jobs_tool_reports_not_queried_when_no_username_is_synced(): void
    {
        $this->mock(CometClient::class)->shouldNotReceive('getJobsForUser');

        $result = (new TriageToolExecutor($this->ticketWithCometAsset(['comet_username' => null])))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('not_queried', $result['job_state']);
        $this->assertArrayHasKey('jobs_checked_at', $result);
        $this->assertNull($result['jobs_checked_at'], 'nothing was asked, so no checked-at time exists');
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
        $this->assertStringContainsString('backups may never have run', $result['note']);
    }

    public function test_backup_jobs_tool_reports_ok_with_jobs(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->backupJob(\Comet\Def::JOB_STATUS_FAILED_QUOTA, now()->subHour()->timestamp),
        ]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_jobs', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('ok', $result['job_state']);
        $this->assertSame(1, $result['job_count']);
        $this->assertSame('Failed (quota exceeded)', $result['jobs'][0]['status']);
    }

    public function test_backup_status_tool_uses_the_same_job_state_vocabulary(): void
    {
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([]);

        $result = (new TriageToolExecutor($this->ticketWithCometAsset()))
            ->execute('comet_get_backup_status', ['hostname' => 'ACME-SRV-01']);

        $this->assertSame('no_backup_jobs_observed', $result['job_state']);
        $this->assertArrayNotHasKey('job_data_state', $result, 'the old field name is retired');
        $this->assertStringContainsString('backups may never have run', $result['job_history_note']);
    }
}
