<?php

namespace App\Services\Cipp;

use App\Models\Client;

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
 *     rows could not be attributed to one client.
 */
final class CippTenantScope
{
    public const KEY_NOTE = 'Tenant scope: client_id is the primary key (on a ticket, the ticket\'s client). The CIPP tenant is resolved from that client\'s stored CIPP mapping (clients.cipp_tenant_domain); there is no tenant-name fallback, and a tenant name or domain cannot be passed. A client not mapped to CIPP, or a tenant mapped to more than one PSA client, is an error, never a cross-client read.';

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
