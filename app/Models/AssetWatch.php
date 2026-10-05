<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent-owned watch on one asset's RMM online state (card K3VEcxtw).
 * See the create_asset_watches migration for the active_key contract.
 */
class AssetWatch extends Model
{
    public const STATE_ONLINE = 'online';

    public const STATE_OFFLINE = 'offline';

    public const STATES = [self::STATE_ONLINE, self::STATE_OFFLINE];

    protected $fillable = [
        'owner',
        'client_id',
        'asset_id',
        'state',
        'reason',
        'repeat',
        'expires_at',
        'fired_at',
        'fire_count',
        'last_observed_state',
        'last_checked_at',
        'expired_at',
        'removed_at',
        'removed_reason',
        'active_key',
    ];

    protected function casts(): array
    {
        return [
            'repeat' => 'boolean',
            'expires_at' => 'datetime',
            'fired_at' => 'datetime',
            'fire_count' => 'integer',
            'last_observed_state' => 'boolean',
            'last_checked_at' => 'datetime',
            'expired_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public static function activeKeyFor(string $owner, int $assetId, string $state): string
    {
        return hash('sha256', $owner."\0".$assetId."\0".$state);
    }

    /**
     * Armed and unexpired: may still fire.
     *
     * @param  Builder<AssetWatch>  $query
     */
    public function scopeArmed(Builder $query): void
    {
        $query->whereNotNull('active_key')->where('expires_at', '>', now());
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    /** active | fired | expired | removed */
    public function status(): string
    {
        if ($this->removed_at !== null) {
            return 'removed';
        }

        if (! $this->repeat && $this->fired_at !== null) {
            return 'fired';
        }

        if ($this->expired_at !== null || $this->expires_at === null || ! $this->expires_at->isFuture()) {
            return 'expired';
        }

        return 'active';
    }
}
