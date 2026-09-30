<?php

namespace App\Services\AppRiver;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * "Sync Licenses Now" (card 6abc5913 / EKnz4VSM): a manual sync that can finish,
 * and that reports how it finished.
 *
 * WHY NOT THE QUEUE. The old button did Artisan::queue('appriver:sync-licenses').
 * The prod worker runs `queue:work database --tries=2 --timeout=30`; a queued
 * artisan command carries no timeout of its own (QueuedCommand has no $timeout,
 * so the payload's timeout is null) and Worker::timeoutForJob() falls back to
 * the worker's 30s, which a full licence sync exceeds -> TimeoutExceededException.
 * A job with its own $timeout (say 600) WOULD escape the 30s alarm — the job's
 * timeout wins in timeoutForJob() — but it is still wrong on that connection:
 * DatabaseQueue::isReservedButExpired() hands out any job reserved longer than
 * retry_after (90s, config/queue.php), so a second worker process would start
 * a duplicate sync mid-run, and --tries=2 would then fail it; and a single
 * worker would hold every other queued job (portal mail, transcription, triage)
 * behind a multi-minute sync. Laravel requires retry_after > timeout; making
 * that true is a production worker/env change, which this change does not make.
 *
 * So the sync runs OUTSIDE the worker, as a detached CLI artisan process (the
 * pattern syncCippContacts()/syncCippDevices() already use): no worker timeout,
 * no retry_after, CLI max_execution_time is 0. The command, run with --manual,
 * records its own outcome here, and the Integrations page renders it.
 */
class AppRiverManualSync
{
    /** JSON: {state, started_at, finished_at, summary}. */
    public const SETTING = 'appriver_manual_sync';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCESS = 'success';

    public const STATE_FAILED = 'failed';

    /** A run still "running" after this long is reported as having no outcome. */
    public const STALE_RUNNING_MINUTES = 30;

    public const COMMAND = 'appriver:sync-licenses --manual';

    /**
     * Start a detached manual sync. Returns false (and starts nothing) when one
     * is already running.
     */
    public function start(): bool
    {
        $current = self::status();
        if ($current !== null && $current['state'] === self::STATE_RUNNING && ! $current['overdue']) {
            return false;
        }

        $this->write(self::STATE_RUNNING, 'Started from Settings > Integrations > AppRiver.', finished: false);

        $artisan = base_path('artisan');
        Process::path(base_path())->start(
            'php '.escapeshellarg($artisan).' '.self::COMMAND.' >> '.escapeshellarg(storage_path('logs/laravel.log')).' 2>&1 &'
        );

        return true;
    }

    public function recordSuccess(string $summary): void
    {
        $this->write(self::STATE_SUCCESS, $summary, finished: true);
    }

    public function recordFailure(string $reason): void
    {
        Log::error('[AppRiverSync] Manual sync failed: '.$reason);
        $this->write(self::STATE_FAILED, $reason, finished: true);
    }

    /**
     * The last manual run, or null when none was ever started.
     *
     * @return array{state: string, started_at: ?string, finished_at: ?string, summary: string, overdue: bool}|null
     */
    public static function status(): ?array
    {
        $raw = Setting::getValue(self::SETTING);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! is_string($data['state'] ?? null)) {
            return null;
        }

        $startedAt = is_string($data['started_at'] ?? null) ? $data['started_at'] : null;
        $overdue = $data['state'] === self::STATE_RUNNING
            && ($startedAt === null || now()->parse($startedAt)->lt(now()->subMinutes(self::STALE_RUNNING_MINUTES)));

        return [
            'state' => $data['state'],
            'started_at' => $startedAt,
            'finished_at' => is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            'summary' => (string) ($data['summary'] ?? ''),
            'overdue' => $overdue,
        ];
    }

    private function write(string $state, string $summary, bool $finished): void
    {
        $previous = self::status();
        Setting::setValue(self::SETTING, json_encode([
            'state' => $state,
            'started_at' => $finished ? ($previous['started_at'] ?? null) : now()->toIso8601String(),
            'finished_at' => $finished ? now()->toIso8601String() : null,
            'summary' => Str::limit($summary, 500),
        ]));
    }
}
