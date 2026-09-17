<?php

namespace App\Support;

use App\Models\Setting;

class BenjiPaysConfig
{
    /**
     * Portal Pay Online routes through a BenjiPays applied link (#2065).
     * Bool setting stored as '1'/'0'; default OFF. Flipping it is an
     * operator decision made on the Integrations card, never by code.
     */
    public const PAY_ONLINE_SETTING = 'benjipays_pay_online';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('benjipays_api_key'),
            default => null,
        };
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /**
     * OFF=OFF: the toggle is only honoured while a key is configured, so a
     * cleared key silently returns the portal to Stripe rather than leaving
     * a POST that can only fail.
     */
    public static function payOnlineEnabled(): bool
    {
        return Setting::getValue(self::PAY_ONLINE_SETTING, '0') === '1' && self::isConfigured();
    }
}
