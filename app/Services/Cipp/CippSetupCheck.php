<?php

namespace App\Services\Cipp;

use App\Support\CippConfig;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

/**
 * "Check setup" on the CIPP panel (card 6abd5c73 / jOWYaBuZ): every prerequisite
 * of Connect CIPP MCP, checked in one click, with ONE plain-English fix line per
 * problem and the exact portal click-path.
 *
 * Every line is pass, fail or cant_check. A check the PSA cannot run is reported
 * as cant_check with the manual step, never as a pass: unable to assess is not
 * a pass.
 *
 * What this cannot see: the MCP client app's own registration in Entra (does the
 * client ID resolve, is the PSA callback registered, are public client flows on).
 * The PSA has no existing call that reads an app registration, and this check
 * adds no Graph permission, grant or credential to get one, so those three lines
 * are always cant_check with the Entra click-path.
 *
 * Nothing here echoes a vendor response body or exception message: a failed
 * Test Connection is reported by exception class only.
 */
class CippSetupCheck
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const CANT_CHECK = 'cant_check';

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var \Closure(array<string, mixed>): CippClient */
    private \Closure $restClientFactory;

    /**
     * @param  (\Closure(array<string, mixed>): CippClient)|null  $restClientFactory  test seam; the default is
     *                                                                                the same client testCipp builds
     */
    public function __construct(?\Closure $restClientFactory = null)
    {
        $this->restClientFactory = $restClientFactory
            ?? fn (array $config): CippClient => new CippClient($config, app(CacheInterface::class));
    }

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function run(string $redirectUri): array
    {
        $tenantId = trim((string) CippConfig::get('tenant_id'));
        $clientId = trim((string) CippConfig::get('mcp_client_id'));
        $clientRef = preg_match(self::GUID, $clientId) ? $clientId : '<your MCP client ID>';
        $hasSecret = (string) CippConfig::get('mcp_client_secret') !== '';

        return [
            $this->tenantLine($tenantId),
            $this->clientIdLine($clientId),
            $this->backendHostLine((string) CippConfig::get('mcp_backend_host')),
            $this->redirectUriLine($redirectUri),
            self::line('client_resolves', 'MCP client ID exists in Entra', self::CANT_CHECK,
                "Can't check automatically: open Entra → App registrations → All applications, search for {$clientRef} and confirm it is listed."),
            self::line('redirect_registered', 'Callback registered on the MCP client app', self::CANT_CHECK,
                "Can't check automatically: open Entra → App registrations → {$clientRef} → Authentication and confirm {$redirectUri} is listed under "
                .($hasSecret ? 'Web (a secret is stored, so the PSA signs in as a confidential client).' : 'Mobile and desktop applications.')),
            $hasSecret
                ? self::line('public_client_flows', 'Public client flows', self::CANT_CHECK,
                    "Can't check automatically: a secret is stored, so a Web redirect is used and public client flows are not needed. To use a Mobile and desktop redirect instead, tick Remove stored MCP client secret and save, then open Entra → App registrations → {$clientRef} → Authentication and confirm Allow public client flows is Yes.")
                : self::line('public_client_flows', 'Public client flows', self::CANT_CHECK,
                    "Can't check automatically: open Entra → App registrations → {$clientRef} → Authentication → Advanced settings and confirm Allow public client flows is Yes."),
            $this->testConnectionLine(),
        ];
    }

    private function tenantLine(string $tenantId): array
    {
        $label = 'Azure AD Tenant ID';
        if ($tenantId === '') {
            return self::line('tenant_id', $label, self::FAIL,
                'Not set. Copy it from Entra → Overview → Tenant ID into Azure AD Tenant ID on this panel and save.');
        }
        if (! preg_match(self::GUID, $tenantId)) {
            return self::line('tenant_id', $label, self::FAIL,
                'Not a tenant ID (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx). Copy it from Entra → Overview → Tenant ID and save.');
        }

        return self::line('tenant_id', $label, self::PASS, 'Set and well-formed.');
    }

    private function clientIdLine(string $clientId): array
    {
        $label = 'MCP Client ID';
        $where = 'Copy the MCP client app\'s ID from CIPP → Integrations → CIPP-API (the client with MCP Access), or Entra → App registrations → that app → Overview → Application (client) ID, into MCP Client ID on this panel and save.';
        if ($clientId === '') {
            return self::line('mcp_client_id', $label, self::FAIL, 'Not set. '.$where);
        }
        if (! preg_match(self::GUID, $clientId)) {
            return self::line('mcp_client_id', $label, self::FAIL, 'Not a client ID (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx). '.$where);
        }

        return self::line('mcp_client_id', $label, self::PASS, 'Set and well-formed.');
    }

    private function backendHostLine(string $stored): array
    {
        $label = 'MCP backend host';
        $where = 'Entra → App registrations → All applications → CIPP-MCP → Expose an API → Application ID URI';
        $bare = CippMcpConnector::normaliseBackendHost($stored);
        if ($bare === '') {
            return self::line('backend_host', $label, self::FAIL, "Not set. Copy it from {$where} into MCP backend host on this panel and save.");
        }
        if ($bare !== $stored) {
            return self::line('backend_host', $label, self::FAIL,
                "The saved value has extra characters after the host. It should be just {$bare} (from {$where}). Paste it into MCP backend host and save; saving strips the rest.");
        }

        return self::line('backend_host', $label, self::PASS, "Set to the bare Application ID URI {$bare}.");
    }

    private function redirectUriLine(string $redirectUri): array
    {
        $label = 'PSA callback URL';
        $host = (string) parse_url($redirectUri, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME));
        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return self::line('redirect_uri', $label, self::FAIL,
                "The PSA would send {$redirectUri}, and Entra accepts only https callbacks outside localhost. Set APP_URL to the PSA's https address.");
        }

        return self::line('redirect_uri', $label, self::PASS, "The PSA sends {$redirectUri}. Copy it exactly into the MCP client app's redirect URIs.");
    }

    private function testConnectionLine(): array
    {
        $label = 'Test Connection (CIPP REST API)';
        if (! CippConfig::isConfigured()) {
            return self::line('test_connection', $label, self::FAIL,
                'CIPP API URL, Tenant ID, Client ID and Client Secret are not all saved. Copy them from CIPP → Integrations → CIPP-API into this panel and save.');
        }

        try {
            $client = ($this->restClientFactory)([
                'api_url' => CippConfig::get('api_url'),
                'tenant_id' => CippConfig::get('tenant_id'),
                'client_id' => CippConfig::get('client_id'),
                'client_secret' => CippConfig::get('client_secret'),
                'application_id' => CippConfig::get('application_id'),
            ]);
            $tenants = $client->listTenants();
        } catch (\Throwable $e) {
            return self::line('test_connection', $label, self::FAIL,
                'The PSA could not sign in to or read from the CIPP API ('.class_basename($e).'). Check the CIPP API URL, Client ID and Client Secret against CIPP → Integrations → CIPP-API, save, and run Test Connection.');
        }

        return self::line('test_connection', $label, self::PASS, 'Connected; CIPP listed '.count($tenants).' tenant(s).');
    }

    /** @return array{key: string, label: string, status: string, message: string} */
    private static function line(string $key, string $label, string $status, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    }
}
