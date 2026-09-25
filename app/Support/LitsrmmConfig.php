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
 *  1. base_url has NO default, and it is operator-supplied. Level defaults to
 *     its public SaaS host because there is exactly one; LITSRMM is
 *     self-hosted, so the host differs per deployment and we have no basis to
 *     guess it. A baked-in default would silently aim credentialed requests at
 *     whatever answers on that address. Absent base_url means not configured,
 *     so the integration stays inert until an operator supplies one.
 *
 *     The scheme is enforced rather than trusted: LitsrmmClient refuses a
 *     non-https base URL unless the host is loopback, before any Authorization
 *     header is built. The settings form applies the same rule so an operator
 *     is told at the field, but the client is where the guarantee lives,
 *     because env and config bypass the form.
 *
 *  2. isEnabled() is read by isAvailable(), and consumers are expected to call
 *     isAvailable() rather than isConfigured(). Keeping "we hold credentials"
 *     and "we are permitted to use them" as separate questions is what makes a
 *     settings toggle mean something: a vendor whose isEnabled() returns
 *     isConfigured() has a switch that cannot turn anything off. See
 *     TacticalConfig for the pattern this one follows, and prefer
 *     isAvailable() in any new consumer. Settings deliberately reads get()
 *     directly, so an operator can still see and edit credentials while the
 *     integration is switched off.
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
