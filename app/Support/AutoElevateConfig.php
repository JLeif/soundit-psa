<?php

namespace App\Support;

use App\Models\Setting;

class AutoElevateConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('autoelevate_api_key'),
            default => null,
        };
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }
}
