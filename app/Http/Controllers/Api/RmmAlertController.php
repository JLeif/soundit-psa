<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
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

        // The `integer` rule accepts a numeric string ("5") without casting
        // it; the model attribute below comes back as a native int. Normalise
        // here once so every comparison and write below is a real int, not a
        // string that happens to look like one.
        $data['client_id'] = (int) $data['client_id'];

        // One query answers two questions: whether anything already exists
        // under this key at all (for `created` below - deliberately broader
        // than AlertService::upsert's own Active/Acknowledged/Ticketed lookup,
        // so `created` is false only when nothing at all existed under the
        // key) and, if something does, whether it belongs to a different
        // client (for the guard below).
        $anyUnderKey = Alert::where('source', AlertSource::LeifRmm)
            ->where('source_alert_id', $data['source_alert_id'])
            ->first();

        // AlertService::upsert matches purely on source + source_alert_id, with
        // no client scoping. Its re-fire branch never updates client_id, but
        // its revive branch (a resolved row recurring under the same key)
        // does - that's exactly the hazard: if two different clients ever
        // posted the same source_alert_id, upsert could silently move the
        // FIRST client's resolved alert onto the second client's payload - a
        // write against the wrong client on a live billing system.
        // AlertService itself now refuses that specific move with an
        // exception (see its revive branch), but that's a loud 500 for a
        // situation this guard can turn into a clean 422 before upsert ever
        // runs. The RMM's key convention (<clientId>:<requirement>) prevents
        // the collision in practice, but that is a caller convention, not a
        // server-side invariant, so it is guarded here too. Checked against
        // ANY status, not just open ones: now that a resolved alert can be
        // revived by upsert, a resolved row under someone else's client is
        // just as much a hazard as an open one. Compared as integers - the
        // model attribute is a native int, and $data['client_id'] was
        // normalised above, but the cast stays here too as the guard's own
        // guarantee against a future caller of this branch skipping that step.
        if ($anyUnderKey !== null && (int) $anyUnderKey->client_id !== (int) $data['client_id']) {
            return response()->json([
                'message' => 'An alert already exists under this source_alert_id for a different client.',
            ], 422);
        }

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
            'created' => $anyUnderKey === null,
            'status' => $alert->status->value,
            'refired_count' => (int) $alert->refired_count,
        ]);
    }

    /**
     * POST /api/rmm/alerts/resolve
     *
     * The estate healing itself closes its own alert: when every device behind
     * an alert is `ok` again, the RMM calls this and AlertService settles the
     * alert, including any ticket already attached to it.
     *
     * An unknown or already-resolved key is a SUCCESS, not an error. The RMM
     * retries, and "nothing is open under this key" is exactly the state it was
     * asking for - a 404 would make it special-case its own success.
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_alert_id' => ['required', 'string', 'max:191'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $alert = Alert::where('source', AlertSource::LeifRmm)
            ->where('source_alert_id', $data['source_alert_id'])
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if ($alert === null) {
            return response()->json(['resolved' => false, 'alert_id' => null]);
        }

        $this->alerts->resolve($alert, $data['reason'] ?? null);

        return response()->json(['resolved' => true, 'alert_id' => $alert->id]);
    }
}
