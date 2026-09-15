<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneCallResolutionProposal extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'handled_at' => 'datetime'];
    }
}
