<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Support\ApiEndpointRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the PSA REST API (/api/v1/* and the /api/rmm/* alias).
 *
 * Deny by default:
 *  - 401 {"error":"Unauthorized"} for every authentication failure (missing or
 *    malformed header, unknown token, draft, paused, revoked, expired). The body
 *    is the same for every cause, so a caller cannot tell a paused token from
 *    one that never existed.
 *  - 403 {"error":"Forbidden"} when the token is valid but the route's
 *    registry entry is not granted to it, or the route resolves to no registry
 *    entry at all.
 * The cause goes to the audit row and the server log, never the response.
 */
class VerifyApiToken
{
    /** Failed authentications allowed per IP per minute before 429. */
    public const GUESS_LIMIT_PER_MINUTE = 60;

    /** Authenticated requests allowed per token per minute before 429. */
    public const TOKEN_LIMIT_PER_MINUTE = 120;

    /** last_used_* is rewritten only when the stored value is older than this. */
    public const LAST_USED_RESOLUTION_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $endpoint = ApiEndpointRegistry::endpointForRouteName($request->route()?->getName());
        $guessKey = 'api-guess:'.$request->ip();

        if (RateLimiter::tooManyAttempts($guessKey, self::GUESS_LIMIT_PER_MINUTE)) {
            return $this->finish($request, $started, null, $endpoint, $this->tooMany(RateLimiter::availableIn($guessKey)), 'guess_throttled');
        }

        [$token, $cause, $presented] = $this->authenticate($request);

        if ($token === null) {
            RateLimiter::hit($guessKey, 60);

            return $this->finish($request, $started, $presented, $endpoint, $this->unauthorized(), $cause);
        }

        if ($endpoint === null) {
            return $this->finish($request, $started, $token, null, $this->forbidden(), 'unregistered');
        }

        if (! $token->grants($endpoint)) {
            return $this->finish($request, $started, $token, $endpoint, $this->forbidden(), 'ungranted');
        }

        $tokenKey = 'api-token:'.$token->id;
        if (RateLimiter::tooManyAttempts($tokenKey, self::TOKEN_LIMIT_PER_MINUTE)) {
            return $this->finish($request, $started, $token, $endpoint, $this->tooMany(RateLimiter::availableIn($tokenKey)), 'token_throttled');
        }
        RateLimiter::hit($tokenKey, 60);

        $this->touchLastUsed($token, $request);

        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_endpoint', $endpoint);

        return $this->finish($request, $started, $token, $endpoint, $next($request), null);
    }

    /**
     * Returns the token, or null and the refusal cause; [2] is the row the
     * presented secret matches (null when none), so a refusal is audited under
     * that token.
     *
     * @return array{0: ApiToken|null, 1: string|null, 2: ApiToken|null}
     */
    private function authenticate(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return [null, $header === '' ? 'missing' : 'malformed', null];
        }

        $hash = ApiToken::hashPlaintext($m[1]);

        // The decision: ApiToken::scopeAuthenticatable is the only gate. The
        // lookup is by digest; hash_equals re-checks the row it found in
        // constant time, so a later change to the lookup cannot drop the
        // comparison silently.
        $row = ApiToken::authenticatable()->where('token_hash', $hash)->first();
        if ($row !== null && hash_equals((string) $row->token_hash, $hash)) {
            return [$row, null, $row];
        }

        return [null, ...$this->refusalCause($hash)];
    }

    /**
     * Why a presented token did not authenticate, and the row its digest
     * matches (null when none), for the audit row only. This runs after the
     * refusal is decided and cannot turn it into an acceptance: the row is used
     * only to file the refusal under that token's Activity.
     *
     * @return array{0: string, 1: ApiToken|null}
     */
    private function refusalCause(string $hash): array
    {
        $row = ApiToken::query()->where('token_hash', $hash)->first();

        $cause = match (true) {
            $row === null => 'unknown',
            $row->isRevoked() => 'revoked',
            $row->isPaused() => 'paused',
            $row->isDraft() => 'draft',
            $row->isExpired() => 'expired',
            default => 'unknown',
        };

        return [$cause, $row];
    }

    private function touchLastUsed(ApiToken $token, Request $request): void
    {
        $last = $token->last_used_at;
        if ($last !== null && $last->gt(now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS)) && $token->last_used_ip === $request->ip()) {
            return;
        }

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->saveQuietly();
    }

    private function finish(Request $request, float $started, ?ApiToken $token, ?string $endpoint, Response $response, ?string $cause): Response
    {
        $status = $response->getStatusCode();

        try {
            ApiRequestLog::create([
                'kind' => 'request',
                'api_token_id' => $token?->id,
                'endpoint' => $endpoint,
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'status' => $status,
                'cause' => $cause,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'source_ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ApiToken] Audit write failed', ['exception' => $e::class]);
        }

        if ($cause !== null) {
            Log::info('[ApiToken] Request refused', [
                'status' => $status,
                'cause' => $cause,
                'endpoint' => $endpoint,
                'api_token_id' => $token?->id,
                'source_ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    private function tooMany(int $retryAfter): JsonResponse
    {
        return response()->json(['error' => 'Too Many Requests'], 429, ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
