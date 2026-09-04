<?php

namespace App\Http\Middleware;

use App\Support\RmmConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the Leif RMM integration surface.
 *
 * Deliberately NOT the `web` + `auth` middleware every other client route
 * carries. Those are session cookies plus CSRF, which is correct for this
 * application's own UI and unusable by a service: there is no credential a
 * server-to-server caller could hold that would satisfy them.
 *
 * That is also why these routes are their own surface rather than a reuse of the
 * UI's. A UI endpoint borrowed as a contract changes whenever the UI does, and
 * the consumer finds out in production.
 */
class VerifyRmmApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = RmmConfig::get('api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            // Not configured is not the same as wrong credentials, and the log
            // has to say which - otherwise a missing setting is diagnosed for an
            // hour as an authentication problem.
            Log::warning('[RMM API] rmm_api_key is not configured; refusing');

            return $this->unauthorized();
        }

        $presented = $this->bearerToken($request);

        if ($presented === null) {
            Log::warning('[RMM API] Missing or malformed Authorization header', ['ip' => $request->ip()]);

            return $this->unauthorized();
        }

        // hash_equals, not ===. String comparison short-circuits on the first
        // differing byte, which leaks the shared secret one character at a time
        // to anyone able to time the responses.
        if (! hash_equals($apiKey, $presented)) {
            Log::warning('[RMM API] API key mismatch', ['ip' => $request->ip()]);

            return $this->unauthorized();
        }

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! is_string($header) || ! preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }

        $token = trim($m[1]);

        return $token === '' ? null : $token;
    }

    /**
     * One response for every refusal.
     *
     * Missing, malformed and wrong all answer identically, so the endpoint
     * cannot be used to work out whether a guessed key was close, or whether the
     * integration is configured at all.
     */
    private function unauthorized(): Response
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
