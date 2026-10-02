<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * The derived token lifecycle shared by bearer-token models: draft -> active
 * -> paused -> revoked, read from activated_at / paused_at / revoked_at.
 * Precedence: revoked > paused > active > draft.
 *
 * Copied from McpToken's lifecycle methods; McpToken itself is not moved onto
 * this trait here, so the MCP surface is untouched.
 */
trait HasTokenLifecycle
{
    /**
     * The authentication gate: activated, not paused, not revoked. A model may
     * narrow it further (ApiToken adds expiry) by overriding this method and
     * calling lifecycleAuthenticatable().
     *
     * @param  Builder<static>  $query
     */
    public function scopeAuthenticatable(Builder $query): void
    {
        $this->lifecycleAuthenticatable($query);
    }

    /** @param  Builder<static>  $query */
    protected function lifecycleAuthenticatable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->whereNotNull('activated_at')
            ->whereNull('paused_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at === null
            && $this->activated_at === null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at === null
            && $this->activated_at !== null;
    }

    public function isPaused(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at !== null;
    }

    public function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->paused_at !== null) {
            return 'paused';
        }

        if ($this->activated_at !== null) {
            return 'active';
        }

        return 'draft';
    }
}
