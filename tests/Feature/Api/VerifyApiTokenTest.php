<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\VerifyApiToken;
use App\Models\ApiRequestLog;
use App\Models\McpToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\MintsApiToken;
use Tests\TestCase;

/**
 * The api.token gate (VerifyApiToken): one byte-identical 401 for every
 * authentication failure, 403 for a valid token without the grant, deny by
 * default, no session, per-token throttle, audit without bodies.
 */
class VerifyApiTokenTest extends TestCase
{
    use MintsApiToken;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function paths(): array
    {
        return [
            'v1' => ['/api/v1/clients'],
            'alias /api/rmm' => ['/api/rmm/clients'],
        ];
    }

    /**
     * Every authentication-failure cause, as [headers-builder]. Each builder
     * mints whatever it needs and returns the headers to send.
     *
     * @return array<string, callable(self): array<string, string>>
     */
    private function failureCauses(): array
    {
        return [
            'missing' => fn () => [],
            'malformed: bare scheme' => fn () => ['Authorization' => 'Bearer'],
            'malformed: basic' => fn () => ['Authorization' => 'Basic '.base64_encode('a:b')],
            'malformed: no scheme' => fn () => ['Authorization' => $this->mintApiToken(label: 'noscheme')[1]],
            'unknown' => fn () => $this->bearer('psa-api-'.str_repeat('x', 48)),
            'draft' => fn () => $this->bearer($this->mintApiToken(state: [], label: 'draft')[1]),
            'paused' => fn () => $this->bearer($this->mintApiToken(state: ['activated_at' => 'now', 'paused_at' => 'now'], label: 'paused')[1]),
            'revoked' => fn () => $this->bearer($this->mintApiToken(state: ['activated_at' => 'now', 'revoked_at' => 'now'], label: 'revoked')[1]),
            'expired' => fn () => $this->bearer($this->mintApiToken(state: ['activated_at' => 'now', 'expires_at' => now()->subSecond()], label: 'expired')[1]),
            'an MCP token' => fn () => $this->bearer($this->mintMcpPlaintext()),
        ];
    }

    private function mintMcpPlaintext(): string
    {
        $plain = \App\Support\McpConfig::mintDraftToken('mcp-cross');
        McpToken::query()->where('label', 'mcp-cross')->update(['activated_at' => now(), 'tools' => json_encode([])]);

        return $plain;
    }

    #[DataProvider('paths')]
    public function test_every_authentication_failure_gets_one_byte_identical_401(string $path): void
    {
        $bodies = [];
        foreach ($this->failureCauses() as $cause => $headers) {
            $response = $this->getJson($path, $headers());
            $this->assertSame(401, $response->getStatusCode(), "cause {$cause}");
            $bodies[$cause] = $response->getContent();
        }

        $this->assertSame(['{"error":"Unauthorized"}'], array_values(array_unique($bodies)), 'bodies differ across causes: '.json_encode($bodies));
    }

    #[DataProvider('paths')]
    public function test_a_valid_token_without_the_grant_gets_403(string $path): void
    {
        [, $plain] = $this->mintApiToken(endpoints: ['assets.read']);

        $response = $this->getJson($path, $this->bearer($plain));

        $response->assertStatus(403);
        $this->assertSame('{"error":"Forbidden"}', $response->getContent());
    }

    #[DataProvider('paths')]
    public function test_an_active_token_with_no_endpoints_is_denied_by_default(string $path): void
    {
        [, $plain] = $this->mintApiToken(endpoints: []);

        $this->getJson($path, $this->bearer($plain))->assertStatus(403);
    }

    #[DataProvider('paths')]
    public function test_a_granted_active_token_is_admitted(string $path): void
    {
        [, $plain] = $this->mintApiToken(endpoints: ['clients.read']);

        $this->getJson($path, $this->bearer($plain))->assertOk()->assertJsonStructure(['clients', 'count']);
    }

    public function test_a_grant_naming_no_registry_entry_admits_nothing(): void
    {
        [, $plain] = $this->mintApiToken(endpoints: ['clients.*', '*', 'clients']);

        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertStatus(403);
    }

    public function test_a_route_with_no_registry_entry_is_refused_even_for_a_valid_token(): void
    {
        Route::middleware([VerifyApiToken::class])->get('/api/v1/unregistered-probe', fn () => response()->json(['leaked' => true]))
            ->name('api.v1.unregistered.probe');
        Route::getRoutes()->refreshNameLookups();

        [, $plain] = $this->mintApiToken();

        $response = $this->getJson('/api/v1/unregistered-probe', $this->bearer($plain));
        $response->assertStatus(403);
        $this->assertSame('{"error":"Forbidden"}', $response->getContent());
        $this->assertSame('unregistered', ApiRequestLog::query()->latest('id')->value('cause'));
    }

    public function test_a_token_differing_from_a_real_one_is_unknown(): void
    {
        [, $plain] = $this->mintApiToken();

        // Same prefix, last character changed: the digest differs, so this must
        // be refused even though an authenticatable token exists.
        $near = substr($plain, 0, -1).(substr($plain, -1) === 'a' ? 'b' : 'a');

        $this->getJson('/api/v1/clients', $this->bearer($near))->assertStatus(401);
        $this->assertSame('unknown', ApiRequestLog::query()->latest('id')->value('cause'));
    }

    public function test_each_of_several_tokens_authenticates_as_itself(): void
    {
        // A lookup that stopped filtering on the digest would hand back some
        // other authenticatable row; the hash comparison must then refuse, and
        // the audit must name the token that actually presented.
        [$a, $plainA] = $this->mintApiToken(label: 'first');
        [$b, $plainB] = $this->mintApiToken(label: 'second');

        $this->getJson('/api/v1/clients', $this->bearer($plainB))->assertOk();
        $this->assertSame($b->id, ApiRequestLog::query()->latest('id')->value('api_token_id'));

        $this->getJson('/api/v1/clients', $this->bearer($plainA))->assertOk();
        $this->assertSame($a->id, ApiRequestLog::query()->latest('id')->value('api_token_id'));
    }

    public function test_the_scheme_is_case_insensitive_and_whitespace_trimmed(): void
    {
        [, $plain] = $this->mintApiToken();

        $this->getJson('/api/v1/clients', ['Authorization' => 'bearer   '.$plain.'  '])->assertOk();
    }

    public function test_the_refusal_cause_is_recorded_but_not_returned(): void
    {
        [$token, $plain] = $this->mintApiToken(state: ['activated_at' => 'now', 'paused_at' => 'now']);

        $response = $this->getJson('/api/v1/clients', $this->bearer($plain));

        $response->assertStatus(401);
        $this->assertStringNotContainsString('paused', $response->getContent());
        $log = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame('paused', $log->cause);
        $this->assertSame(401, (int) $log->status);
        // Filed under the token the secret matched, so the refusal shows in
        // that token's Activity tab; the response still names nothing.
        $this->assertSame($token->id, $log->api_token_id);

        $this->actingAs(\App\Models\User::factory()->admin()->create())
            ->get(route('settings.api-tokens.show', $token))
            ->assertOk()
            ->assertSee('<td class="small">paused</td>', false);
    }

    /**
     * The resolved refusal causes other than paused (covered above), each
     * minted in exactly that state.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function resolvedRefusalCauses(): array
    {
        return [
            'draft' => ['draft', []],
            'revoked' => ['revoked', ['activated_at' => 'now', 'revoked_at' => 'now']],
            'expired' => ['expired', ['activated_at' => 'now', 'expires_at' => 'past']],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[DataProvider('resolvedRefusalCauses')]
    public function test_a_resolved_refusal_is_filed_under_the_matched_token(string $cause, array $state): void
    {
        if (($state['expires_at'] ?? null) === 'past') {
            $state['expires_at'] = now()->subSecond();
        }
        [$token, $plain] = $this->mintApiToken(state: $state, label: $cause);
        // A second, unrelated token, so a log that named "some token" rather
        // than the matched one cannot pass.
        $this->mintApiToken(label: 'bystander');

        $response = $this->getJson('/api/v1/clients', $this->bearer($plain));

        $response->assertStatus(401);
        $this->assertSame('{"error":"Unauthorized"}', $response->getContent());
        $log = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame($cause, $log->cause);
        $this->assertSame(401, (int) $log->status);
        $this->assertSame($token->id, $log->api_token_id);
    }

    public function test_unknown_and_malformed_refusals_stay_unattributed(): void
    {
        $this->mintApiToken(label: 'bystander');

        $this->getJson('/api/v1/clients', $this->bearer('psa-api-'.str_repeat('x', 48)))->assertStatus(401);
        $log = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame('unknown', $log->cause);
        $this->assertNull($log->api_token_id);

        $this->getJson('/api/v1/clients', ['Authorization' => 'Basic '.base64_encode('a:b')])->assertStatus(401);
        $log = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame('malformed', $log->cause);
        $this->assertNull($log->api_token_id);
    }

    public function test_no_session_or_cookie_is_issued(): void
    {
        [, $plain] = $this->mintApiToken();

        $response = $this->withCookie('laravel_session', 'probe')
            ->getJson('/api/v1/clients', $this->bearer($plain));

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_the_throttle_is_per_token_not_global(): void
    {
        [$a, $plainA] = $this->mintApiToken(label: 'noisy');
        [, $plainB] = $this->mintApiToken(label: 'quiet');

        for ($i = 0; $i < VerifyApiToken::TOKEN_LIMIT_PER_MINUTE; $i++) {
            RateLimiter::hit('api-token:'.$a->id, 60);
        }

        $this->getJson('/api/v1/clients', $this->bearer($plainA))->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/v1/clients', $this->bearer($plainB))->assertOk();
    }

    public function test_failed_authentications_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < VerifyApiToken::GUESS_LIMIT_PER_MINUTE; $i++) {
            $this->getJson('/api/v1/clients', $this->bearer('psa-api-guess-'.$i))->assertStatus(401);
        }

        $this->getJson('/api/v1/clients', $this->bearer('psa-api-guess-final'))->assertStatus(429);
    }

    public function test_last_used_is_written_and_then_throttled(): void
    {
        $this->freezeTime();
        [$token, $plain] = $this->mintApiToken();

        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertOk();
        $token->refresh();
        $this->assertSame(now()->toDateTimeString(), $token->last_used_at->toDateTimeString());
        $this->assertSame('127.0.0.1', $token->last_used_ip);

        $this->travel(30)->seconds();
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertOk();
        $this->assertSame(now()->subSeconds(30)->toDateTimeString(), $token->fresh()->last_used_at->toDateTimeString(), 'not rewritten inside the 60s resolution');

        $this->travel(31)->seconds();
        $this->getJson('/api/v1/clients', $this->bearer($plain))->assertOk();
        $this->assertSame(now()->toDateTimeString(), $token->fresh()->last_used_at->toDateTimeString(), 'rewritten once 60s have passed');
    }

    public function test_audit_rows_carry_endpoint_and_status_never_bodies(): void
    {
        $client = \App\Models\Client::factory()->create();
        [$token, $plain] = $this->mintApiToken();

        $this->postJson('/api/rmm/alerts', [
            'client_id' => $client->id,
            'source_alert_id' => 'audit-probe-key',
            'severity' => 'warning',
            'title' => 'AUDIT-TITLE-MARKER',
            'message' => 'AUDIT-BODY-MARKER',
        ], $this->bearer($plain))->assertOk();

        $log = ApiRequestLog::query()->latest('id')->first();
        $this->assertSame('request', $log->kind);
        $this->assertSame($token->id, $log->api_token_id);
        $this->assertSame('alerts.leif_rmm.raise', $log->endpoint);
        $this->assertSame('/api/rmm/alerts', $log->path);
        $this->assertSame(200, (int) $log->status);
        $this->assertNull($log->cause);

        $dump = json_encode(ApiRequestLog::query()->get()->toArray());
        $this->assertStringNotContainsString('AUDIT-BODY-MARKER', $dump);
        $this->assertStringNotContainsString('AUDIT-TITLE-MARKER', $dump);
        $this->assertStringNotContainsString('audit-probe-key', $dump);
        $this->assertStringNotContainsString(substr($plain, 8), $dump);
    }
}
