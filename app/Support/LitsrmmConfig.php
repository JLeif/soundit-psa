<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configuration for the LITSRMM integration (Leif IT Solutions RMM).
 *
 * Modelled on LevelConfig, which the vendor's proposal names as the shape their
 * API was built to match. Two deliberate differences from that model, both
 * measured rather than assumed:
 *
 *  1. base_url has NO default. Level defaults to its public SaaS host because
 *     there is exactly one. LITSRMM is self-hosted: the proposal says "both
 *     systems already run on the same host, so these are localhost calls",
 *     which describes THEIR deployment, not ours. A baked-in default would be
 *     a guess about someone else's network, and a wrong one would silently aim
 *     credentialed requests at whatever answers on that address here. Absent
 *     base_url means not configured, so the integration stays inert until an
 *     operator supplies it.
 *
 *  2. isEnabled() is read by isAvailable(), and consumers are expected to call
 *     isAvailable() rather than isConfigured(). The vendor's own proposal
 *     reports the Tactical switch as a live bug for precisely this reason:
 *     TacticalConfig::isEnabled() returns isConfigured(), so the settings
 *     toggle changes nothing. That defect is tracked separately (fR7xvo0f);
 *     this class is written so a twelfth vendor does not arrive carrying the
 *     same shape. Settings deliberately reads get() directly, so an operator
 *     can still see and edit credentials while the integration is switched off.
 */
class LitsrmmConfig
{
    /**
     * Whether an operator has switched the integration on.
     *
     * Defaults to '1' to match every sibling (Level, Tactical, Mesh): a vendor
     * that has just had credentials entered is expected to work without a
     * second action. Inertness comes from isConfigured(), not from this.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('litsrmm_enabled', '1') === '1';
    }

    /**
     * Whether the credentials needed to reach the API are present.
     *
     * BOTH api_key and base_url are required. Level can omit base_url because
     * it has a public default; a self-hosted vendor cannot.
     */
    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key')) && ! empty(self::get('base_url'));
    }

    /**
     * The question every consumer should ask: configured AND switched on.
     *
     * Kept distinct from isConfigured() so that "we hold credentials" and "we
     * are permitted to use them" cannot collapse into one another.
     */
    public static function isAvailable(): bool
    {
        return self::isConfigured() && self::isEnabled();
    }

    /**
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    private static array $map = [
        'api_key' => ['litsrmm_api_key', 'services.litsrmm.api_key', true],
        'base_url' => ['litsrmm_base_url', 'services.litsrmm.base_url', false],
        'webhook_secret' => ['litsrmm_webhook_secret', 'services.litsrmm.webhook_secret', true],
    ];

    public static function get(string $key): ?string
    {
        if (! isset(self::$map[$key])) {
            return config("services.litsrmm.{$key}");
        }

        [$settingKey, $configKey, $encrypted] = self::$map[$key];

        return Setting::settingOrConfig($settingKey, $configKey, $encrypted);
    }
}
