<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The write surface Leif RMM uses to report coverage drift.
 *
 * Separate from RmmController, which is read-only by design and says so: the PSA
 * owns client identity and the RMM never writes it back. An alert is not
 * identity — it is the same kind of traffic the Tactical, Comet and Ninja
 * webhooks already carry, and it gets its own controller for the same reason
 * they do.
 *
 * ALERTS, NOT TICKETS. The RMM raises an alert and a human promotes it from the
 * alerts screen. A ticket is a commitment to bill and to work; opening one
 * automatically for every coverage transition is how a queue becomes noise.
 *
 * Deliberately thin. Deduplication, re-fire counting and settling an attached
 * ticket all belong to AlertService and are already written; reimplementing any
 * of it here would create a second definition of "the same alert".
 */
class RmmAlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    /**
     * POST /api/rmm/alerts
     *
     * Raise a new alert, or re-fire the one already open under this
     * source_alert_id. The RMM sends one per (client, requirement), so a client
     * whose Huntress coverage keeps changing accumulates re-fires on a single
     * alert rather than a row per hour.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `exists` is the refusal that matters: an alert filed against the
            // wrong client is worse than no alert at all.
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'source_alert_id' => ['required', 'string', 'max:191'],
            'severity' => ['required', 'string', 'in:'.implode(',', array_column(AlertSeverity::cases(), 'value'))],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'fired_at' => ['nullable', 'date'],
        ]);

        // AlertService::upsert only looks for an alert in Active, Acknowledged or
        // Ticketed status when deciding whether to re-fire. This check is
        // deliberately broader — any alert under this key, resolved ones
        // included — so that `created` below is false only when nothing at all
        // existed under the key. If a resolved alert exists and upsert creates a
        // new one, `created` reports false even though a new row appears; the
        // caller can still tell from `status` and `refired_count`.
        $existing = Alert::where('source', AlertSource::LeifRmm)
            ->where('source_alert_id', $data['source_alert_id'])
            ->exists();

        $alert = $this->alerts->upsert(
            AlertSource::LeifRmm,
            $data['source_alert_id'],
            [
                'client_id' => $data['client_id'],
                'severity' => AlertSeverity::from($data['severity']),
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'hostname' => $data['hostname'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'fired_at' => $data['fired_at'] ?? now(),
            ],
        );

        return response()->json([
            'alert_id' => $alert->id,
            // `created` is reported so the RMM can log "raised" vs "re-fired"
            // without inferring it from refired_count, which is 0 for both a new
            // alert and one whose first re-fire has not happened yet.
            'created' => ! $existing,
            'status' => $alert->status->value,
            'refired_count' => (int) $alert->refired_count,
        ]);
    }
}
