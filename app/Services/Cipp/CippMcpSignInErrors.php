<?php

namespace App\Services\Cipp;

use App\Support\CippConfig;

/**
 * Plain-English text for the Microsoft sign-in errors Connect CIPP MCP hits
 * (card jOWYaBuZ): one sentence saying what went wrong plus one click-path fix.
 *
 * Input is always a CODE (the `error` field and/or an AADSTS number); the vendor
 * body or description is never passed in and never echoed. Codes not in the map
 * are shown as the code with a generic line.
 */
class CippMcpSignInErrors
{
    /**
     * AADSTS number => [what happened, how to fix it]. `{client}`, `{callback}` and
     * `{platform}` are filled in. `{platform}` is the redirect platform implied by the
     * stored MCP Client Secret: Web when one is stored (clientAuthFields() sends it),
     * otherwise Mobile and desktop applications. CippSetupCheck::run() makes the same choice.
     * The 53003 sentence here covers the code exchange; the authorization-error arm
     * uses SIGN_IN_BLOCKED_53003.
     */
    private const MAP = [
        500113 => [
            'Microsoft has no redirect URI registered on the MCP client app, so it had nowhere to send the sign-in back to.',
            'Open Entra → App registrations → {client} → Authentication → Add a platform → {platform}, add {callback}, save, then click Connect CIPP MCP again.',
        ],
        50011 => [
            'The PSA callback URL is not registered on the MCP client app, or is registered with different text.',
            'Open Entra → App registrations → {client} → Authentication and add {callback} exactly as shown under {platform}, save, then click Connect CIPP MCP again.',
        ],
        53003 => [
            'Conditional Access blocked the token redeem from the PSA server.',
            'Sign in with a dedicated CIPP service account excluded from the blocking policy: Entra → Protection → Conditional Access → Policies → the blocking policy → Users → Exclude → add the service account, save, then Reconnect as that account (see docs/INSTALL.md, CIPP).',
        ],
        65001 => [
            'The MCP client app has not been granted consent to call CIPP-MCP.',
            'In CIPP open Integrations → CIPP-API → MCP and click Actions → Save to Azure, or open Entra → App registrations → {client} → API permissions → Grant admin consent, then click Connect CIPP MCP again.',
        ],
        7000218 => [
            'Microsoft required a client secret for this sign-in and the PSA sent none.',
            'Open Entra → App registrations → {client} → Authentication: if {callback} is listed under Web, remove it there and add it under Mobile and desktop applications (a Web redirect requires a secret) and, under Advanced settings, set Allow public client flows to Yes; or keep the Web redirect and save that app\'s secret as MCP Client Secret on this panel. Save, then click Connect CIPP MCP again.',
        ],
    ];

    /** 53003 on the authorization-error arm: Entra refused the sign-in at /authorize, so no code existed and nothing was redeemed. */
    private const SIGN_IN_BLOCKED_53003 = 'Conditional Access blocked the interactive sign-in at Microsoft, before any code reached the PSA.';

    /** The AADSTS number in a sanitised code string, or null. */
    public static function aadsts(string $code): ?int
    {
        return preg_match('/\bAADSTS(\d{4,})\b/', $code, $m) ? (int) $m[1] : null;
    }

    /**
     * The flash line for a failed sign-in.
     *
     * @param  string  $code  already sanitised (CippMcpConnector::sanitizeCode / errorCode)
     * @param  bool  $atCodeExchange  true when Microsoft refused the code exchange (the token redeem
     *                                from the PSA server); false for an authorization error
     *                                returned to the callback, before any code existed
     */
    public static function message(string $code, bool $atCodeExchange = false): string
    {
        $number = self::aadsts($code);
        if ($number !== null && isset(self::MAP[$number])) {
            [$what, $fix] = self::MAP[$number];
            if ($number === 53003 && ! $atCodeExchange) {
                $what = self::SIGN_IN_BLOCKED_53003;
            }
            $hasSecret = (string) CippConfig::get('mcp_client_secret') !== '';

            return "CIPP MCP sign-in failed (AADSTS{$number}): {$what} Fix: ".strtr($fix, [
                '{client}' => self::clientRef(),
                '{callback}' => route('auth.cipp-mcp.callback'),
                '{platform}' => $hasSecret
                    ? 'Web (an MCP Client Secret is stored, so the PSA signs in as a confidential client)'
                    : 'Mobile and desktop applications',
            ]);
        }

        return "CIPP MCP sign-in failed (error code: {$code}). There is no plain-English help for this code yet: "
            .'click Check setup on the CIPP panel, and look the code up in Microsoft\'s AADSTS error reference.';
    }

    private static function clientRef(): string
    {
        $id = trim((string) CippConfig::get('mcp_client_id'));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) ? $id : 'your MCP client app';
    }
}
