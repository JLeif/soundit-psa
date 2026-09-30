<?php

namespace App\Services\AppRiver;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use App\Services\AlertService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Operator alarm for a dropped AppRiver login (card 6abc5913 / EKnz4VSM).
 *
 * AppRiverClient::handleRefreshFailure() clears dead credentials, and the daily
 * appriver:sync-licenses schedule is gated on isConnected(), so a dropped login
 * used to stop the sync SILENTLY — 12 days of stale seat counts under billing
 * before anyone noticed. This class makes that state loud, reusing the Alerts
 * Hub (AlertService) and the CIPP MCP connector's episode pattern:
 *
 *   - ONE open alert per dropped-login episode. The episode is claimed with a
 *     conditional UPDATE on a null marker (or the unique-key INSERT of it),
 *     never read-then-write, so two concurrent failures open one episode.
 *   - Later sightings in the same episode REFRESH that alert (refired_count),
 *     they never create a second one.
 *   - The episode ends on the next successful token store (reconnect or refresh),
 *     which resolves the alert.
 *
 * Every public method is bookkeeping around a credential path and NEVER throws:
 * a failing step is logged by exception CLASS only (a QueryException message
 * carries SQL bindings, which may hold an encrypted token) — CIPP ruling C.
 */
class AppRiverLoginMonitor
{
    /** Set while a dropped-login episode is open; null/absent otherwise. */
    public const DROPPED_AT = 'appriver_login_dropped_at';

    /** Id of the Alerts Hub row raised for the open episode. */
    public const ALERT_ID = 'appriver_login_alert_id';

    /** Where the operator fixes it; named verbatim in the alert. */
    public const RECONNECT_ACTION = 'Settings > Integrations > AppRiver';

    /**
     * Previously connected, now without a token: the state the daily schedule used
     * to skip in silence. appriver_connected_at survives disconnect() on purpose
     * (see its docblock), so it is the witness that a login once existed.
     */
    public static function isDropped(): bool
    {
        return (string) Setting::getValue('appriver_connected_at', '') !== ''
            && ! AppRiverClient::isConnected();
    }

    /**
     * The daily 05:50 schedule's filter. Same answer as the old inline gate
     * (connected AND at least one mapped client), but a previously-connected,
     * now-disconnected integration is logged under [AppRiverSync] and raises or
     * refreshes the alert instead of skipping without a word.
     */
    public static function scheduledSyncShouldRun(): bool
    {
        if (! AppRiverClient::isConnected()) {
            self::guard('scheduleDroppedCheck', function (): void {
                if (self::isDropped()) {
                    Log::warning('[AppRiverSync] Scheduled sync skipped: AppRiver was connected but has no stored token (login dropped). Reconnect in '.self::RECONNECT_ACTION.'.');
                    (new self)->recordLoginDropped('scheduled sync found no stored token');
                }
            });

            return false;
        }

        return Client::whereNotNull('appriver_customer_id')->exists();
    }

    /**
     * The login dropped (credentials cleared, or found absent after a connect).
     * First sighting of an episode raises ONE alert; later ones refresh it.
     */
    public function recordLoginDropped(string $reason): void
    {
        $reason = Str::limit($reason, 200, '');
        $opened = false;
        self::guard('openEpisode', function () use (&$opened): void {
            $opened = $this->openEpisode();
        });

        self::guard($opened ? 'raiseAlert' : 'refreshAlert', function () use ($opened, $reason): void {
            $alert = $opened ? null : $this->openAlert();

            if ($alert !== null) {
                // Same source_alert_id → AlertService re-fires the open row.
                app(AlertService::class)->upsert(AlertSource::AppRiver, $alert->source_alert_id, [
                    'severity' => AlertSeverity::Error,
                    'title' => $alert->title,
                    'message' => $alert->message,
                    'metadata' => ['last_reason' => $reason, 'last_seen_at' => now()->toIso8601String()],
                ]);

                return;
            }

            // A new episode, or the operator resolved the alert by hand while the
            // login is still dropped: raise one (the only open row for this episode).
            $alert = app(AlertService::class)->upsert(AlertSource::AppRiver, 'appriver-login-dropped:'.Str::ulid(), [
                'severity' => AlertSeverity::Error,
                'title' => 'AppRiver login dropped: reconnect required',
                'message' => 'PSA no longer holds a working AppRiver login ('.$reason.'). '
                    .'The daily licence sync cannot run, so M365 seat counts used for billing will go stale. '
                    .'Reconnect in '.self::RECONNECT_ACTION.'.',
                'metadata' => ['reason' => $reason, 'action' => self::RECONNECT_ACTION],
            ]);
            Setting::setValue(self::ALERT_ID, (string) $alert->id);
        });
    }

    /**
     * Tokens were stored (reconnect or successful refresh): end the episode and
     * resolve its alert. Cheap no-op when no episode is open.
     */
    public function recordConnected(): void
    {
        self::guard('clearEpisode', function (): void {
            if (Setting::getValue(self::DROPPED_AT) !== null) {
                Setting::setValue(self::DROPPED_AT, null);
            }
        });

        self::guard('resolveAlert', function (): void {
            $alertId = Setting::getValue(self::ALERT_ID);
            if ($alertId !== null && $alertId !== '') {
                $alert = Alert::find((int) $alertId);
                if ($alert !== null) {
                    app(AlertService::class)->resolve($alert, 'AppRiver reconnected; licence sync can run again.');
                }
                Setting::setValue(self::ALERT_ID, null);
            }
        });
    }

    /** The episode's alert, when it is still open in the Alerts Hub. */
    private function openAlert(): ?Alert
    {
        $alertId = Setting::getValue(self::ALERT_ID);
        if ($alertId === null || $alertId === '') {
            return null;
        }

        return Alert::whereKey((int) $alertId)
            ->where('source', AlertSource::AppRiver)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();
    }

    /**
     * Atomically start an episode: true for exactly one caller per episode.
     */
    private function openEpisode(): bool
    {
        $now = now()->toIso8601String();
        if (Setting::where('key', self::DROPPED_AT)->whereNull('value')->update(['value' => $now]) === 1) {
            return true;
        }
        if (Setting::where('key', self::DROPPED_AT)->exists()) {
            return false;
        }

        try {
            Setting::create(['key' => self::DROPPED_AT, 'value' => $now]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private static function guard(string $step, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            try {
                Log::error('[AppRiver] Login-alert bookkeeping step failed; continuing', [
                    'step' => $step,
                    'exception' => class_basename($e),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
