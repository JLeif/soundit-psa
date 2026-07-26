<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Comet\CometAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Comet Server webhook events. Per the vendored SDK the POST body is
 * a StreamableEvent (vendor/cometbackup/comet-php-sdk/Comet/StreamableEvent.php):
 * Type is an INTEGER SEVT_ code — Def.php:2115 SEVT_JOB_COMPLETED = 4201,
 * "Data is the job object" (a serialized BackupJobDetail).
 *
 * Honest limit (psa-enpew): the previous pipeline (string Type match +
 * exact-code guards downstream) processed zero events in ~4.5 months of real
 * traffic, but no captured live payload establishes WHICH guard rejected it.
 * This handler therefore routes deliberately at every step and never drops
 * silently — and never claims otherwise: the response `status` is the
 * service's actual disposition (alert_created / alert_resolved /
 * ignored_non_backup / dropped_unmatched / …), so a dropped event can no
 * longer be reported as processed. HTTP stays 200 for authenticated,
 * well-formed transport (Comet only reads the status line); the JSON is the
 * honest record. Recognised-but-unhandled SEVT families log at DEBUG,
 * anything outside the SEVT catalog logs at WARNING, and the settings stamps
 * give operators a real-traffic proof that recognition is actually working.
 */
class CometWebhookController extends Controller
{
    public function __construct(
        private readonly CometAlertService $alertService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        // Operator-visible delivery proof: any authenticated event arrived.
        // "Recognized" is NOT stamped here — the service stamps it only when
        // a job payload actually routes, so this stamp advancing while that
        // one stalls is the operator's signal that matching broke (psa-enpew).
        Setting::setValue('comet_webhook_last_received_at', now()->toIso8601String());

        Log::debug('[Comet Webhook] Received', [
            'type' => $data['Type'] ?? 'unknown',
            'type_string' => $data['TypeString'] ?? null,
        ]);

        try {
            $type = $data['Type'] ?? null;

            if ($type === \Comet\Def::SEVT_JOB_COMPLETED) {
                $jobData = $data['Data'] ?? null;

                if (! is_array($jobData)) {
                    $this->alertService->recordUnmatchedEvent('missing_job_data', [
                        'classification' => null,
                        'status' => null,
                        'has_device_id' => false,
                        'has_source_guid' => false,
                        'has_destination_guid' => false,
                    ]);

                    return response()->json([
                        'status' => 'dropped_unmatched',
                        'alert_id' => null,
                        'reason' => 'missing_job_data',
                    ]);
                }

                $outcome = $this->alertService->handleJobCompleted($jobData);

                $body = [
                    'status' => $outcome->disposition->value,
                    'alert_id' => $outcome->alert?->id,
                ];
                if ($outcome->reason !== null) {
                    $body['reason'] = $outcome->reason;
                }

                return response()->json($body);
            }

            if (is_int($type) && $type >= \Comet\Def::SEVT__MIN && $type <= \Comet\Def::SEVT__MAX) {
                // A real SEVT family we deliberately do not handle (job-new,
                // account/bucket/server/tenant/policy events, meta hello…).
                Log::debug('[Comet Webhook] Ignoring unhandled SEVT event family', ['type' => $type]);

                return response()->json(['status' => 'ignored_unhandled_event_type']);
            }

            // Not a shape the vendor catalog defines (e.g. a string Type) —
            // never drop that silently; it is exactly how psa-enpew hid.
            Log::warning('[Comet Webhook] Unrecognized event Type, ignoring', [
                'type' => is_scalar($type) ? $type : gettype($type),
                'type_string' => is_scalar($data['TypeString'] ?? null) ? $data['TypeString'] : null,
            ]);

            return response()->json(['status' => 'ignored_unrecognized_event_type']);

        } catch (\Exception $e) {
            Log::error('[Comet Webhook] Error processing webhook', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Generic body only — exception text can leak schema/SQL details
            // to any holder of the webhook credential.
            return response()->json(['status' => 'error', 'message' => 'Internal error processing webhook.'], 500);
        }
    }
}
