<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Huntress\HuntressWebhookDecoder;
use App\Support\HuntressConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Svix\Exception\WebhookVerificationException;
use Svix\Webhook;

class HuntressWebhookController extends Controller
{
    public function __invoke(Request $request, HuntressWebhookDecoder $decoder)
    {
        $secret = HuntressConfig::get('webhook_signing_secret');
        $encodedSecret = str_starts_with($secret ?? '', 'whsec_') ? substr($secret, 6) : ($secret ?? '');
        $decodedSecret = base64_decode($encodedSecret, true);
        if (! HuntressConfig::webhooksEnabled() || $decodedSecret === false || $decodedSecret === '') {
            return response()->json(['error' => 'Unavailable'], 503);
        }
        $body = $request->getContent();
        // Match Svix's complete-family precedence; never mix signed fields or
        // persist an ID from a different family than the verifier selected.
        $headers = [];
        foreach (['svix', 'webhook'] as $family) {
            foreach (['id', 'timestamp', 'signature'] as $field) {
                $name = $family.'-'.$field;
                $headers[$name] = $request->header($name);
            }
        }
        $family = isset($headers['svix-id'], $headers['svix-timestamp'], $headers['svix-signature'])
            ? 'svix' : 'webhook';
        $delivery = $headers[$family.'-id'] ?? '';
        if (! preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $delivery)) {
            return response()->json(['error' => 'Invalid delivery'], 400);
        }
        try {
            (new Webhook($secret))->verify($body, $headers);
        } catch (WebhookVerificationException) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        try {
            $event = $decoder->decode($body);
        } catch (\JsonException|\InvalidArgumentException) {
            return response()->json(['error' => 'Invalid event'], 422);
        }
        if ($event === null) {
            // Deliberately do not log vendor body, headers or event contents.
            Log::info('Huntress webhook unhandled', ['delivery_id' => $delivery]);

            return response()->json(['received' => true]);
        }
        $hash = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
        $row = $event;
        $row['organization_ids'] = json_encode($event['organization_ids'], JSON_THROW_ON_ERROR);
        $row['delivery_id'] = $delivery;
        $row['content_hash'] = $hash;
        $row['received_at'] = now();
        // The unique delivery key arbitrates concurrent retries. Roll back the
        // failed insert transaction before reading the winning delivery.
        try {
            DB::transaction(fn () => DB::table('huntress_webhook_events')->insert($row));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $existing = DB::table('huntress_webhook_events')->where('delivery_id', $delivery)->first();
            if (! $existing) {
                throw $e;
            }
            if (! hash_equals($existing->content_hash, $hash)) {
                return response()->json(['error' => 'Conflicting delivery'], 409);
            }
        }

        // Event durability owns the response, not the promotion outcome. A replay
        // retries promotion; the polling fallback also repairs a failed promotion.
        try {
            app(\App\Services\Huntress\HuntressLinkService::class)->promote($event['record_type'], $event['record_id']);
        } catch (\Throwable) {
            Log::warning('Huntress link promotion failed', ['delivery_id' => $delivery]);
        }

        return response()->json(['received' => true]);
    }
}
