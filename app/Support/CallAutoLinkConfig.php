<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The one switch for the deterministic call auto-link
 * (PhoneCallService::autoLinkToSoleOpenTicket, card 0JJon0z4).
 *
 * Absent row ⇒ OFF. Only the exact string '1' turns it on, so a blank, '0',
 * 'false' or any other stored value leaves calls unlinked.
 *
 * Gated on nothing else: not intake_call_enabled, not
 * intake_attach_auto_threshold, not any AI setting. It defaults OFF because
 * linkCallToTicket() can start a prepay debit, so enabling it moves client
 * prepay balances. Enabling it in production is the owner's decision.
 */
class CallAutoLinkConfig
{
    public const SETTING_KEY = 'call_autolink_enabled';

    public static function enabled(): bool
    {
        return (string) Setting::getValue(self::SETTING_KEY) === '1';
    }
}
