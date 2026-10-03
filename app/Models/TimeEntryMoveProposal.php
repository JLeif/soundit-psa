<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A held agent move of one time entry's contract (card I3EvQKUV PR 2, ruling Q9),
 * awaiting a staff approval on the ticket page.
 */
class TimeEntryMoveProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function toContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'to_contract_id')->withTrashed();
    }

    public function fromContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'from_contract_id')->withTrashed();
    }
}
