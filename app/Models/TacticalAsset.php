<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TacticalAsset extends Model
{
    protected $fillable = [
        'asset_id',
        'agent_id',
        'hostname',
        'os',
        'plat',
        'os_version',
        'public_ip',
        'local_ips',
        'last_user',
        'cpu',
        'make_model',
        'disk_summary',
        'ram_gb',
        'serial_number',
        'status',
        'agent_version',
        'last_seen_at',
        'client_name',
        'site_name',
        'needs_reboot',
        'has_patches_pending',
        'checks_failing',
        'checks_total',
        'graphics',
        'monitoring_type',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'local_ips' => 'array',
            'ram_gb' => 'decimal:1',
            'needs_reboot' => 'boolean',
            'has_patches_pending' => 'boolean',
            'checks_failing' => 'integer',
            'checks_total' => 'integer',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The agent's platform (windows|darwin|linux), or null when unknown.
     * Prefers Tactical's own `plat` field; rows synced before the plat column
     * existed fall back to an operating_system sniff. (psa-0pb9m)
     */
    public function platform(): ?string
    {
        return \App\Services\Tactical\TacticalPlatform::fromAgentPayload($this->plat, $this->os);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'online' => 'bg-success',
            'offline' => 'bg-danger',
            'overdue' => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
    }
}
