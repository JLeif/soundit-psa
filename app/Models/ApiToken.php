<?php

namespace App\Models;

use App\Models\Concerns\HasTokenLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token for the PSA REST API (/api/v1/*, with the /api/rmm/* alias).
 *
 * A sibling of McpToken, in its own table, so an API token can never
 * authenticate to an MCP route or the reverse. Only sha256(plaintext) is
 * stored; the plaintext is returned once by mint()/regenerate() and never
 * persisted. A new token is a draft with no endpoints, so it cannot
 * authenticate until it is granted endpoints and activated.
 *
 * @property array<int, string> $endpoints
 */
class ApiToken extends Model
{
    use HasTokenLifecycle;

    public const PLAINTEXT_PREFIX = 'psa-api-';

    protected $fillable = [
        'label',
        'endpoints',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'endpoints' => 'array',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Activated, not paused, not revoked, and not past expires_at.
     *
     * @param  Builder<ApiToken>  $query
     */
    public function scopeAuthenticatable(Builder $query): void
    {
        $this->lifecycleAuthenticatable($query);
        $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int, string> */
    public function grantedEndpoints(): array
    {
        return array_values(array_filter((array) ($this->endpoints ?? []), 'is_string'));
    }

    public function grants(string $endpoint): bool
    {
        return in_array($endpoint, $this->grantedEndpoints(), true);
    }

    public static function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function prefixOf(string $plaintext): string
    {
        return mb_substr($plaintext, 0, 12).'…';
    }

    /**
     * Mint a draft token with no endpoints. Returns [model, plaintext]; the
     * plaintext exists only in the return value.
     *
     * @return array{0: ApiToken, 1: string}
     */
    public static function mint(string $label, ?int $createdBy = null): array
    {
        $plain = self::PLAINTEXT_PREFIX.Str::random(48);

        $token = new self;
        $token->label = $label;
        $token->token_hash = self::hashPlaintext($plain);
        $token->token_prefix = self::prefixOf($plain);
        $token->endpoints = [];
        $token->created_by = $createdBy;
        $token->save();

        return [$token, $plain];
    }

    /** Replace the secret. Returns the new plaintext, shown once. */
    public function regenerate(): string
    {
        $plain = self::PLAINTEXT_PREFIX.Str::random(48);

        $this->forceFill([
            'token_hash' => self::hashPlaintext($plain),
            'token_prefix' => self::prefixOf($plain),
            'last_used_at' => null,
            'last_used_ip' => null,
        ])->save();

        return $plain;
    }

    public static function normalizeLabel(?string $label): string
    {
        $label = trim((string) $label);
        $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?? '';
        $label = trim($label, '-');

        return $label !== '' ? $label : 'untitled';
    }
}
