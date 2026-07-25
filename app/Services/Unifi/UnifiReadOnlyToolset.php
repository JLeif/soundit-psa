<?php

namespace App\Services\Unifi;

use App\Models\Client;
use App\Models\ClientUnifiSite;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\UnifiConfig;
use Illuminate\Support\Facades\Log;

/**
 * UniFi read-only network telemetry tools for the staff MCP surface (psa-1ynqc),
 * MULTI-SITE per client (psa-jpygj).
 *
 * Motivating incident: T-22724, a Comcast WAN fault whose root cause required a human
 * to open the UniFi console by hand because the agent had no way to see WAN state.
 *
 * SHAPE SOURCE: every field name below comes from the vendor's own OpenAPI spec —
 * https://developer.ui.com/site-manager/v1.0.0/openapi.json — never from docs prose or
 * inference. See UnifiClient's docblock for the four shape facts that matter and
 * tests/Fixtures/unifi/*.json for the vendor payloads the tests assert against.
 *
 * MULTI-SITE: a PSA client can map to MANY UniFi sites (several physical locations).
 * Mappings live in client_unifi_sites (client_id, unifi_site_id UNIQUE so a site still
 * maps to <=1 client, unifi_host_id), NEVER on the client row. The client-scoped tools
 * AGGREGATE across a client's sites and present ONE consistent shape whatever the count:
 * a per-site array (site_count + sites[]) for the site-grained tools, and a flat device
 * list where each row is tagged with its console for the host-grained one.
 *
 * DATA-BOUNDARY RULE (mirrors HuntressReadOnlyToolset — a UI account can administer
 * consoles belonging to more than one client, and in principle more than one MSP):
 *  - Site METADATA is account-wide and annotated with its mapped PSA client (or null),
 *    so a human can discover what still needs mapping. Metadata ONLY — no telemetry.
 *  - TELEMETRY (health, devices, ISP metrics) is MAPPED-SITES-ONLY, resolved from the
 *    caller client's own client_unifi_sites rows — never from tool input.
 *
 * TWO SCOPING HAZARDS THE UPSTREAM API CREATES, both handled here rather than papered
 * over — read these before changing any filter:
 *  1. GET /v1/isp-metrics/{type} accepts NO site filter. It returns one row per visible
 *     site, each tagged {hostId, siteId}. We filter to the caller's SET of sites
 *     ourselves; handing the response back unfiltered would leak every other client's
 *     WAN data.
 *  2. GET /v1/devices is grouped by HOST and carries NO siteId anywhere. A device is
 *     therefore only attributable to a client through its console, and ONLY when every
 *     UniFi site that console serves belongs to THAT ONE client. unifi_list_devices
 *     proves that upstream (siteIdsByHost) before returning anything and skips any
 *     console that fails it.
 *     The test that matters: counting how many PSA CLIENTS share the console is NOT
 *     sufficient — a console carrying two UniFi sites where only one is this client's
 *     passes that check, and every device on the console would then be returned under
 *     the client. The question is which SITES the console serves, not how many of them
 *     we happen to have mapped. (Caught in review as psa-51mhv R1.) A console serving
 *     several sites is fine IFF they are all this client's (psa-jpygj) — attribution to
 *     the client is then unambiguous even though devices cannot be split between sites.
 *
 * READ-ONLY. The spec also exposes /v1/connector/consoles/{id}/*path — a generic
 * passthrough to a console's local Network API supporting POST/PUT/PATCH/DELETE. It is
 * deliberately absent here and from UnifiClient; exposing a runtime-chosen path to an
 * agent is a separate decision with its own review, not a detail of a telemetry PR.
 */
class UnifiReadOnlyToolset
{
    private const GENERAL_TOOL_NAMES = [
        'unifi_list_sites',
    ];

    private const CLIENT_TOOL_NAMES = [
        'unifi_get_site_health',
        'unifi_list_devices',
        'unifi_get_isp_metrics',
    ];

    /** Bounds the page walk when locating a client's site by id. */
    private const MAX_SITE_LOOKUP_PAGES = 20;

    /**
     * Read freshness (psa-47vxh). The Site Manager API serves CACHED telemetry:
     * when a console goes dark its last report is frozen and handed back on
     * unchanged, so a nine-months-dark site reads as a confident "up"/"online".
     * We surface the vendor's own last-report time + a computed staleness flag so
     * that never travels as current truth — the psa-wedk RMM last-seen staleness
     * idiom, on this read surface. A console reports continuously, so no report in
     * this long means it is almost certainly offline (conservative enough not to
     * false-flag a healthy console). A missing/unparseable time fails to STALE.
     */
    private const STALE_AFTER_HOURS = 24;

    private const DEVICE_FRESHNESS_NOTE = 'reported_at is the UniFi Site Manager updatedAt for a console\'s device data; it stops advancing when the console goes offline, so a stale reading means the console may be dark and these device states are last-known, not live. data_as_of is the oldest console\'s report across this result; data_stale is true when it is older than '.self::STALE_AFTER_HOURS.'h or unknown.';

    /**
     * Site health has no last-report of its own: the Site Manager /sites
     * statistics block carries NO last-contact timestamp (verified against the
     * vendor payload), so a dark console\'s cached wanUptime/counts read as
     * healthy with nothing on THIS endpoint to tell. Rather than compute a false
     * fresh/stale, we flag freshness unverifiable (data_as_of/data_stale null) and
     * point at unifi_list_devices, which DOES carry per-console reported_at.
     */
    private const SITE_HEALTH_FRESHNESS_NOTE = 'UniFi Site Manager provides no last-contact timestamp for site statistics, so freshness here is UNVERIFIABLE (data_as_of and data_stale are null): wan_uptime_percent and counts are the console\'s last-cached values and read as healthy even if the console is offline. To confirm a site is actually reporting, call unifi_list_devices for this client and check each console\'s reported_at / stale.';

    /**
     * Durations the vendor documents per interval, first entry = default. 5-minute
     * samples are retained at least 24h; 1-hour samples at least 30 days. Source:
     * the `duration` parameter description in the Site Manager OpenAPI spec.
     */
    private const DURATIONS_BY_TYPE = [
        '5m' => ['24h'],
        '1h' => ['7d', '30d'],
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return array_merge(self::generalDefinitions(), self::clientDefinitions());
    }

    /** @return array<int, array<string, mixed>> */
    public static function generalDefinitions(): array
    {
        return [
            [
                'name' => 'unifi_list_sites',
                'description' => 'List UniFi sites across every console the UniFi account administers, each annotated with its mapped PSA client (or null when unmapped). Use this to resolve a PSA client to its UniFi site(s) — a client with several locations maps to several sites — and to discover sites that still need mapping. Returns site metadata only — no health, ISP or device data; use unifi_get_site_health for a mapped client.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max sites to return (default 50, max 100).'],
                        'page_token' => ['type' => 'string', 'description' => 'Opaque cursor from a previous response next_page_token.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'unifi_get_site_health',
                'description' => 'Get current network health for every UniFi site mapped to a PSA client — a client with several locations returns one entry per site: ISP name, WAN uptime percentage, any open internet issues, gateway model, and device counts including how many are offline or awaiting a firmware update. Start here when a client reports an internet or site-wide network problem. Results are a per-site array (site_count + sites[]). NOTE: these are cached figures with NO vendor freshness stamp (data_stale is null = unverifiable) — a dark console still reads as healthy here, so confirm the site is actually reporting via unifi_list_devices (its reported_at/stale) before trusting an "up" reading.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to at least one UniFi site.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'unifi_list_devices',
                'description' => "List UniFi devices (gateways, switches, access points) across a PSA client's console(s) with their up/down status, model, IP, firmware status and uptime. Use this to find which access point or switch is offline. Each device is tagged with the console (host_id) it belongs to; any console that cannot be safely attributed to this client alone is reported under skipped rather than guessed at. Each device and console carries reported_at + a stale flag, and the payload carries data_as_of/data_stale: a stale console is one UniFi has not heard from recently, so its device statuses are last-known, NOT live — do not read a stale console's devices as currently online.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a UniFi site and console.'],
                        'status' => ['type' => 'string', 'description' => "Optional filter on device status, e.g. 'online' or 'offline'."],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'unifi_get_isp_metrics',
                'description' => "Get WAN/ISP telemetry over time for each UniFi site mapped to a PSA client: average and peak latency, packet loss, downtime, and throughput per sample period, plus the ISP name and ASN. Use this to evidence or rule out an ISP fault — it answers 'was the internet actually down, and when'. Results are a per-site array (site_count + sites[], each with its periods).",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a UniFi site.'],
                        'type' => ['type' => 'string', 'description' => "Sample interval: '5m' (retained at least 24h) or '1h' (retained at least 30 days). Defaults to 5m."],
                        'duration' => ['type' => 'string', 'description' => "Window ending now: '24h' for 5m samples, or '7d'/'30d' for 1h samples. Mutually exclusive with begin_timestamp/end_timestamp. Defaults to 24h for 5m and 7d for 1h."],
                        'begin_timestamp' => ['type' => 'string', 'description' => 'RFC3339 start, e.g. 2026-07-23T13:35:00Z. Use with end_timestamp instead of duration.'],
                        'end_timestamp' => ['type' => 'string', 'description' => 'RFC3339 end. Use with begin_timestamp instead of duration.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::GENERAL_TOOL_NAMES, true)
            || in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        // OFF=OFF: the master switch withdraws the capability, not just the syncs.
        if (! UnifiConfig::isAvailable()) {
            return ['error' => 'UniFi is not available in this deployment — it is either switched off or has no API key configured.'];
        }

        return match ($toolName) {
            'unifi_list_sites' => $this->listSites($input),
            'unifi_get_site_health' => $this->getSiteHealth($input, $clientId),
            'unifi_list_devices' => $this->listDevices($input, $clientId),
            'unifi_get_isp_metrics' => $this->getIspMetrics($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── mapping helper (account-wide METADATA only) ────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listSites(array $input): array
    {
        $params = ['pageSize' => (string) $this->limit($input, default: 50, max: 100)];

        $token = trim((string) ($input['page_token'] ?? ''));
        if ($token !== '') {
            $params['nextToken'] = $token;
        }

        try {
            $response = $this->client()->listSites($params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $mapped = $this->mappedClientsBySiteId();

        $sites = [];
        foreach ($this->rows($response) as $row) {
            $siteId = $this->scalarOrNull($row['siteId'] ?? null);
            if ($siteId === null) {
                continue;
            }
            $client = $mapped->get($siteId);

            // METADATA ONLY. `statistics` is deliberately not projected here: an
            // unmapped row belongs to a site we have not associated with a client, and
            // its health data must not ride along on the mapping helper.
            $sites[] = [
                'site_id' => $siteId,
                'host_id' => $this->scalarOrNull($row['hostId'] ?? null),
                'name' => $this->textSanitizer->sanitizeNullable('UniFi site name', $row['meta']['name'] ?? null, 200),
                'description' => $this->textSanitizer->sanitizeNullable('UniFi site description', $row['meta']['desc'] ?? null, 300),
                'timezone' => $this->scalarOrNull($row['meta']['timezone'] ?? null),
                'permission' => $this->scalarOrNull($row['permission'] ?? null),
                'is_owner' => (bool) ($row['isOwner'] ?? false),
                'psa_client_id' => $client?->id,
                'psa_client_name' => $client?->name,
            ];
        }

        return [
            'count' => count($sites),
            'sites' => $sites,
            'next_page_token' => $this->nextPageToken($response),
        ];
    }

    // ── telemetry (mapped-sites-only), aggregated across a client's sites ───────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getSiteHealth(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        $mappings = $client->unifiSites;
        $targetIds = $mappings->pluck('unifi_site_id')->all();

        try {
            // One bounded sites walk covers every site the client maps.
            $rows = $this->collectSiteRows($targetIds);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        $sites = [];
        foreach ($mappings as $mapping) {
            $row = $rows[$mapping->unifi_site_id] ?? null;

            if ($row === null) {
                // Natural cursor exhaustion without this site = it is genuinely gone
                // upstream (cap exhaustion would have thrown above, not landed here).
                // Surface the gap per-site rather than sinking the whole read.
                $sites[] = [
                    'site_id' => $mapping->unifi_site_id,
                    'error' => "UniFi site {$mapping->unifi_site_id} was not found on this UniFi account.",
                ];

                continue;
            }

            $stats = is_array($row['statistics'] ?? null) ? $row['statistics'] : [];

            $sites[] = [
                'site_id' => $this->scalarOrNull($row['siteId'] ?? null),
                'host_id' => $this->scalarOrNull($row['hostId'] ?? null),
                'site_name' => $this->textSanitizer->sanitizeNullable('UniFi site name', $row['meta']['name'] ?? null, 200),
                'isp_name' => $this->textSanitizer->sanitizeNullable('UniFi ISP name', $stats['ispInfo']['name'] ?? null, 200),
                'isp_organization' => $this->textSanitizer->sanitizeNullable('UniFi ISP organization', $stats['ispInfo']['organization'] ?? null, 200),
                'wan_uptime_percent' => $this->numberOrNull($stats['percentages']['wanUptime'] ?? null),
                // Element shape is unverified — the vendor example carries an empty array —
                // so this is passed through the bounded leaf-sanitizer, never field-projected.
                'internet_issues' => $this->sanitizeStructure('UniFi internet issue', $stats['internetIssues'] ?? []),
                'gateway_model' => $this->scalarOrNull($stats['gateway']['shortname'] ?? null),
                'counts' => $this->scalarMap($stats['counts'] ?? null),
            ];
        }

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            // Freshness is unverifiable on this endpoint (psa-47vxh) — the vendor
            // gives no site-level last-contact. Null, never a false fresh/stale;
            // the note points at unifi_list_devices for the real per-console signal.
            'data_as_of' => null,
            'data_stale' => null,
            'freshness_note' => self::SITE_HEALTH_FRESHNESS_NOTE,
            'site_count' => count($sites),
            'sites' => $sites,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listDevices(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        // Group the client's site mappings by console. Device state is reported per
        // console, so a site with no console mapping cannot contribute devices.
        $sitesByHost = [];
        $skipped = [];
        foreach ($client->unifiSites as $mapping) {
            $host = $mapping->unifi_host_id;
            if ($host === null || $host === '') {
                $skipped[] = [
                    'site_ids' => [$mapping->unifi_site_id],
                    'reason' => "site {$mapping->unifi_site_id} has no console (host) mapping, and device state is reported per console",
                ];

                continue;
            }
            $sitesByHost[$host][] = $mapping->unifi_site_id;
        }

        if ($sitesByHost === []) {
            return ['error' => "{$client->name} is mapped to UniFi site(s) but none has a console (unifi_host_id), and device state is reported per console. Set the console mapping to read devices."];
        }

        // GUARD 1 (pure DB, BEFORE any upstream call): a console mapped to ANOTHER PSA
        // client cannot be split (/v1/devices carries no siteId), so skip it. Doing this
        // first means a wholly-shared client never spends a request — and it is what the
        // empty-queue "shared console" test pins.
        $cleanHosts = [];
        foreach ($sitesByHost as $host => $siteIds) {
            $sharedWithOther = ClientUnifiSite::where('unifi_host_id', $host)
                ->where('client_id', '!=', $client->id)
                ->exists();

            if ($sharedWithOther) {
                $skipped[] = [
                    'host_id' => $host,
                    'site_ids' => $siteIds,
                    'reason' => 'that UniFi console is shared with another PSA client, and UniFi does not report a site for each device, so its devices cannot be attributed to a single client',
                ];

                continue;
            }

            $cleanHosts[$host] = $siteIds;
        }

        if ($cleanHosts === []) {
            return ['error' => $this->noConsoleReadableError($client->name, $skipped)];
        }

        $statusFilter = strtolower(trim((string) ($input['status'] ?? '')));

        $devices = [];
        $consoles = [];
        $offline = 0;
        $reportedAts = []; // per-console last-report times, for the freshness envelope

        try {
            // One sites walk proves which UniFi sites each of the client's consoles
            // serves. An upstream failure or cap exhaustion here (or in a device walk
            // below) is an INFRA failure — it hard-errors the whole read rather than
            // returning a partial list wearing a success shape. A per-console
            // ATTRIBUTION refusal, by contrast, is a skip (recorded, never thrown).
            $servedByHost = $this->siteIdsByHost(array_keys($cleanHosts));

            foreach ($cleanHosts as $host => $clientSiteIds) {
                $servedSites = $servedByHost[$host] ?? [];

                if ($servedSites === []) {
                    $skipped[] = [
                        'host_id' => $host,
                        'site_ids' => $clientSiteIds,
                        'reason' => 'no UniFi site was found on that console, so device attribution cannot be confirmed',
                    ];

                    continue;
                }

                // THE BOUNDARY: every site the console serves must belong to THIS client.
                // A console serving any foreign/unmapped site is refused — its hardware
                // cannot be split by site, so returning it would leak the other site's
                // devices. A console serving several of THIS client's sites is fine.
                $foreign = array_values(array_diff($servedSites, $clientSiteIds));
                if ($foreign !== []) {
                    $skipped[] = [
                        'host_id' => $host,
                        'site_ids' => $clientSiteIds,
                        'reason' => count($servedSites) > 1
                            ? 'that UniFi console serves more than one UniFi site ('.count($servedSites).') and at least one is not mapped to this client, so its devices cannot be attributed to this client'
                            : "that UniFi console serves a different UniFi site ({$foreign[0]}) than the one mapped to this client; correct the site/console mapping",
                    ];

                    continue;
                }

                $groups = $this->deviceGroupsForHost($host);
                $hostDevices = $this->client()->flattenDevices($groups);

                // Freshness (psa-47vxh): the console's last report to UniFi's cloud
                // is the /devices host-group updatedAt (the only last-report signal
                // the API exposes). It governs every device on the console.
                $reportedAt = $this->latestReportedAt($groups);
                $reportedAtIso = $reportedAt?->toIso8601ZuluString();
                $reportedAts[] = $reportedAt;

                $hostCount = 0;
                $hostOffline = 0;
                foreach ($hostDevices as $device) {
                    $status = $this->scalarOrNull($device['status'] ?? null);

                    if ($statusFilter !== '' && strtolower((string) $status) !== $statusFilter) {
                        continue;
                    }

                    if (is_string($status) && strtolower($status) !== 'online') {
                        $offline++;
                        $hostOffline++;
                    }

                    $devices[] = [
                        'host_id' => $host,
                        'id' => $this->scalarOrNull($device['id'] ?? null),
                        'mac' => $this->scalarOrNull($device['mac'] ?? null),
                        'name' => $this->textSanitizer->sanitizeNullable('UniFi device name', $device['name'] ?? null, 200),
                        'model' => $this->scalarOrNull($device['model'] ?? null),
                        'status' => $status,
                        'ip' => $this->scalarOrNull($device['ip'] ?? null),
                        'product_line' => $this->scalarOrNull($device['productLine'] ?? null),
                        'firmware_version' => $this->scalarOrNull($device['version'] ?? null),
                        'firmware_status' => $this->scalarOrNull($device['firmwareStatus'] ?? null),
                        'is_console' => (bool) ($device['isConsole'] ?? false),
                        'is_managed' => (bool) ($device['isManaged'] ?? false),
                        'startup_time' => $this->scalarOrNull($device['startupTime'] ?? null),
                        'note' => $this->textSanitizer->sanitizeNullable('UniFi device note', $device['note'] ?? null, 500),
                        // The console's last report — a stale value means this status
                        // is last-known, not live (freshness note on the payload).
                        'reported_at' => $reportedAtIso,
                    ];
                    $hostCount++;
                }

                $consoles[] = [
                    'host_id' => $host,
                    'site_ids' => array_values($clientSiteIds),
                    'count' => $hostCount,
                    'offline_count' => $hostOffline,
                    'reported_at' => $reportedAtIso,
                    'stale' => $this->isStale($reportedAt),
                ];
            }
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // Every console the client has was refused — fail closed with the reasons,
        // never a clean empty device list (this file's "scream, never a false all-clear"
        // rule). Preserves the single-console refusal contract too.
        if ($consoles === []) {
            return ['error' => $this->noConsoleReadableError($client->name, $skipped)];
        }

        $out = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'count' => count($devices),
            'offline_count' => $offline,
            ...$this->freshnessEnvelope($reportedAts, self::DEVICE_FRESHNESS_NOTE),
            'consoles' => $consoles,
            'devices' => $devices,
        ];

        // A partial read must SAY what it could not attribute — a skipped console is a
        // gap the agent has to see, not one to hide behind a clean count.
        if ($skipped !== []) {
            $out['skipped'] = $skipped;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getIspMetrics(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        $type = trim((string) ($input['type'] ?? '5m')) ?: '5m';
        if (! in_array($type, UnifiClient::METRIC_TYPES, true)) {
            return ['error' => "Unsupported interval type '{$type}'. UniFi reports ISP metrics as '5m' (retained at least 24h) or '1h' (retained at least 30 days)."];
        }

        // The vendor's time-window contract is enforced HERE, not left to prose in the
        // schema. Forwarding a bad combination just earns a vendor error that an agent
        // under incident pressure then retries; a crisp local refusal naming the
        // accepted shapes is cheaper and actionable (UX review psa-zsn8p R1).
        $begin = trim((string) ($input['begin_timestamp'] ?? ''));
        $end = trim((string) ($input['end_timestamp'] ?? ''));
        $duration = trim((string) ($input['duration'] ?? ''));
        $hasExplicitWindow = $begin !== '' || $end !== '';

        if ($hasExplicitWindow && $duration !== '') {
            return ['error' => 'duration and begin_timestamp/end_timestamp are mutually exclusive — pass one or the other. Accepted shapes: type=5m with duration=24h; type=1h with duration=7d or 30d; or begin_timestamp+end_timestamp (RFC3339) with duration omitted.'];
        }

        if ($hasExplicitWindow && ($begin === '' || $end === '')) {
            return ['error' => 'An explicit window needs both begin_timestamp and end_timestamp (RFC3339, e.g. 2026-07-23T13:35:00Z). Pass both, or use duration instead.'];
        }

        // Validate the timestamps locally rather than letting a malformed string reach
        // the vendor as a query param (psa-2mgit R2, non-blocking note).
        foreach (['begin_timestamp' => $begin, 'end_timestamp' => $end] as $field => $value) {
            if ($value !== '' && ! $this->isRfc3339($value)) {
                return ['error' => "{$field} must be an RFC3339 timestamp, e.g. 2026-07-23T13:35:00Z. Received: ".mb_substr($value, 0, 40)];
            }
        }

        $params = [];

        if ($hasExplicitWindow) {
            $params['beginTimestamp'] = $begin;
            $params['endTimestamp'] = $end;
        } else {
            $allowed = self::DURATIONS_BY_TYPE[$type];
            $duration = $duration !== '' ? $duration : $allowed[0];

            if (! in_array($duration, $allowed, true)) {
                return ['error' => "duration '{$duration}' is not available at {$type} resolution. UniFi retains ".
                    implode(' or ', $allowed)." for type={$type}; ".
                    ($type === '5m'
                        ? 'use type=1h for windows longer than 24h'
                        : 'use type=5m for a 24h window').
                    ', or pass begin_timestamp+end_timestamp instead.'];
            }

            $params['duration'] = $duration;
        }

        try {
            $response = $this->client()->getIspMetrics($type, $params);
        } catch (\Throwable $e) {
            return $this->apiError($e);
        }

        // Hazard 1: the endpoint has no site filter and returns every visible site.
        // Scope to the caller's SET of sites here — this filter is the data boundary.
        $clientSiteIds = $client->unifiSites->pluck('unifi_site_id');
        $clientSiteSet = array_flip($clientSiteIds->all());

        $periodsBySite = [];
        foreach ($this->rows($response) as $row) {
            $siteId = $row['siteId'] ?? null;
            if (! is_string($siteId) || ! isset($clientSiteSet[$siteId])) {
                continue;
            }

            foreach ((array) ($row['periods'] ?? []) as $period) {
                if (! is_array($period)) {
                    continue;
                }
                $wan = is_array($period['data']['wan'] ?? null) ? $period['data']['wan'] : [];

                $periodsBySite[$siteId][] = [
                    'metric_time' => $this->scalarOrNull($period['metricTime'] ?? null),
                    'avg_latency_ms' => $this->numberOrNull($wan['avgLatency'] ?? null),
                    'max_latency_ms' => $this->numberOrNull($wan['maxLatency'] ?? null),
                    'packet_loss_percent' => $this->numberOrNull($wan['packetLoss'] ?? null),
                    'downtime_seconds' => $this->numberOrNull($wan['downtime'] ?? null),
                    'uptime_seconds' => $this->numberOrNull($wan['uptime'] ?? null),
                    // SNAKE_CASE upstream, among camelCase siblings. Read exactly as the
                    // vendor emits them — see UnifiClient shape fact 3.
                    'download_kbps' => $this->numberOrNull($wan['download_kbps'] ?? null),
                    'upload_kbps' => $this->numberOrNull($wan['upload_kbps'] ?? null),
                    'isp_name' => $this->textSanitizer->sanitizeNullable('UniFi ISP name', $wan['ispName'] ?? null, 200),
                    'isp_asn' => $this->scalarOrNull($wan['ispAsn'] ?? null),
                ];
            }
        }

        $sites = [];
        foreach ($client->unifiSites as $mapping) {
            $sites[] = [
                'site_id' => $mapping->unifi_site_id,
                'periods' => $periodsBySite[$mapping->unifi_site_id] ?? [],
            ];
        }

        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'interval' => $type,
            'site_count' => count($sites),
            'sites' => $sites,
        ];
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a client-scoped tool and prove it is UniFi-mapped.
     * Returns the Client (with its unifiSites loaded on demand), or an error payload
     * array to hand straight back.
     *
     * @param  array<string, mixed>  $input
     * @return Client|array<string, mixed>
     */
    private function resolveMappedClient(array $input, ?int $clientId): Client|array
    {
        $id = $clientId ?? $this->positiveInt($input['client_id'] ?? null);
        if ($id === null) {
            return ['error' => 'client_id is required'];
        }

        $client = Client::find($id);
        if ($client === null) {
            return ['error' => "PSA client {$id} was not found."];
        }

        if ($client->unifiSites()->count() === 0) {
            // Name a remediation that EXISTS (UX rule from psa-zsn8p R1 — this copy
            // once pointed at a screen no build shipped and dead-ended the agent).
            // psa-g5l80 shipped Settings → Integrations → UniFi → Site Mapping.
            return ['error' => "{$client->name} is not mapped to a UniFi site. An operator can map it in Settings → Integrations → UniFi → Site Mapping, which links the client to one or more UniFi sites (and their consoles, needed for device reads) from the live site list. To find the right site first, run unifi_list_sites — it annotates every site with its mapped PSA client."];
        }

        return $client;
    }

    /**
     * Every /v1/devices host-group belonging to one console, walking the cursor.
     *
     * /v1/devices IS paginated (pageSize + nextToken). Reading only the first page and
     * filtering it to our host meant that a console landing on page 2 produced a clean
     * empty device list — the exact "confident empty answer" this file's own docblock
     * forbids. Walk the pages instead.
     *
     * Filtering is done here rather than via the upstream `hostIds[]` query parameter:
     * scoping we perform ourselves is scoping we can test, and the parameter's array
     * encoding is not something to guess at.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deviceGroupsForHost(string $hostId): array
    {
        $groups = [];

        $this->walkPages(
            'devices',
            fn (array $params) => $this->client()->listDevices($params),
            function (array $rows) use ($hostId, &$groups) {
                foreach ($rows as $group) {
                    if (($group['hostId'] ?? null) === $hostId) {
                        $groups[] = $group;
                    }
                }
            },
        );

        return $groups;
    }

    /**
     * Walk a cursor-paginated endpoint, handing each page's rows to $consume.
     *
     * The whole point of this helper is to distinguish NORMAL cursor exhaustion
     * (nextToken null — we saw everything) from CAP exhaustion (we gave up with a
     * cursor still outstanding). Returning what we had in the second case produces a
     * partial answer wearing a success shape, which this surface must never do.
     *
     * For siteIdsByHost() it is worse than cosmetic: a second site on a page we never
     * fetched would leave a console looking single-site, letting the device attribution
     * guard pass and attributing a whole console's hardware to the wrong client. Both
     * the arch and security lanes independently found this (psa-smns1 / psa-2mgit R2),
     * which is why it throws rather than warns.
     *
     * $consume may return false to stop early — that is a satisfied search, not a
     * degraded read, so it does not throw.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $fetch
     * @param  callable(array<int, array<string, mixed>>): (bool|null)  $consume
     */
    private function walkPages(string $label, callable $fetch, callable $consume): void
    {
        $params = ['pageSize' => '100'];

        for ($page = 0; $page < self::MAX_SITE_LOOKUP_PAGES; $page++) {
            $response = $fetch($params);

            if ($consume($this->rows($response)) === false) {
                return;
            }

            $next = $this->nextPageToken($response);
            if ($next === null) {
                return;
            }

            $params['nextToken'] = $next;
        }

        throw new UnifiClientException(
            'UniFi returned more than '.self::MAX_SITE_LOOKUP_PAGES." pages of {$label} and the scan was stopped at that safe limit, ".
            'so this answer would be incomplete. Refusing rather than reporting a partial result.'
        );
    }

    /**
     * Every UniFi site id each of the given consoles serves, from ONE bounded sites
     * walk. Used to prove device attribution is unambiguous before any device is
     * returned — see the boundary note in listDevices().
     *
     * A row on a TARGET console carrying a null/empty site id makes attribution
     * unprovable (it may be a real second site), so it fails closed rather than being
     * skipped (psa-2mgit R2). Cap exhaustion throws via walkPages, for the same reason.
     *
     * @param  array<int, string>  $hostIds
     * @return array<string, array<int, string>> host id => the site ids it serves
     */
    private function siteIdsByHost(array $hostIds): array
    {
        $targets = array_flip($hostIds);
        $byHost = [];

        $this->walkPages(
            'sites',
            fn (array $params) => $this->client()->listSites($params),
            function (array $rows) use ($targets, &$byHost) {
                foreach ($rows as $row) {
                    $hostId = $row['hostId'] ?? null;
                    if (! is_string($hostId) || ! isset($targets[$hostId])) {
                        continue;
                    }

                    $siteId = $row['siteId'] ?? null;
                    if (! is_string($siteId) || $siteId === '') {
                        throw new UnifiClientException(
                            'UniFi returned a site on this console with no usable site id, so device attribution cannot be proven. Refusing the read.'
                        );
                    }

                    $byHost[$hostId][$siteId] = true;
                }

                return null;
            },
        );

        return array_map(fn (array $set) => array_keys($set), $byHost);
    }

    /**
     * Locate the requested site rows by id, walking the cursor a bounded number of
     * pages and stopping as soon as all are found. A target that never appears before
     * natural cursor exhaustion is simply absent from the returned map (genuinely gone);
     * cap exhaustion throws via walkPages so "we didn't finish looking" can never read
     * as "not found".
     *
     * @param  array<int, string>  $siteIds
     * @return array<string, array<string, mixed>> site id => row
     */
    private function collectSiteRows(array $siteIds): array
    {
        $targets = array_flip($siteIds);
        $found = [];

        $this->walkPages(
            'sites',
            fn (array $params) => $this->client()->listSites($params),
            function (array $rows) use ($targets, &$found) {
                foreach ($rows as $row) {
                    $siteId = $row['siteId'] ?? null;
                    if (is_string($siteId) && isset($targets[$siteId])) {
                        $found[$siteId] = $row;
                    }
                }

                return count($found) >= count($targets) ? false : null;
            },
        );

        return $found;
    }

    /** @return \Illuminate\Support\Collection<string, Client> PSA clients keyed by unifi_site_id. */
    private function mappedClientsBySiteId(): \Illuminate\Support\Collection
    {
        // Source of truth is the pivot (client_unifi_sites); a client may key several
        // rows. Joining through Client::query() keeps the soft-delete scope, so a
        // trashed client's sites read as unmapped — same as before the pivot.
        return Client::query()
            ->join('client_unifi_sites', 'client_unifi_sites.client_id', '=', 'clients.id')
            ->get(['clients.id', 'clients.name', 'client_unifi_sites.unifi_site_id'])
            ->keyBy('unifi_site_id');
    }

    /**
     * The fail-closed error for a device read where no console could be attributed —
     * always names "console" and folds in every per-console reason, so the record can
     * never read as a clean all-clear.
     *
     * @param  array<int, array<string, mixed>>  $skipped
     */
    private function noConsoleReadableError(string $clientName, array $skipped): string
    {
        $reasons = array_map(
            fn (array $s) => (isset($s['host_id']) ? $s['host_id'].': ' : '').($s['reason'] ?? 'unavailable'),
            $skipped,
        );

        return "No UniFi console could be read for {$clientName}. ".implode('; ', $reasons).'.';
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    private function client(): UnifiClient
    {
        return app(UnifiClient::class);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $response): array
    {
        $rows = $response['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $response */
    private function nextPageToken(array $response): ?string
    {
        $token = $response['nextToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @param array<string, mixed> $input */
    private function limit(array $input, int $default, int $max): int
    {
        $limit = $this->positiveInt($input['limit'] ?? null) ?? $default;

        return max(1, min($limit, $max));
    }

    /**
     * RFC3339 as the vendor spells it in its examples: 2024-06-30T13:35:00Z, with an
     * optional fractional part and either Z or a numeric offset. Parsed as well as
     * pattern-matched so 2026-02-31T00:00:00Z is rejected rather than forwarded.
     */
    private function isRfc3339(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        return \DateTime::getLastErrors() === false || (\DateTime::getLastErrors()['warning_count'] ?? 0) === 0;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    // ── freshness (psa-47vxh) ──────────────────────────────────────────────────

    /** Parse a vendor ISO8601 timestamp, or null when absent/unparseable. */
    private function parseTimestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null; // an unreadable time is unknown, not fresh
        }
    }

    /** A report time is stale when it is missing or older than the threshold. */
    private function isStale(?\Illuminate\Support\Carbon $reportedAt): bool
    {
        return $reportedAt === null || $reportedAt->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * The newest updatedAt across a host's device groups (a host normally maps to
     * one group, but pagination can split it), or null if none is parseable.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function latestReportedAt(array $groups): ?\Illuminate\Support\Carbon
    {
        $latest = null;
        foreach ($groups as $group) {
            $ts = $this->parseTimestamp($group['updatedAt'] ?? null);
            if ($ts !== null && ($latest === null || $ts->gt($latest))) {
                $latest = $ts;
            }
        }

        return $latest;
    }

    /**
     * The payload-level freshness envelope for a set of per-item report times.
     * data_as_of is the OLDEST known report — the freshest the WHOLE reading can
     * honestly claim — and data_stale is true when that is beyond the threshold OR
     * any item's time is unknown (a console that never reported can hide a dark
     * site behind a fresh sibling, so it fails the set to stale, never fresh).
     *
     * @param  array<int, ?\Illuminate\Support\Carbon>  $reportedAts
     * @return array{data_as_of: ?string, data_stale: bool, freshness_note: string}
     */
    private function freshnessEnvelope(array $reportedAts, string $note): array
    {
        $hasUnknown = in_array(null, $reportedAts, true);

        $oldest = null;
        foreach ($reportedAts as $ts) {
            if ($ts !== null && ($oldest === null || $ts->lt($oldest))) {
                $oldest = $ts;
            }
        }

        return [
            'data_as_of' => $oldest?->toIso8601ZuluString(),
            'data_stale' => $hasUnknown || $this->isStale($oldest),
            'freshness_note' => $note,
        ];
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }

    /**
     * Bounded recursive leaf-sanitizer for untrusted nested structures whose element
     * shape we have not verified against a real payload (internetIssues). String leaves
     * are redacted and fenced; scalars pass through; depth and breadth are capped.
     */
    private function sanitizeStructure(string $label, mixed $value, int $maxDepth = 4, int $maxItems = 30): mixed
    {
        if (is_string($value)) {
            return $this->textSanitizer->sanitizeNullable($label, $value, 500);
        }

        if (! is_array($value) || $maxDepth <= 0) {
            return is_array($value) ? '[truncated]' : $this->scalarOrNull($value);
        }

        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count++ >= $maxItems) {
                $out['_truncated'] = true;
                break;
            }
            $out[$key] = $this->sanitizeStructure($label, $item, $maxDepth - 1, $maxItems);
        }

        return $out;
    }

    private function apiError(\Throwable $e): array
    {
        Log::warning('[UniFi reads] query failed', ['error' => $e->getMessage()]);

        return ['error' => 'UniFi query failed: '.mb_substr($e->getMessage(), 0, 200)];
    }
}
