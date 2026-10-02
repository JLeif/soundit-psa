<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One row per API request (kind=request) or token lifecycle action
 * (kind=lifecycle). Request and response bodies are never stored.
 */
class ApiRequestLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'kind',
        'api_token_id',
        'endpoint',
        'method',
        'path',
        'status',
        'cause',
        'duration_ms',
        'source_ip',
        'actor',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
