<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiTokenModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mint_stores_only_the_sha256_hash_never_the_plaintext(): void
    {
        [$token, $plain] = ApiToken::mint('hash-only');

        $row = (array) DB::table('api_tokens')->where('id', $token->id)->first();

        $this->assertSame(hash('sha256', $plain), $row['token_hash']);
        foreach ($row as $column => $value) {
            $this->assertNotSame($plain, $value, "column {$column} holds the plaintext");
            if (is_string($value) && $column !== 'token_prefix') {
                $this->assertStringNotContainsString(substr($plain, 8), $value, "column {$column} contains the secret");
            }
        }
        $this->assertStringNotContainsString(substr($plain, 8), json_encode($token->toArray()));
    }

    public function test_plaintext_and_prefix_format(): void
    {
        [$token, $plain] = ApiToken::mint('format');

        $this->assertMatchesRegularExpression('/^psa-api-[A-Za-z0-9]{48}$/', $plain);
        $this->assertSame(mb_substr($plain, 0, 12).'…', $token->token_prefix);
    }

    public function test_a_minted_token_is_a_draft_with_no_endpoints(): void
    {
        [$token] = ApiToken::mint('born-safe');

        $this->assertSame('draft', $token->state());
        $this->assertSame([], $token->fresh()->grantedEndpoints());
        $this->assertNull($token->expires_at);
        $this->assertFalse(ApiToken::authenticatable()->whereKey($token->id)->exists());
    }

    public function test_regenerate_replaces_hash_and_prefix_and_clears_last_used(): void
    {
        [$token, $old] = ApiToken::mint('regen');
        $token->forceFill(['last_used_at' => now(), 'last_used_ip' => '192.0.2.1'])->save();

        $new = $token->regenerate();
        $token->refresh();

        $this->assertNotSame($old, $new);
        $this->assertSame(hash('sha256', $new), $token->token_hash);
        $this->assertNull($token->last_used_at);
        $this->assertNull($token->last_used_ip);
    }

    public function test_state_precedence_is_revoked_then_paused_then_active_then_draft(): void
    {
        [$t] = ApiToken::mint('precedence');
        $this->assertSame('draft', $t->state());

        $t->activated_at = now();
        $this->assertSame('active', $t->state());

        $t->paused_at = now();
        $this->assertSame('paused', $t->state());

        $t->revoked_at = now();
        $this->assertSame('revoked', $t->state());
    }

    public function test_authenticatable_scope_admits_only_active_unexpired_tokens(): void
    {
        $make = function (string $label, array $state): ApiToken {
            [$t] = ApiToken::mint($label);
            $t->forceFill($state)->save();

            return $t;
        };

        $active = $make('active', ['activated_at' => now()]);
        $futureExpiry = $make('future', ['activated_at' => now(), 'expires_at' => now()->addDay()]);
        $make('draft', []);
        $make('paused', ['activated_at' => now(), 'paused_at' => now()]);
        $make('revoked', ['activated_at' => now(), 'revoked_at' => now()]);
        $make('expired', ['activated_at' => now(), 'expires_at' => now()->subMinute()]);

        $ids = ApiToken::authenticatable()->pluck('id')->sort()->values()->all();

        $this->assertSame([$active->id, $futureExpiry->id], $ids);
    }

    public function test_expiry_is_optional_with_no_default(): void
    {
        [$t] = ApiToken::mint('no-default-expiry');

        $this->assertNull(DB::table('api_tokens')->where('id', $t->id)->value('expires_at'));
        $this->assertFalse($t->isExpired());
    }
}
