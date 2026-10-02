<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Support\ApiEndpointRegistry;
use App\Support\AppTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Settings -> API Tokens. Reading needs auth; every write route carries the
 * `admin` middleware (routes/web.php). Mirrors McpTokensController's draft ->
 * activate lifecycle without touching it.
 */
class ApiTokensController extends Controller
{
    public function index()
    {
        return view('settings.api-tokens.index', [
            'tokens' => ApiToken::query()->with('createdBy')->orderByRaw('revoked_at is not null')->latest('id')->get(),
        ]);
    }

    public function show(ApiToken $token)
    {
        $newToken = session('api_new_token');

        return view('settings.api-tokens.show', [
            'token' => $token,
            'groups' => ApiEndpointRegistry::grouped(),
            'granted' => $token->grantedEndpoints(),
            'newToken' => is_string($newToken) ? $newToken : null,
            'activity' => ApiRequestLog::query()
                ->where('api_token_id', $token->id)
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * Mint an inactive draft with no endpoints and redirect to its page, where
     * the plaintext is shown once (flashed for exactly one request).
     */
    public function store(Request $request)
    {
        $validated = $request->validate(self::expiryRules(), self::expiryMessages());
        $expires = self::expiryInstant($validated['expires_at'] ?? null);

        [$token, $plain] = ApiToken::mint($this->uniqueDraftLabel(), $request->user()?->id);
        if ($expires !== null) {
            $token->forceFill(['expires_at' => $expires])->save();
        }

        $this->audit($request, $token, 'token/mint', ['endpoints' => [], 'expires_at' => $expires?->toIso8601String()]);

        return redirect()
            ->route('settings.api-tokens.show', $token)
            ->with('api_new_token', $plain);
    }

    public function update(Request $request, ApiToken $token)
    {
        if ($token->isRevoked()) {
            return $this->respond($request, $token, 'A revoked token cannot be edited.', ok: false);
        }

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            ...self::expiryRules(),
        ], self::expiryMessages());

        $label = ApiToken::normalizeLabel($validated['label']);
        if (ApiToken::query()->where('label', $label)->where('id', '!=', $token->id)->exists()) {
            throw ValidationException::withMessages(['label' => 'That name is already taken.']);
        }

        $expires = self::expiryInstant($validated['expires_at'] ?? null);

        $before = ['label' => $token->label, 'expires_at' => $token->expires_at?->toIso8601String()];
        $token->forceFill(['label' => $label, 'expires_at' => $expires])->save();

        $this->audit($request, $token, 'token/update', [
            'before' => $before,
            'after' => ['label' => $label, 'expires_at' => $expires?->toIso8601String()],
        ]);

        return $this->respond($request, $token->fresh(), 'Token saved.');
    }

    /**
     * expires_at is a TIMESTAMP column, whose range ends at
     * 2038-01-19 03:14:07 UTC on MariaDB/MySQL; a later value fails the write.
     * The column type stays (Jeeves's RULED (B) term 4, run 01a0fb29), so the
     * input is bounded instead, on create and on update.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function expiryRules(): array
    {
        return [
            'expires_at' => [
                'bail',
                'nullable',
                'date',
                'before:2038-01-19',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // A date before 2038-01-19 can still pass the column's
                    // limit once its local end of day is converted to UTC
                    // (2038-01-18 in a zone west of UTC).
                    $instant = self::expiryInstant(is_string($value) ? $value : null);
                    if ($instant !== null && $instant->gt(self::latestStorableExpiry())) {
                        $fail('That expiry date is too far in the future for this time zone. Choose an earlier date, or leave it blank.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    private static function expiryMessages(): array
    {
        return [
            'expires_at.before' => 'The expiry date must be before 2038-01-19. Leave it blank for a token that never expires.',
        ];
    }

    private static function latestStorableExpiry(): Carbon
    {
        return Carbon::parse('2038-01-19 03:14:07', 'UTC');
    }

    /**
     * The form's date is local (app timezone) end-of-day; stored as UTC.
     */
    private static function expiryInstant(?string $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date, AppTimezone::get())->endOfDay()->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateEndpoints(Request $request, ApiToken $token)
    {
        if ($token->isRevoked()) {
            return $this->respond($request, $token, 'A revoked token cannot be edited.', ok: false);
        }

        $validated = $request->validate([
            'endpoints' => ['present', 'array'],
            'endpoints.*' => ['string', 'max:100'],
        ]);

        $names = array_values(array_unique(array_map('trim', $validated['endpoints'])));
        $unknown = array_values(array_filter($names, fn (string $n): bool => ! ApiEndpointRegistry::has($n)));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'endpoints' => 'Unknown endpoint(s): '.implode(', ', $unknown),
            ]);
        }

        $before = $token->grantedEndpoints();
        $token->forceFill(['endpoints' => $names])->save();

        $this->audit($request, $token, 'token/endpoints', ['before' => $before, 'after' => $names]);

        return $this->respond($request, $token->fresh(), 'Endpoint grants saved.');
    }

    public function activate(Request $request, ApiToken $token)
    {
        if ($token->isRevoked()) {
            return $this->respond($request, $token, 'A revoked token cannot be activated.', ok: false);
        }

        $token->forceFill([
            'activated_at' => $token->activated_at ?? now(),
            'paused_at' => null,
        ])->save();

        $this->audit($request, $token, 'token/activate', []);

        return $this->respond($request, $token->fresh(), 'Token activated.');
    }

    public function pause(Request $request, ApiToken $token)
    {
        if (! $token->isActive()) {
            return $this->respond($request, $token, 'Only an active token can be paused.', ok: false);
        }

        $token->forceFill(['paused_at' => now()])->save();
        $this->audit($request, $token, 'token/pause', []);

        return $this->respond($request, $token->fresh(), 'Token paused.');
    }

    public function resume(Request $request, ApiToken $token)
    {
        if (! $token->isPaused()) {
            return $this->respond($request, $token, 'Only a paused token can be resumed.', ok: false);
        }

        $token->forceFill(['paused_at' => null])->save();
        $this->audit($request, $token, 'token/resume', []);

        return $this->respond($request, $token->fresh(), 'Token resumed.');
    }

    public function regenerate(Request $request, ApiToken $token)
    {
        if ($token->isRevoked()) {
            return redirect()->route('settings.api-tokens.show', $token)
                ->with('error', "Revoked tokens can't be regenerated. Create a new one instead.");
        }

        $plain = $token->regenerate();
        $this->audit($request, $token, 'token/regenerate', []);

        return redirect()
            ->route('settings.api-tokens.show', $token)
            ->with('api_new_token', $plain);
    }

    public function revoke(Request $request, ApiToken $token)
    {
        if ($token->isRevoked()) {
            return redirect()->route('settings.api-tokens.index')
                ->with('success', 'Token "'.$token->label.'" is already revoked.');
        }

        $wasDraft = $token->isDraft();
        $token->forceFill(['revoked_at' => now()])->save();
        $this->audit($request, $token, 'token/revoke', ['endpoints' => $token->grantedEndpoints(), 'was_draft' => $wasDraft]);

        return redirect()->route('settings.api-tokens.index')
            ->with('success', $wasDraft
                ? 'Draft "'.$token->label.'" discarded.'
                : 'Token "'.$token->label.'" revoked.');
    }

    private function respond(Request $request, ApiToken $token, string $message, bool $ok = true)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'state' => $token->state(),
                'granted_count' => count($token->grantedEndpoints()),
            ], $ok ? 200 : 422);
        }

        return redirect()->route('settings.api-tokens.show', $token)
            ->with($ok ? 'success' : 'error', $message);
    }

    private function uniqueDraftLabel(): string
    {
        $base = 'untitled';
        if (! ApiToken::query()->where('label', $base)->exists()) {
            return $base;
        }

        for ($i = 2; $i < 10000; $i++) {
            if (! ApiToken::query()->where('label', $base.'-'.$i)->exists()) {
                return $base.'-'.$i;
            }
        }

        return $base.'-'.strtolower(\Illuminate\Support\Str::random(6));
    }

    /** @param  array<string, mixed>  $details */
    private function audit(Request $request, ApiToken $token, string $action, array $details): void
    {
        try {
            ApiRequestLog::create([
                'kind' => 'lifecycle',
                'api_token_id' => $token->id,
                'endpoint' => $action,
                'actor' => mb_substr('web:'.((string) ($request->user()?->email ?? $request->user()?->id ?? 'unknown')), 0, 191),
                'source_ip' => $request->ip(),
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Settings/ApiTokens] Audit write failed', ['exception' => $e::class]);
        }
    }
}
