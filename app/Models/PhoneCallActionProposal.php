<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A held call-log action awaiting cockpit approval. Same construction as
 * PhoneCallResolutionProposal (encrypted payload, hidden from serialization)
 * with an action_type, because one table serves set_call_billable,
 * block_caller and allow_caller.
 */
class PhoneCallActionProposal extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'handled_at' => 'datetime'];
    }
}
