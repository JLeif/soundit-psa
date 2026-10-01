<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a CIPP read learns which tenant it may touch (card 6abdcac2, the
 * Huntress pattern from #4486 applied to CIPP).
 *
 * client_id is the key. It resolves through the client's stored integration column,
 * clients.cipp_tenant_domain, and through nothing else: there is no tenant-name
 * fallback, because a tenant the agent types is not bound to any PSA client. Every
 * CIPP read path consults this resolver — the direct REST path and the MCP relay
 * (HandlesCippTools / CippMcpToolRelay) and the dynamic catalog executor — so the
 * three cannot drift into different answers for the same client.
 *
 * Fails closed, with an error that says why, never with a cross-client read:
 *   - no client_id at all;
 *   - a client_id that does not resolve to the client the caller holds;
 *   - a client not mapped to CIPP;
 *   - a tenant key another PSA client also carries (compared trimmed and
 *     case-insensitively, the way CIPP's Get-Tenants matches a domain), because the
 *     rows could not be attributed to one client;
 *   - a tenant another PSA client carries under ANOTHER of its aliases (customerId,
 *     defaultDomainName, initialDomainName: the keys Get-Tenants matches), checked
 *     against CIPP's tenant list whenever any other PSA client is mapped;
 *   - that tenant list unreadable, or not naming the client's tenant exactly once,
 *     because then the alias check above could not be made.
 */
final class CippTenantScope
{
    public const KEY_NOTE = 'Tenant scope: client_id is the primary key (on a ticket, the ticket\'s client). The CIPP tenant is resolved from that client\'s stored CIPP mapping (clients.cipp_tenant_domain); there is no tenant-name fallback, and a tenant name or domain cannot be passed. A client not mapped to CIPP, or a tenant mapped to more than one PSA client, is an error, never a cross-client read.';

    private const TENANT_LIST_CACHE_KEY = 'cipp-tenant-scope:tenant-list';

    private const TENANT_LIST_TTL_SECONDS = 300;

    /**
     * The tenant key to send upstream for this client, or an error payload.
     *
     * @return string|array{error: string}
     */
    public static function resolve(?Client $client, ?int $clientId): string|array
    {
        if ($clientId === null) {
            return ['error' => 'client_id is required: a CIPP read resolves its tenant through the PSA client\'s stored CIPP mapping and does not search tenants by name.'];
        }

        if ($client === null || (int) $client->getKey() !== $clientId) {
            return ['error' => "PSA client {$clientId} was not found."];
        }

        $tenant = trim((string) $client->cipp_tenant_domain);
        if ($tenant === '') {
            return ['error' => "PSA client {$clientId} is not mapped to CIPP (it has no CIPP tenant mapping). Map it in Settings > CIPP Tenants; this read does not search other clients' tenants."];
        }

        if (self::mappedToAnotherClient($tenant, $clientId)) {
            return ['error' => "PSA client {$clientId}'s CIPP tenant is also mapped to another PSA client, so its data cannot be attributed to one client. Fix the duplicate mapping in Settings > CIPP Tenants."];
        }

        $aliasError = self::aliasSharedWithAnotherClient($tenant, $clientId);
        if ($aliasError !== null) {
            return ['error' => $aliasError];
        }

        return $tenant;
    }

    /**
     * Whether any OTHER PSA client's stored tenant equals one of these keys (trimmed,
     * case-insensitive). Used for the client's own key and for every alias CIPP reports
     * for a tenant row.
     *
     * @param  string|array<int, string>  $keys
     */
    public static function mappedToAnotherClient(string|array $keys, int $clientId): bool
    {
        $wanted = array_filter(array_map(
            fn (mixed $key): string => mb_strtolower(trim((string) $key)),
            (array) $keys,
        ), fn (string $key): bool => $key !== '');

        if ($wanted === []) {
            return false;
        }

        return Client::whereKeyNot($clientId)
            ->whereNotNull('cipp_tenant_domain')
            ->pluck('cipp_tenant_domain')
            ->contains(fn (mixed $other): bool => in_array(mb_strtolower(trim((string) $other)), $wanted, true));
    }

    /**
     * The keys CIPP's Get-Tenants matches a tenant row on, trimmed and lowercased.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function tenantAliases(array $row): array
    {
        $aliases = [];
        foreach (['customerId', 'defaultDomainName', 'initialDomainName'] as $key) {
            if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                $aliases[] = mb_strtolower(trim($row[$key]));
            }
        }

        return $aliases;
    }

    /**
     * Whether a ListTenants row is a tenant: non-empty customerId and defaultDomainName
     * strings. CIPP's "could not list tenants" answer carries both keys, empty
     * ({Results: 'Failed to retrieve tenants…', defaultDomainName: '', customerId: ''}).
     */
    public static function isTenantRow(mixed $row): bool
    {
        return is_array($row)
            && is_string($row['customerId'] ?? null) && trim($row['customerId']) !== ''
            && is_string($row['defaultDomainName'] ?? null) && trim($row['defaultDomainName']) !== '';
    }

    /**
     * Why the client's tenant cannot be attributed to it once CIPP's own aliases for
     * that tenant are counted, or null when it can. Client B mapped to Client A's
     * initialDomainName or customerId is A's tenant to CIPP, so comparing stored
     * strings alone would serve A's rows under B. With no other PSA client mapped
     * there is nothing to share, and CIPP is not asked.
     */
    private static function aliasSharedWithAnotherClient(string $tenant, int $clientId): ?string
    {
        $anotherClientMapped = Client::whereKeyNot($clientId)
            ->whereNotNull('cipp_tenant_domain')
            ->pluck('cipp_tenant_domain')
            ->contains(fn (mixed $other): bool => trim((string) $other) !== '');
        if (! $anotherClientMapped) {
            return null;
        }

        $unreadable = "PSA could not read a usable CIPP tenant list, so it cannot check PSA client {$clientId}'s CIPP tenant's other domains against other PSA clients' mappings, and this read was not run.";
        $cached = self::cachedTenantList();
        $tenants = $cached ?? self::readTenantList();
        if ($tenants === null) {
            return $unreadable;
        }

        $matches = self::rowsMatching($tenants, $tenant);
        if (count($matches) !== 1 && $cached !== null) {
            // Settings > CIPP Tenants maps a client from CIPP's live list, so a tenant
            // added since this copy was cached is missing from it. Re-read before the
            // mapping is reported as wrong or ambiguous.
            $tenants = self::readTenantList();
            if ($tenants === null) {
                return $unreadable;
            }
            $matches = self::rowsMatching($tenants, $tenant);
        }

        if (count($matches) !== 1) {
            return count($matches) === 0
                ? "CIPP's tenant list has no tenant matching PSA client {$clientId}'s CIPP mapping, so its other domains cannot be checked against other PSA clients' mappings and this read was not run. Check the mapping in Settings > CIPP Tenants."
                : "CIPP's tenant list has more than one tenant matching PSA client {$clientId}'s CIPP mapping, so it is ambiguous and this read was not run. Fix the mapping in Settings > CIPP Tenants.";
        }

        if (self::mappedToAnotherClient(self::tenantAliases($matches[0]), $clientId)) {
            return "PSA client {$clientId}'s CIPP tenant is also mapped, under another of its domains, to another PSA client, so its data cannot be attributed to one client. Fix the duplicate mapping in Settings > CIPP Tenants.";
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tenants
     * @return array<int, array<string, mixed>>
     */
    private static function rowsMatching(array $tenants, string $tenant): array
    {
        $wanted = mb_strtolower($tenant);

        return array_values(array_filter($tenants, fn (array $row): bool => in_array($wanted, self::tenantAliases($row), true)));
    }

    /**
     * The tenant rows PSA last read from CIPP, or null when none are cached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function cachedTenantList(): ?array
    {
        $cached = Cache::get(self::TENANT_LIST_CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * CIPP's tenant rows read now, or null when they could not be read as tenants.
     * Cached only when usable, so a failed read is retried on the next call.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function readTenantList(): ?array
    {
        try {
            // Over the transport this deployment has: REST when its credentials are set,
            // else ExecMCP, which can run on its own credentials (isMcpRelayEnabled()
            // does not need the REST ones). CIPP's failure object is refused either
            // way: REST get() unwraps its string Results, which the array return type
            // refuses; over MCP it arrives as an answer that is not a list of tenants,
            // refused below.
            if (CippConfig::isConfigured()) {
                $rows = app(CippClient::class)->get('api/ListTenants', []);
            } elseif (CippConfig::isMcpRelayEnabled()) {
                $rows = app(CippMcpClient::class)->callTool('ListTenants', []);
            } else {
                throw new \RuntimeException('neither the CIPP REST API nor CIPP MCP is configured');
            }
        } catch (\Throwable $e) {
            Log::warning('[CippTenantScope] CIPP tenant list could not be read for the alias check', [
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }

        if ($rows === [] || ! array_is_list($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! self::isTenantRow($row)) {
                return null;
            }
        }

        Cache::put(self::TENANT_LIST_CACHE_KEY, $rows, self::TENANT_LIST_TTL_SECONDS);

        return $rows;
    }

    /**
     * A key that selects a tenant (or reads across tenants) on a CIPP endpoint. The
     * dynamic catalog publishes the vendor's own parameter list, and the vendor's read
     * catalog carries tenantFilter / TenantFilter / tenantId / TenantId / customerId /
     * AllTenantSelector / includeAllTenants / ReverseTenantLookup / vendorTenantIds
     * (CIPP-API Config/openapi.json). QueueId is here too: Get-GraphRequestList.ps1
     * reads cached rows by QueueId alone, with no tenant term, so a queue id from
     * another tenant returns that tenant's rows. Matching is case-insensitive because
     * PowerShell's $Request.Query is.
     */
    public static function isTenantSelectorKey(string $key): bool
    {
        return preg_match('/tenant|customerid|^queueid$/i', $key) === 1;
    }
}
