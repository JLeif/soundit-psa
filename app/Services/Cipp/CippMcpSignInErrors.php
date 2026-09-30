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
    /** AADSTS number => [what happened, how to fix it]. `{client}` / `{callback}` are filled in. */
    private const MAP = [
        500113 => [
            'Microsoft has no redirect URI registered on the MCP client app, so it had nowhere to send the sign-in back to.',
            'Open Entra → App registrations → {client} → Authentication → Add a platform → Mobile and desktop applications, add {callback}, save, then click Connect CIPP MCP again.',
        ],
        50011 => [
            'The PSA callback URL is not registered on the MCP client app, or is registered with different text.',
            'Open Entra → App registrations → {client} → Authentication and add {callback} exactly as shown (Mobile and desktop applications), save, then click Connect CIPP MCP again.',
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
            'Microsoft expected a client secret because the MCP client app does not allow public client sign-in.',
            'Open Entra → App registrations → {client} → Authentication → Advanced settings, set Allow public client flows to Yes, save, then click Connect CIPP MCP again.',
        ],
    ];

    /** The AADSTS number in a sanitised code string, or null. */
    public static function aadsts(string $code): ?int
    {
        return preg_match('/\bAADSTS(\d{4,})\b/', $code, $m) ? (int) $m[1] : null;
    }

    /**
     * The flash line for a failed sign-in.
     *
     * @param  string  $code  already sanitised (CippMcpConnector::sanitizeCode / errorCode)
     */
    public static function message(string $code): string
    {
        $number = self::aadsts($code);
        if ($number !== null && isset(self::MAP[$number])) {
            [$what, $fix] = self::MAP[$number];

            return "CIPP MCP sign-in failed (AADSTS{$number}): {$what} Fix: ".strtr($fix, [
                '{client}' => self::clientRef(),
                '{callback}' => route('auth.cipp-mcp.callback'),
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
