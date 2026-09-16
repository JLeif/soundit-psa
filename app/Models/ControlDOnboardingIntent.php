<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internal durable evidence, never a request-mass-assignable or public payload model. */
class ControlDOnboardingIntent extends Model
{
    protected $table = 'controld_onboarding_intents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reason_code' => 'integer'];
    }
}
