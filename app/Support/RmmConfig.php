<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Leif RMM integration config.
 *
 * The RMM is an outbound CONSUMER of this PSA: it reads client identity from
 * here because the PSA owns it, and holds no opinion of its own about who the
 * clients are.
 *
 * Dormant until a key is set. An integration that is half-configured — enabled
 * with no key — would answer every request with a 401 that looks like an
 * authentication bug rather than a configuration one, so "no key" means the
 * surface is simply off.
 */
class RmmConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('rmm_api_key'),
            default => null,
        };
    }

    public static function isConfigured(): bool
    {
        $key = self::get('api_key');

        return is_string($key) && $key !== '';
    }
}
