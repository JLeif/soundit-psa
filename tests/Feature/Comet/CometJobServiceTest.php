<?php

namespace Tests\Feature\Comet;

use App\Models\Asset;
use App\Models\Client;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometClientException;
use App\Services\Comet\CometJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CometJobService job labelling + posture tracking (psa-enpew).
 *
 * Job fixtures are REAL \Comet\BackupJobDetail objects carrying \Comet\Def
 * constants — the SDK ships the vendor shape, so the fixtures cannot drift
 * from it (CLAUDE.md fixture rule). The previous implementation exact-matched
 * small-int classifications and five status codes, so real SDK-scale jobs
 * labelled 'Other'/'Unknown' and last_failure missed every failure subtype
 * except 7002; per the psa-enpew honest limit, backup classification is now
 * accepted on BOTH scales (4001 and legacy 4) via CometJobCodes.
 *
 * The service's availability contract is also pinned here: a failed or
 * impossible read returns state=unavailable/not_queried, never the clean
 * empty shape a genuine no-jobs answer has — and a genuine no-backup-jobs
 * answer is its own first-class state (no_backup_jobs_observed, shared
 * vocabulary with CometReadOnlyToolset, psa-z30dv), because "the server has
 * never seen a backup here" must not render as an ok-shaped empty history.
 */
class CometJobServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'hostname' => 'ACME-SRV-01',
            'comet_username' => 'acme-backup',
            'comet_device_id' => 'dev-1',
        ], $overrides));
    }

    /** Vendor-shaped job fixture; defaults to a fresh successful backup. */
    private function job(array $attrs = []): \Comet\BackupJobDetail
    {
        $job = new \Comet\BackupJobDetail;
        $job->Username = $attrs['username'] ?? 'acme-backup';
        $job->DeviceID = $attrs['device'] ?? 'dev-1';
        $job->Classification = $attrs['classification'] ?? \Comet\Def::JOB_CLASSIFICATION_BACKUP;
        $job->Status = $attrs['status'] ?? \Comet\Def::JOB_STATUS_STOP_SUCCESS;
        $job->StartTime = $attrs['start'] ?? now()->subHours(6)->timestamp;
        $job->EndTime = $attrs['end'] ?? now()->subHours(5)->timestamp;
        $job->TotalSize = $attrs['total_size'] ?? 1000;
        $job->UploadSize = $attrs['upload_size'] ?? 500;
        $job->TotalFiles = $attrs['total_files'] ?? 10;

        return $job;
    }

    /** @param array<int, \Comet\BackupJobDetail> $jobs */
    private function service(array $jobs): CometJobService
    {
        $client = $this->mock(CometClient::class);
        $client->shouldReceive('getJobsForUser')->andReturn($jobs);

        return new CometJobService($client);
    }

    public function test_every_vendor_failure_subtype_gets_a_real_label_and_failed_category(): void
    {
        $expected = [
            \Comet\Def::JOB_STATUS_FAILED_TIMEOUT => 'Failed (timeout)',
            \Comet\Def::JOB_STATUS_FAILED_WARNING => 'Completed with warnings',
            \Comet\Def::JOB_STATUS_FAILED_ERROR => 'Failed (error)',
            \Comet\Def::JOB_STATUS_FAILED_QUOTA => 'Failed (quota exceeded)',
            \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED => 'Failed (missed schedule)',
            \Comet\Def::JOB_STATUS_FAILED_CANCELLED => 'Cancelled',
            \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING => 'Skipped (already running)',
            \Comet\Def::JOB_STATUS_FAILED_ABANDONED => 'Abandoned',
        ];

        $start = now()->subHour()->timestamp;
        $jobs = array_map(
            fn (int $status) => $this->job(['status' => $status, 'start' => $start]),
            array_keys($expected),
        );

        $result = $this->service($jobs)->getRecentJobs($this->asset());

        $this->assertSame('ok', $result['state']);
        $this->assertNotNull($result['jobs_checked_at']);
        $this->assertCount(count($expected), $result['jobs']);
        foreach ($result['jobs'] as $row) {
            $this->assertSame($expected[$row['status_code']], $row['status']);
            $this->assertSame('failed', $row['category']);
        }
    }

    public function test_sdk_scale_classifications_are_labelled(): void
    {
        $start = now()->subHour()->timestamp;
        $result = $this->service([
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_BACKUP, 'start' => $start]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RESTORE, 'start' => $start - 1]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION, 'start' => $start - 2]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_DEEPVERIFY, 'start' => $start - 3]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_DELETE_CUSTOM, 'start' => $start - 4]),
        ])->getRecentJobs($this->asset());

        $this->assertSame(
            ['Backup', 'Restore', 'Retention', 'Deep verify', 'Delete (custom)'],
            array_column($result['jobs'], 'classification'),
        );
    }

    public function test_an_out_of_catalog_classification_says_interpretation_failed_not_other(): void
    {
        // 'Other' reads as benign; an unrecognised code must say so.
        $result = $this->service([
            $this->job(['classification' => 9999, 'start' => now()->subHour()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertSame('Unrecognized job type (code 9999)', $result['jobs'][0]['classification']);
    }

    public function test_legacy_scale_classification_4_counts_as_backup_for_posture(): void
    {
        // Dual-scale (psa-enpew): posture tracking must recognise backup on
        // the legacy small-int scale too, not just SDK 4001.
        $result = $this->service([
            $this->job(['classification' => 4, 'status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'start' => now()->subHour()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertNotNull($result['last_failure'], 'legacy-scale backup failures must register');
        $this->assertSame('Backup', $result['jobs'][0]['classification']);
    }

    public function test_last_failure_catches_failure_subtypes_beyond_7002(): void
    {
        // Old code tracked last_failure on === 7002 only: a device whose backups
        // die of quota/timeout/missed-schedule showed NO failure at all.
        $result = $this->service([
            $this->job(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'start' => now()->subDays(3)->timestamp]),
            $this->job(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'start' => now()->subDay()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertNotNull($result['last_failure']);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_QUOTA, $result['last_failure']['status_code']);
        $this->assertSame('Failed (quota exceeded)', $result['last_failure']['status']);
    }

    public function test_retention_and_restore_outcomes_never_mask_backup_posture(): void
    {
        // A successful retention pass an hour after a failed backup must not
        // read as "backups are fine" (the psa-z30dv retention-masking trap).
        $result = $this->service([
            $this->job(['status' => \Comet\Def::JOB_STATUS_FAILED_ERROR, 'start' => now()->subHours(3)->timestamp]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION, 'start' => now()->subHours(2)->timestamp]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RESTORE, 'start' => now()->subHour()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertNull($result['last_success'], 'retention/restore successes are not backup successes');
        $this->assertNotNull($result['last_failure']);
        $this->assertSame(\Comet\Def::JOB_STATUS_FAILED_ERROR, $result['last_failure']['status_code']);
        $this->assertCount(3, $result['jobs'], 'the job list itself still shows all classifications');
    }

    public function test_unknown_and_unnamed_status_codes_fall_back_by_range_never_to_success(): void
    {
        $start = now()->subHour()->timestamp;
        $result = $this->service([
            $this->job(['status' => 5001, 'start' => $start]),      // unnamed, success range
            $this->job(['status' => 6004, 'start' => $start - 1]),  // NOT_YET_STARTED, running range
            $this->job(['status' => 7042, 'start' => $start - 2]),  // unnamed, failed range
            $this->job(['status' => 4242, 'start' => $start - 3]),  // outside every range
        ])->getRecentJobs($this->asset());

        $rows = array_combine(array_column($result['jobs'], 'status_code'), $result['jobs']);

        $this->assertSame(['Success (code 5001)', 'success'], [$rows[5001]['status'], $rows[5001]['category']]);
        $this->assertSame(['Running', 'running'], [$rows[6004]['status'], $rows[6004]['category']]);
        $this->assertSame(['Failed (code 7042)', 'failed'], [$rows[7042]['status'], $rows[7042]['category']]);
        $this->assertSame(['Unknown (code 4242)', 'unknown'], [$rows[4242]['status'], $rows[4242]['category']]);

        // Range-derived posture: the unnamed success/failed codes still count.
        $this->assertSame(5001, $result['last_success']['status_code']);
        $this->assertSame(7042, $result['last_failure']['status_code']);
    }

    public function test_success_label_matches_the_backup_read_toolset_vocabulary(): void
    {
        // One backup-state vocabulary across surfaces (psa-z30dv): 'Success',
        // not 'Completed'.
        $result = $this->service([
            $this->job(['start' => now()->subHour()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertSame('Success', $result['jobs'][0]['status']);
    }

    public function test_job_rows_no_longer_carry_the_dead_error_field(): void
    {
        // BackupJobDetail has no FileErrors property (vendor SDK), so the old
        // 'error' key could never hold anything but null. It is gone, not lying.
        $result = $this->service([$this->job(['start' => now()->subHour()->timestamp])])
            ->getRecentJobs($this->asset());

        $this->assertArrayNotHasKey('error', $result['jobs'][0]);
    }

    // ── Availability contract: degraded reads scream, never clean-empty ──────

    public function test_a_failed_live_read_returns_unavailable_not_a_clean_empty_history(): void
    {
        $client = $this->mock(CometClient::class);
        $client->shouldReceive('getJobsForUser')->andThrow(new CometClientException('connection refused'));

        $result = (new CometJobService($client))->getRecentJobs($this->asset());

        $this->assertSame('unavailable', $result['state']);
        $this->assertNotNull($result['jobs_checked_at']);
        $this->assertSame([], $result['jobs']);
        $this->assertNull($result['last_success']);
        $this->assertNull($result['last_failure']);
    }

    public function test_an_asset_without_a_comet_username_is_not_queried(): void
    {
        $asset = $this->asset(['comet_username' => null]);

        $client = $this->mock(CometClient::class);
        $client->shouldNotReceive('getJobsForUser');

        $result = (new CometJobService($client))->getRecentJobs($asset);

        $this->assertSame('not_queried', $result['state']);
        $this->assertNull($result['jobs_checked_at']);
        $this->assertSame([], $result['jobs']);
    }

    public function test_a_genuinely_empty_history_is_no_backup_jobs_observed_not_a_clean_ok(): void
    {
        // The server really answered and reported nothing — that is a
        // first-class state (backups may never have run), in the same
        // vocabulary as comet_get_backup_posture (psa-z30dv), never an
        // ok-shaped empty history.
        $result = $this->service([])->getRecentJobs($this->asset());

        $this->assertSame('no_backup_jobs_observed', $result['state']);
        $this->assertNotNull($result['jobs_checked_at'], 'a lookup ran, so its time is retained');
        $this->assertSame([], $result['jobs']);
    }

    public function test_a_history_with_only_non_backup_jobs_is_still_no_backup_jobs_observed(): void
    {
        // Retention/restore activity proves the device talks to the server —
        // it proves nothing about backups. The rows still list.
        $result = $this->service([
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RETENTION, 'start' => now()->subHour()->timestamp]),
            $this->job(['classification' => \Comet\Def::JOB_CLASSIFICATION_RESTORE, 'start' => now()->subHours(2)->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertSame('no_backup_jobs_observed', $result['state']);
        $this->assertCount(2, $result['jobs'], 'non-backup rows still list');
        $this->assertNull($result['last_success']);
        $this->assertNull($result['last_failure']);
    }

    public function test_job_timestamps_are_iso8601_utc_for_unambiguous_relay(): void
    {
        // Display surfaces convert via ->toAppTz() (CLAUDE.md); tool surfaces
        // relay the UTC form — the old bare date() strings carried no zone.
        $result = $this->service([
            $this->job(['start' => now()->subHour()->timestamp]),
        ])->getRecentJobs($this->asset());

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['jobs'][0]['started']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['jobs'][0]['ended']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['jobs_checked_at']);
    }

    // ── Asset detail page rendering ───────────────────────────────────────────

    public function test_asset_detail_page_renders_range_based_status_badges(): void
    {
        // End-to-end through AssetController + the Blade badge matcher, which
        // keys off the row's range category (and \Comet\Def) instead of
        // exact label strings. Running gets dark text on the cyan badge (WCAG).
        $user = \App\Models\User::factory()->create();
        $asset = $this->asset();
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->job(['status' => \Comet\Def::JOB_STATUS_FAILED_QUOTA, 'start' => now()->subHour()->timestamp]),
            $this->job(['status' => \Comet\Def::JOB_STATUS_FAILED_WARNING, 'start' => now()->subHours(2)->timestamp]),
            $this->job(['status' => \Comet\Def::JOB_STATUS_RUNNING_ACTIVE, 'start' => now()->subMinutes(10)->timestamp, 'end' => 0]),
            $this->job(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'start' => now()->subHours(3)->timestamp]),
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', $asset));

        $response->assertOk();
        $response->assertSeeText('Failed (quota exceeded)');
        $response->assertSeeText('Completed with warnings');
        $response->assertSee('bg-warning text-dark', false);
        $response->assertSee('bg-info text-dark', false);
        $response->assertSeeText('Last failure:');
    }

    public function test_asset_detail_page_says_unavailable_when_the_live_read_fails(): void
    {
        // "No recent backup jobs found" on a failed read is a false all-clear.
        $user = \App\Models\User::factory()->create();
        $asset = $this->asset();
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')
            ->andThrow(new CometClientException('timeout'));

        $response = $this->actingAs($user)->get(route('assets.show', $asset));

        $response->assertOk();
        $response->assertSeeText('Backup job history unavailable');
        $response->assertDontSeeText('No backup jobs observed');
    }

    public function test_asset_detail_page_distinguishes_a_genuinely_empty_history(): void
    {
        $user = \App\Models\User::factory()->create();
        $asset = $this->asset();
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([]);

        $response = $this->actingAs($user)->get(route('assets.show', $asset));

        $response->assertOk();
        $response->assertSeeText('The Comet server returned no backup jobs for this device');
        $response->assertSeeText('No jobs is not evidence of success');
        $response->assertDontSeeText('Backup job history unavailable');
    }

    public function test_asset_detail_page_points_at_older_activity_when_the_recent_window_is_empty(): void
    {
        // Backup jobs exist but none in the default 7-day window: that is
        // NOT "no backup jobs observed" — the copy points at the older
        // activity instead of an all-clear-shaped empty card.
        $user = \App\Models\User::factory()->create();
        $asset = $this->asset();
        $this->mock(CometClient::class)->shouldReceive('getJobsForUser')->andReturn([
            $this->job(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'start' => now()->subDays(30)->timestamp, 'end' => now()->subDays(30)->addHour()->timestamp]),
        ]);

        $response = $this->actingAs($user)->get(route('assets.show', $asset));

        $response->assertOk();
        $response->assertSeeText('No jobs in the last 7 days');
        $response->assertSeeText('Last success:');
        $response->assertDontSeeText('The Comet server returned no backup jobs for this device');
    }

    public function test_device_filter_and_recency_cutoff_still_apply(): void
    {
        $result = $this->service([
            $this->job(['device' => 'someone-elses-device', 'start' => now()->subHour()->timestamp]),
            $this->job(['status' => \Comet\Def::JOB_STATUS_STOP_SUCCESS, 'start' => now()->subDays(30)->timestamp]),
        ])->getRecentJobs($this->asset(), 7);

        $this->assertSame([], $result['jobs'], 'foreign devices and stale jobs stay out of the recent list');
        $this->assertNotNull($result['last_success'], 'last success is tracked across all time regardless of the window');
    }
}
