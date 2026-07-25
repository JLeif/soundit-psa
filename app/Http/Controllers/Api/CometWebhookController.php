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
 * silently: recognised-but-unhandled SEVT families log at DEBUG, anything
 * outside the SEVT catalog logs at WARNING, and the settings stamps below
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
                    Log::warning('[Comet Webhook] Job-completed event without job Data payload');

                    return response()->json(['status' => 'ignored']);
                }

                // Proof that event-type configuration is right: a job event
                // was recognised and routed (whether or not it alerts).
                Setting::setValue('comet_webhook_last_recognized_at', now()->toIso8601String());

                $alert = $this->alertService->handleJobCompleted($jobData);

                return response()->json([
                    'status' => 'processed',
                    'alert_id' => $alert?->id,
                ]);
            }

            if (is_int($type) && $type >= \Comet\Def::SEVT__MIN && $type <= \Comet\Def::SEVT__MAX) {
                // A real SEVT family we deliberately do not handle (job-new,
                // account/bucket/server/tenant/policy events, meta hello…).
                Log::debug('[Comet Webhook] Ignoring unhandled SEVT event family', ['type' => $type]);

                return response()->json(['status' => 'ignored']);
            }

            // Not a shape the vendor catalog defines (e.g. a string Type) —
            // never drop that silently; it is exactly how psa-enpew hid.
            Log::warning('[Comet Webhook] Unrecognized event Type, ignoring', [
                'type' => is_scalar($type) ? $type : gettype($type),
                'type_string' => is_scalar($data['TypeString'] ?? null) ? $data['TypeString'] : null,
            ]);

            return response()->json(['status' => 'ignored']);

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
