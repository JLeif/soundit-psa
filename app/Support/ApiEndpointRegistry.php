<?php

namespace App\Support;

/**
 * The single source of truth for the PSA REST API surface.
 *
 * Every route behind the `api.token` middleware is named
 * `api.v1.<endpoint>` (canonical, /api/v1/<path>) or `api.rmm.<endpoint>`
 * (a compatibility alias, /api/<alias>). Both names resolve to ONE entry here
 * and ONE grant on the token, so granting `clients.read` covers both paths.
 * The alias routes exist so the LITS RMM client, which hard-codes /api/rmm/*,
 * works unchanged.
 *
 * The grant screen renders from this list and the middleware refuses any
 * route whose name does not resolve to an entry. ApiEndpointRegistryTest holds
 * the route table and this list in a bijection.
 */
final class ApiEndpointRegistry
{
    public const CANONICAL_ROUTE_PREFIX = 'api.v1.';

    public const ALIAS_ROUTE_PREFIX = 'api.rmm.';

    /**
     * @return array<string, array{method: string, path: string, aliases: array<int, string>, group: string, access: string, description: string}>
     */
    public static function all(): array
    {
        return [
            'clients.read' => [
                'method' => 'GET',
                'path' => 'v1/clients',
                'aliases' => ['rmm/clients'],
                'group' => 'directory',
                'access' => 'read',
                'description' => 'Every client with is_active and its Huntress, Control D and Tactical ids. Not paginated; carries a count.',
            ],
            'assets.read' => [
                'method' => 'GET',
                'path' => 'v1/assets',
                'aliases' => ['rmm/assets'],
                'group' => 'directory',
                'access' => 'read',
                'description' => 'Every asset: client, hostname, serial (raw), type, OS, is_active, last seen.',
            ],
            'alerts.leif_rmm.raise' => [
                'method' => 'POST',
                'path' => 'v1/alerts/leif-rmm',
                'aliases' => ['rmm/alerts'],
                'group' => 'alerts',
                'access' => 'write',
                'description' => 'Raise or re-fire a Leif RMM alert. Dedupe key (source, source_alert_id); a resolved key is revived, not duplicated. Refused (422) if the key belongs to another client.',
            ],
            'alerts.leif_rmm.resolve' => [
                'method' => 'POST',
                'path' => 'v1/alerts/leif-rmm/resolve',
                'aliases' => ['rmm/alerts/resolve'],
                'group' => 'alerts',
                'access' => 'write',
                'description' => 'Resolve the open alert under a source_alert_id. An unknown or already-resolved key succeeds with resolved:false.',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, blurb: string, icon: string, accent: string}>
     */
    public static function groups(): array
    {
        return [
            'directory' => [
                'label' => 'Directory',
                'blurb' => 'Clients and assets, read-only. The PSA owns identity; consumers mirror it.',
                'icon' => 'bi-diagram-3',
                'accent' => '#1a365d',
            ],
            'alerts' => [
                'label' => 'Alerts',
                'blurb' => 'Raise and resolve Alerts Hub alerts. Alerts, never tickets; a human promotes them.',
                'icon' => 'bi-bell',
                'accent' => '#b45309',
            ],
        ];
    }

    public static function has(string $endpoint): bool
    {
        return array_key_exists($endpoint, self::all());
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * The registry entry name a route name resolves to, or null when the route
     * name is not in the api.v1./api.rmm. family or names no registered entry.
     */
    public static function endpointForRouteName(?string $routeName): ?string
    {
        if (! is_string($routeName)) {
            return null;
        }

        foreach ([self::CANONICAL_ROUTE_PREFIX, self::ALIAS_ROUTE_PREFIX] as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                $endpoint = substr($routeName, strlen($prefix));

                return self::has($endpoint) ? $endpoint : null;
            }
        }

        return null;
    }

    /**
     * Entries grouped for the grant screen, in groups() order.
     *
     * @return array<string, array{label: string, blurb: string, icon: string, accent: string, endpoints: array<string, array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::groups() as $key => $group) {
            $out[$key] = $group + ['endpoints' => []];
        }
        foreach (self::all() as $name => $entry) {
            $out[$entry['group']]['endpoints'][$name] = $entry;
        }

        return $out;
    }
}
