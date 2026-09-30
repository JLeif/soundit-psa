<?php

namespace App\Services\Cipp;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Models\Alert;
use App\Models\Setting;
use App\Services\AlertService;
use App\Support\CippConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The CIPP MCP delegated-auth connector (CIPP v11+, card 6abc4adc leg 2).
 *
 * CIPP v11 stopped accepting app-only client_credentials at ExecMCP. It takes a
 * DELEGATED token for the shared CIPP-MCP resource app, obtained once through an
 * interactive authorization-code + PKCE sign-in (Settings > Integrations > CIPP >
 * "Connect CIPP MCP") and kept alive with its refresh token. This class owns:
 *   - the connector's stored state (refresh token ENCRYPTED, connected_at, the
 *     signed-in UPN, access-token expiry, the last refresh failure);
 *   - the authorize URL and the one-time code exchange;
 *   - the operator alert raised ONCE per refresh-failure episode and resolved on
 *     the next successful refresh.
 * CippMcpClient::getToken() does the silent refresh and reports back here.
 *
 * Secrecy: no token (access, refresh, id, code or verifier) is ever put in a log
 * line, a flash message, alert text or exception text. Vendor failures are
 * reduced to their OAuth error CODE (e.g. invalid_grant / AADSTS70043) before
 * they leave this class; the response body is never echoed.
 */
class CippMcpConnector
{
    public const REFRESH_TOKEN = 'cipp_mcp_connector_refresh_token';

    public const CONNECTED_AT = 'cipp_mcp_connector_connected_at';

    public const ACCESS_EXPIRES_AT = 'cipp_mcp_connector_access_expires_at';

    public const UPN = 'cipp_mcp_connector_upn';

    public const FAILED_AT = 'cipp_mcp_connector_refresh_failed_at';

    public const FAILED_CODE = 'cipp_mcp_connector_refresh_error_code';

    public const ALERT_ID = 'cipp_mcp_connector_alert_id';

    /** Where the operator fixes a lapsed connector; named in the alert. */
    public const RECONNECT_ACTION = 'Settings > Integrations > CIPP / Microsoft 365 > Connect CIPP MCP';

    public function isConnected(): bool
    {
        return (string) Setting::getValue(self::REFRESH_TOKEN, '') !== '';
    }

    /** Decrypted refresh token, or null when not connected. Never log the result. */
    public function refreshToken(): ?string
    {
        $token = Setting::getEncrypted(self::REFRESH_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * A stamp that changes on every (re)connect. Cache keys include it, so a new
     * sign-in never reads the previous connection's access token or its cached
     * sign-in failure.
     */
    public function generation(): string
    {
        return (string) Setting::getValue(self::CONNECTED_AT, '');
    }

    // --- scope / URLs -------------------------------------------------------

    /**
     * `<Application ID URI>/user_impersonation offline_access`. The backend host is
     * a setting (cipp_mcp_backend_host), never hardcoded; a bare host is given the
     * https:// scheme, an api:// URI is kept as it is.
     */
    public static function scope(): string
    {
        $host = trim((string) CippConfig::get('mcp_backend_host'));
        if ($host === '') {
            throw new CippMcpAuthException('CIPP MCP backend host (the CIPP-MCP Application ID URI) is not configured');
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $host)) {
            $host = 'https://'.$host;
        }

        return rtrim($host, '/').'/user_impersonation offline_access';
    }

    public static function tokenUrl(string $tenantId): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode($tenantId).'/oauth2/v2.0/token';
    }

    public static function authorizeUrl(string $tenantId, array $query): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode($tenantId).'/oauth2/v2.0/authorize?'
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** RFC 7636 S256: BASE64URL(SHA256(ascii(code_verifier))), unpadded. */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** A 64-char verifier from the RFC 7636 unreserved set (43..128 allowed). */
    public static function newCodeVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * The token-endpoint body fields that identify the client: always client_id,
     * plus client_secret when one is stored (Web / confidential redirect). A
     * Mobile/desktop (public) registration has no secret and sends none.
     *
     * @return array<string, string>
     */
    public static function clientAuthFields(): array
    {
        $fields = ['client_id' => (string) CippConfig::get('mcp_client_id')];
        $secret = (string) CippConfig::get('mcp_client_secret');
        if ($secret !== '') {
            $fields['client_secret'] = $secret;
        }

        return $fields;
    }

    // --- the one-time code exchange ------------------------------------------

    /**
     * Exchange an authorization code for tokens and store the connection.
     *
     * @throws CippMcpAuthException carrying the vendor error CODE only
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): void
    {
        $tenantId = (string) CippConfig::get('tenant_id');
        if ($tenantId === '' || (string) CippConfig::get('mcp_client_id') === '') {
            throw new CippMcpAuthException('CIPP MCP client app is not configured (tenant id / MCP client id)');
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::tokenUrl($tenantId), self::clientAuthFields() + [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
                'scope' => self::scope(),
            ]);
        } catch (CippMcpAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CippMcpAuthException('CIPP MCP code exchange could not reach Microsoft ('.class_basename($e).')');
        }

        if ($response->failed()) {
            throw new CippMcpAuthException('CIPP MCP code exchange was refused: '.self::errorCode($response));
        }

        $refresh = $response->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new CippMcpAuthException('CIPP MCP code exchange returned no refresh token (is offline_access granted?)');
        }

        Setting::setEncrypted(self::REFRESH_TOKEN, $refresh);
        Setting::setValue(self::CONNECTED_AT, now()->toIso8601String());
        Setting::setValue(self::UPN, self::upnFromIdToken($response->json('id_token')));
        $this->recordAccessExpiry((int) ($response->json('expires_in') ?? 0));
        $this->recordRefreshSuccess();
    }

    // --- refresh bookkeeping (called by CippMcpClient::getToken) -------------

    public function recordAccessExpiry(int $expiresIn): void
    {
        Setting::setValue(self::ACCESS_EXPIRES_AT, $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null);
    }

    /** Persist a rotated refresh token. The old one is overwritten, never logged. */
    public function storeRotatedRefreshToken(mixed $refreshToken): void
    {
        if (is_string($refreshToken) && $refreshToken !== '' && $refreshToken !== $this->refreshToken()) {
            Setting::setEncrypted(self::REFRESH_TOKEN, $refreshToken);
        }
    }

    /**
     * A refresh succeeded: clear the failure marker and resolve the open alert,
     * ending the failure episode.
     */
    public function recordRefreshSuccess(): void
    {
        if (Setting::getValue(self::FAILED_AT) !== null) {
            Setting::setValue(self::FAILED_AT, null);
            Setting::setValue(self::FAILED_CODE, null);
        }

        $alertId = Setting::getValue(self::ALERT_ID);
        if ($alertId !== null && $alertId !== '') {
            $alert = Alert::find((int) $alertId);
            if ($alert !== null) {
                app(AlertService::class)->resolve($alert, 'CIPP MCP connector refreshed successfully.');
            }
            Setting::setValue(self::ALERT_ID, null);
        }
    }

    /**
     * A refresh failed. The first failure of an episode raises ONE operator alert
     * naming the reconnect action; later failures in the same episode only update
     * the stored code. The episode ends at the next success (recordRefreshSuccess).
     */
    public function recordRefreshFailure(string $errorCode): void
    {
        $errorCode = self::sanitizeCode($errorCode);
        $opened = $this->openEpisode();
        Setting::setValue(self::FAILED_CODE, $errorCode);

        if (! $opened) {
            return;
        }

        try {
            $alert = app(AlertService::class)->upsert(AlertSource::Cipp, 'cipp-mcp-connector-refresh:'.Str::ulid(), [
                'severity' => AlertSeverity::Error,
                'title' => 'CIPP MCP sign-in expired: reconnect required',
                'message' => 'PSA could not refresh its delegated CIPP MCP sign-in (error code: '.$errorCode.'). '
                    .'CIPP itself may be fine; this is the stored connection. Reconnect at '.self::RECONNECT_ACTION.'. '
                    .'Until then the curated cipp_* reads are served over the REST API and catalog tools are unavailable.',
                'metadata' => ['error_code' => $errorCode, 'action' => self::RECONNECT_ACTION],
            ]);
            Setting::setValue(self::ALERT_ID, (string) $alert->id);
        } catch (\Throwable $e) {
            // Alerting must never turn a failed sign-in into a failed read.
            Log::error('[CippMcpConnector] Could not raise the refresh-failure alert', ['exception' => class_basename($e)]);
        }
    }

    /**
     * Atomically start a failure episode. True for exactly one caller per episode,
     * even when two reads fail at once: the claim is a conditional UPDATE on the
     * null marker (or the unique-key INSERT of it), never a read-then-write.
     */
    private function openEpisode(): bool
    {
        $now = now()->toIso8601String();
        if (Setting::where('key', self::FAILED_AT)->whereNull('value')->update(['value' => $now]) === 1) {
            return true;
        }
        if (Setting::where('key', self::FAILED_AT)->exists()) {
            return false;
        }

        try {
            Setting::create(['key' => self::FAILED_AT, 'value' => $now]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Panel status; `state` is not_connected | connected | refresh_failed.
     *
     * @return array{state: string, upn: ?string, connected_at: ?string, access_expires_at: ?string, failed_at: ?string, error_code: ?string}
     */
    public function status(): array
    {
        $connected = $this->isConnected();
        $failedAt = $connected ? Setting::getValue(self::FAILED_AT) : null;

        return [
            'state' => ! $connected ? 'not_connected' : ($failedAt !== null ? 'refresh_failed' : 'connected'),
            'upn' => $connected ? Setting::getValue(self::UPN) : null,
            'connected_at' => $connected ? Setting::getValue(self::CONNECTED_AT) : null,
            'access_expires_at' => $connected ? Setting::getValue(self::ACCESS_EXPIRES_AT) : null,
            'failed_at' => $failedAt,
            'error_code' => $failedAt !== null ? Setting::getValue(self::FAILED_CODE) : null,
        ];
    }

    // --- helpers -------------------------------------------------------------

    /**
     * The vendor's OAuth error CODE, never its body: the first AADSTS number from
     * error_codes / error_description when present, else the `error` field, else
     * the HTTP status.
     */
    public static function errorCode(Response $response): string
    {
        $codes = $response->json('error_codes');
        if (is_array($codes) && isset($codes[0]) && is_numeric($codes[0])) {
            $aadsts = 'AADSTS'.(int) $codes[0];
        } elseif (preg_match('/\bAADSTS\d{4,}\b/', (string) $response->json('error_description'), $m)) {
            $aadsts = $m[0];
        }

        $error = $response->json('error');
        $error = is_string($error) ? self::sanitizeCode($error) : '';

        $parts = array_values(array_filter([$error !== 'unknown' ? $error : '', $aadsts ?? '']));

        return $parts !== [] ? implode(' / ', $parts) : 'HTTP '.$response->status();
    }

    /** Keep a code to a short identifier; nothing that could carry a token. */
    public static function sanitizeCode(string $code): string
    {
        $code = preg_replace('/[^A-Za-z0-9_ .\/-]/', '', $code) ?? '';
        $code = mb_substr(trim($code), 0, 80);

        return $code !== '' ? $code : 'unknown';
    }

    /**
     * The signed-in account's UPN from the id_token's payload. Display only: the
     * token came straight from Microsoft's token endpoint over TLS, so it is not
     * re-verified here, and nothing authorises on it.
     */
    public static function upnFromIdToken(mixed $idToken): ?string
    {
        if (! is_string($idToken) || substr_count($idToken, '.') < 2) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/'), true), true);
        if (! is_array($payload)) {
            return null;
        }

        foreach (['preferred_username', 'upn', 'email'] as $claim) {
            if (is_string($payload[$claim] ?? null) && $payload[$claim] !== '') {
                return mb_substr($payload[$claim], 0, 255);
            }
        }

        return null;
    }
}
