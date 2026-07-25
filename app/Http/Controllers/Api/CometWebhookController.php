<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comet\CometAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Comet Server webhook events. The POST body is a StreamableEvent
 * (vendor/cometbackup/comet-php-sdk/Comet/StreamableEvent.php): Type is an
 * INTEGER SEVT_ code — Def.php:2115 SEVT_JOB_COMPLETED = 4201, "Data is the
 * job object" (a serialized BackupJobDetail). The previous string match on
 * 'job.completed' matched nothing Comet ever sends, so every real webhook
 * event was ignored at this gate (psa-enpew).
 */
class CometWebhookController extends Controller
{
    public function __construct(
        private readonly CometAlertService $alertService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->json()->all();

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

                $alert = $this->alertService->handleJobCompleted($jobData);

                return response()->json([
                    'status' => 'processed',
                    'alert_id' => $alert?->id,
                ]);
            }

            Log::debug('[Comet Webhook] Ignoring event type', ['type' => $type]);

            return response()->json(['status' => 'ignored']);

        } catch (\Exception $e) {
            Log::error('[Comet Webhook] Error processing webhook', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
