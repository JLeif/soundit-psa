<?php

namespace App\Services\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectEvent;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\ScreenConnectConfig;
use Illuminate\Database\Eloquent\Builder;

/**
 * ScreenConnect read-only session-state tools for the staff MCP surface (psa-mjf6x).
 *
 * Motivating incident: an agent nearly escalated an "unreachable" machine that was
 * merely idle. The ScreenConnect webhook feed already held the connect/disconnect
 * state that distinguishes an IDLE machine (agent connected, nobody using it) from a
 * DEAD one (agent gone) — no tool exposed it.
 *
 * DATA SOURCE: the local webhook-fed snapshot ONLY — assets.screenconnect_* columns
 * and the screenconnect_events table, both written by ScreenConnectSyncService. No
 * live vendor call is made (this integration is webhook-ingest only; there is no
 * outbound ScreenConnect API client in this codebase), so every answer is exactly as
 * fresh as the last webhook — and must say so.
 *
 * THE STALENESS RULE (psa-wedk): a vendor-synced "online" flag is only as good as the
 * timestamp that dates it. Every payload that carries `online` therefore carries
 * `online_reported_at` (the connect/disconnect event that set the flag) and
 * `last_webhook_at` (the last time ANY ScreenConnect event touched the asset) beside
 * it, plus fixed `state_semantics` copy; an online flag older than 24h additionally
 * gets an explicit staleness warning. Never project the bare flag into a new surface.
 *
 * DATA BOUNDARY: client-scoped. Assets resolve strictly WHERE client_id = the
 * caller's client — a hostname that exists under another client is "not found",
 * never a leak — and events join through the resolved asset only.
 *
 * DEVICE KEY (card 6abdcac2): ScreenConnect has no client-level vendor key; the
 * binding IS assets.client_id. asset_id is the primary device key and resolves only
 * within client_id. hostname is a fallback: a hostname that names more than one of
 * the client's ScreenConnect-linked devices is ambiguous and fails closed (pass
 * asset_id), never a silent pick of the most recently synced row.
 */
class ScreenConnectReadOnlyToolset
{
    private const CLIENT_TOOL_NAMES = [
        'screenconnect_get_session_state',
        'screenconnect_list_devices',
    ];

    /** Agent-facing key rule, appended to both tool descriptions (card 6abdcac2). */
    public const DEVICE_KEY_NOTE = 'client_id is required and is the only client key: devices are read only from that PSA client\'s assets, and another client\'s hostname, asset or session id is "not found", never a cross-client search. For one device, asset_id (this client\'s PSA asset ID) is the primary key; hostname is a fallback, and a hostname that matches more than one of the client\'s ScreenConnect-linked devices is an error (pass asset_id), never a guess.';

    /** An `online = true` report older than this is flagged stale rather than trusted. */
    private const STALE_ONLINE_AFTER_HOURS = 24;

    /**
     * Fixed provenance copy that rides on every state answer, so the flag can never
     * be read without its event-driven caveat (psa-wedk).
     */
    private const STATE_SEMANTICS = 'ScreenConnect online/offline state is event-driven from webhooks, not a live poll: online reflects the last Connected/Disconnected event (online_reported_at), and last_webhook_at is the last time any ScreenConnect event touched this asset. An old online report can mean a long-running session or broken webhooks — cross-check (e.g. against RMM state) before treating it as current.';

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::clientDefinitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'screenconnect_get_session_state',
                'description' => "Get ScreenConnect session state for one of a PSA client's devices, from the local webhook-fed snapshot (no live ScreenConnect call): online/offline as of the last connect/disconnect event, when that state was reported, last webhook activity, session id, and recent session events. Use this to tell an IDLE machine (agent connected, nobody on it) from a DEAD one (agent gone) before escalating an unreachable device. Pass asset_id or hostname. ".self::DEVICE_KEY_NOTE,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'asset_id' => ['type' => 'integer', 'description' => 'PSA asset ID (preferred): resolved only within client_id. Another client\'s asset is "not found".'],
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname or asset name: a FALLBACK when asset_id is not known (case-insensitive; a fully-qualified name also matches by its short host part). Matched only within client_id\'s assets; more than one ScreenConnect-linked match is an error, never a guess. With asset_id, it must name that asset.'],
                        'events_limit' => ['type' => 'integer', 'description' => 'Max recent session events to include (default 5, max 25).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'screenconnect_list_devices',
                'description' => "List a PSA client's ScreenConnect-linked devices from the local webhook-fed snapshot (no live ScreenConnect call), each pairing its online/offline flag with when that state was reported, plus fleet totals by state. Use this to see ScreenConnect coverage and which machines are reported online, offline, or unknown. ".self::DEVICE_KEY_NOTE,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Optional hostname or asset-name search term.'],
                        'status' => ['type' => 'string', 'description' => "Optional state filter: 'online', 'offline', or 'unknown' (linked but no connect/disconnect event recorded)."],
                        'limit' => ['type' => 'integer', 'description' => 'Max devices to return (default 25, max 100).'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
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
        // OFF=OFF: the master switch withdraws the capability, not just the webhook
        // intake — a switched-off integration must not keep answering from its snapshot.
        if (! ScreenConnectConfig::isAvailable()) {
            return ['error' => 'ScreenConnect is not available in this deployment — it is either switched off or has no base URL and webhook secret configured.'];
        }

        if ($clientId === null) {
            return ['error' => 'client_id is required for '.$toolName.'.'];
        }

        $client = Client::find($clientId);
        if ($client === null) {
            return ['error' => "PSA client {$clientId} was not found."];
        }

        return match ($toolName) {
            'screenconnect_get_session_state' => $this->getSessionState($input, $client),
            'screenconnect_list_devices' => $this->listDevices($input, $client),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── session state (the idle-vs-dead answer) ────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getSessionState(array $input, Client $client): array
    {
        $resolved = $this->resolveDevice($input, $client);
        if (is_array($resolved)) {
            return $resolved;
        }
        $asset = $resolved;

        if (! $this->isLinked($asset)) {
            $label = $asset->hostname ?? $asset->name;

            return ['error' => "{$label} exists in {$client->name} but has no ScreenConnect session data — the ScreenConnect agent is not installed or no webhook has matched this asset yet, so ScreenConnect cannot answer idle-vs-dead for it. Check RMM state instead."];
        }

        $eventsLimit = $this->limit($input['events_limit'] ?? null, default: 5, max: 25);

        $events = ScreenConnectEvent::where('asset_id', $asset->id)
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->limit($eventsLimit)
            ->get();

        return array_merge(
            [
                'psa_client_id' => $client->id,
                'psa_client_name' => $client->name,
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'asset_name' => $asset->name,
                'asset_type' => $asset->asset_type,
                'last_user' => $this->textSanitizer->sanitizeNullable('Asset last user', $asset->last_user, 200, ['None', '-']),
            ],
            $this->stateFields($asset),
            [
                'session_id' => $asset->screenconnect_session_id,
                'session_url' => $asset->screenconnect_session_id !== null
                    ? ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id)
                    : null,
                'client_version' => $asset->screenconnect_client_version,
                'recent_events' => $events
                    ->map(fn (ScreenConnectEvent $event): array => $this->mapEvent($event))
                    ->values()
                    ->all(),
                'events_returned' => $events->count(),
                'events_total' => ScreenConnectEvent::where('asset_id', $asset->id)->count(),
            ],
        );
    }

    // ── device list (fleet coverage view) ──────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listDevices(array $input, Client $client): array
    {
        $limit = $this->limit($input['limit'] ?? null, default: 25, max: 100);
        $queryText = trim((string) ($input['query'] ?? ''));
        $status = mb_strtolower(trim((string) ($input['status'] ?? '')));

        // Crisp local refusal over a silently-empty filter result (UX lesson from the
        // UniFi reads): an unsupported value gets the accepted shapes named.
        if (! in_array($status, ['', 'online', 'offline', 'unknown'], true)) {
            return ['error' => "Unsupported status '{$status}'. Accepted values: online, offline, unknown (linked but no connect/disconnect event recorded)."];
        }

        $linked = Asset::where('client_id', $client->id)
            ->where(function (Builder $query) {
                $query->whereNotNull('screenconnect_session_id')
                    ->orWhereNotNull('screenconnect_synced_at');
            });

        // Fleet totals come from the FULL linked set, before filters and the page
        // limit, so a truncated or filtered page can never read as the whole fleet.
        $totals = [
            'total_linked' => (clone $linked)->count(),
            'online_count' => (clone $linked)->where('screenconnect_online', true)->count(),
            'offline_count' => (clone $linked)->where('screenconnect_online', false)->count(),
            'unknown_count' => (clone $linked)->whereNull('screenconnect_online')->count(),
        ];

        if ($queryText !== '') {
            $like = '%'.mb_strtolower($queryText).'%';
            $linked->where(function (Builder $query) use ($like) {
                $query->whereRaw("LOWER(COALESCE(hostname, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$like]);
            });
        }

        if ($status === 'online') {
            $linked->where('screenconnect_online', true);
        } elseif ($status === 'offline') {
            $linked->where('screenconnect_online', false);
        } elseif ($status === 'unknown') {
            $linked->whereNull('screenconnect_online');
        }

        $devices = $linked
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Asset $asset): array => [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'asset_name' => $asset->name,
                'asset_type' => $asset->asset_type,
                'state' => $this->stateLabel($asset->screenconnect_online),
                'online' => $asset->screenconnect_online,
                // psa-wedk: the flag never travels without the timestamps that date it.
                'online_reported_at' => $asset->screenconnect_last_seen_at?->toIso8601String(),
                'last_webhook_at' => $asset->screenconnect_synced_at?->toIso8601String(),
                'session_id' => $asset->screenconnect_session_id,
                'client_version' => $asset->screenconnect_client_version,
            ])
            ->values()
            ->all();

        return array_merge(
            [
                'psa_client_id' => $client->id,
                'psa_client_name' => $client->name,
                'count' => count($devices),
            ],
            $totals,
            [
                'devices' => $devices,
                'state_semantics' => self::STATE_SEMANTICS,
            ],
        );
    }

    // ── state presentation (psa-wedk pairing lives here) ───────────────────────

    /** @return array<string, mixed> */
    private function stateFields(Asset $asset): array
    {
        $online = $asset->screenconnect_online;
        $reportedAgeMinutes = $this->ageMinutes($asset->screenconnect_last_seen_at);

        return [
            'state' => $this->stateLabel($online),
            'online' => $online,
            'online_reported_at' => $asset->screenconnect_last_seen_at?->toIso8601String(),
            'online_reported_age_minutes' => $reportedAgeMinutes,
            'last_webhook_at' => $asset->screenconnect_synced_at?->toIso8601String(),
            'last_webhook_age_minutes' => $this->ageMinutes($asset->screenconnect_synced_at),
            'state_semantics' => self::STATE_SEMANTICS,
            'staleness_warning' => $this->stalenessWarning($online, $reportedAgeMinutes),
        ];
    }

    private function stateLabel(?bool $online): string
    {
        return $online === null ? 'unknown' : ($online ? 'online' : 'offline');
    }

    /**
     * Only a trusted-looking "online" misleads: an offline or unknown state is dated
     * by its own timestamps and needs no extra alarm. This is the psa-wedk false-Online
     * bug guarded against at the presentation layer.
     */
    private function stalenessWarning(?bool $online, ?int $reportedAgeMinutes): ?string
    {
        if ($online !== true) {
            return null;
        }

        if ($reportedAgeMinutes === null) {
            return 'Reported online but with no report timestamp — treat the state as unknown, not current.';
        }

        if ($reportedAgeMinutes > self::STALE_ONLINE_AFTER_HOURS * 60) {
            $hours = intdiv($reportedAgeMinutes, 60);

            return "Reported online, but that report is ~{$hours}h old — treat it as stale, not current, until cross-checked.";
        }

        return null;
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the one device a session-state read is about (card 6abdcac2):
     *   1. asset_id: this client's PSA asset by primary key — another client's id is
     *      "not found", exactly like an unknown one;
     *   2. hostname (fallback): this client's assets only, via findAsset().
     * Both given: the hostname must name the asset_id's device, or the call is refused.
     *
     * @param  array<string, mixed>  $input
     * @return Asset|array{error: string}
     */
    private function resolveDevice(array $input, Client $client): Asset|array
    {
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $rawAssetId = $input['asset_id'] ?? null;

        if ($rawAssetId !== null && $rawAssetId !== '') {
            $assetId = $this->positiveInt($rawAssetId);
            if ($assetId === null) {
                return ['error' => 'asset_id must be a positive integer PSA asset ID.'];
            }

            $asset = Asset::whereKey($assetId)->where('client_id', $client->id)->first();
            if ($asset === null) {
                return ['error' => "Asset {$assetId} was not found in {$client->name} (it does not exist or belongs to a different client). screenconnect_list_devices shows this client's ScreenConnect-linked devices."];
            }

            if ($hostname !== '' && ! $this->namesAsset($hostname, $asset)) {
                return ['error' => "hostname '{$hostname}' is not asset {$assetId}'s hostname or name. Pass one or the other."];
            }

            return $asset;
        }

        if ($hostname === '') {
            return ['error' => 'asset_id or hostname is required'];
        }

        $asset = $this->findAsset($hostname, $client->id);
        if (is_array($asset)) {
            return $asset;
        }
        if ($asset === null) {
            return ['error' => "Device '{$hostname}' was not found in {$client->name}. screenconnect_list_devices shows this client's ScreenConnect-linked devices."];
        }

        return $asset;
    }

    /** The same name rules findAsset() applies, checked against one known asset. */
    private function namesAsset(string $hostname, Asset $asset): bool
    {
        $wanted = array_unique(array_filter([
            mb_strtolower($hostname),
            mb_strtolower(ScreenConnectAssetMatcher::firstLabel($hostname)),
        ], fn (string $v): bool => $v !== ''));

        foreach ([$asset->hostname, $asset->name] as $stored) {
            $stored = mb_strtolower(trim((string) $stored));
            if ($stored === '') {
                continue;
            }
            if (in_array($stored, $wanted, true)
                || in_array(mb_strtolower(ScreenConnectAssetMatcher::firstLabel($stored)), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Asset|array{error: string}|null */
    private function findAsset(string $hostname, int $clientId): Asset|array|null
    {
        $asset = $this->assetByName($hostname, $clientId);
        if ($asset !== null) {
            return $asset;
        }

        // A fully-qualified input gets a second chance by its host part (the asset may
        // be stored short). The reverse also happens: the asset may be stored fully
        // qualified ("test-mbp.lan", e.g. from an RMM) while the caller passes the short
        // name. That is the same first-label rule the webhook ingest uses, shared via
        // ScreenConnectAssetMatcher — exact first, then the first-label match. Both pick
        // through preferLinked(): two or more linked candidates fail closed naming them,
        // never a guess.
        $short = ScreenConnectAssetMatcher::firstLabel($hostname);
        if ($short !== '' && mb_strtolower($short) !== mb_strtolower($hostname)) {
            $asset = $this->assetByName($short, $clientId);
            if ($asset !== null) {
                return $asset;
            }
        }

        return $this->assetByFirstLabel($short, $clientId);
    }

    /**
     * Exact (rule 1) match within the client, picked by preferLinked().
     *
     * @return Asset|array{error: string, candidates: array<int, array<string, mixed>>}|null
     */
    private function assetByName(string $name, int $clientId): Asset|array|null
    {
        return $this->preferLinked(
            fn (): Builder => ScreenConnectAssetMatcher::exactQuery($clientId, $name),
            "Hostname '{$name}' matches more than one ScreenConnect-linked device for this client, so none was picked. Re-issue with asset_id.",
        );
    }

    /**
     * First-label (rule 2) match within the client: a stored hostname whose first DNS
     * label is $short, picked by the same preferLinked() rule as the exact match.
     *
     * @return Asset|array{error: string, candidates: array<int, array<string, mixed>>}|null
     */
    private function assetByFirstLabel(string $short, int $clientId): Asset|array|null
    {
        if ($short === '') {
            return null;
        }

        return $this->preferLinked(
            fn (): Builder => ScreenConnectAssetMatcher::firstLabelQuery($clientId, $short),
            "Hostname '{$short}' is the first label of more than one ScreenConnect-linked device's hostname for this client, so none was picked. Re-issue with asset_id.",
        );
    }

    /**
     * A duplicate is common (an unlinked stale row beside the linked one), so the
     * single ScreenConnect-linked candidate is preferred. Two or more LINKED candidates
     * are ambiguous: the call fails closed naming this client's candidate asset ids
     * (at most 11), never picking the most recently synced (card 6abdcac2). With no
     * linked candidate, the lowest-id match (or null) is returned.
     *
     * @param  callable(): Builder  $matches  a fresh client-scoped match query per call
     * @return Asset|array{error: string, candidates: array<int, array<string, mixed>>}|null
     */
    private function preferLinked(callable $matches, string $ambiguous): Asset|array|null
    {
        $linked = $matches()
            ->where(function (Builder $query) {
                $query->whereNotNull('screenconnect_session_id')
                    ->orWhereNotNull('screenconnect_synced_at');
            })
            ->orderBy('id')
            ->limit(11)
            ->get();

        if ($linked->count() > 1) {
            return [
                'error' => $ambiguous,
                'candidates' => $linked->map(fn (Asset $asset): array => [
                    'asset_id' => $asset->id,
                    'hostname' => $asset->hostname,
                    'asset_name' => $asset->name,
                ])->all(),
            ];
        }

        return $linked->first() ?? $matches()->orderBy('id')->first();
    }

    private function isLinked(Asset $asset): bool
    {
        return $asset->screenconnect_session_id !== null
            || $asset->screenconnect_synced_at !== null;
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function mapEvent(ScreenConnectEvent $event): array
    {
        return [
            'event_type' => $event->event_type,
            'event_time' => $event->event_time?->toIso8601String(),
            // Free text from the session (command lines, chat messages, participant
            // names) reaches an LLM — fence it as data, never pass it through raw.
            'host' => $this->textSanitizer->sanitizeNullable('ScreenConnect event host', $event->host, 200),
            'participant' => $this->textSanitizer->sanitizeNullable('ScreenConnect event participant', $event->participant, 200),
            'data' => $this->textSanitizer->sanitizeNullable('ScreenConnect event data', $event->data, 500),
            'network_address' => $event->network_address,
        ];
    }

    private function ageMinutes(?\Carbon\CarbonInterface $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return max(0, (int) $timestamp->diffInMinutes(now()));
    }

    private function limit(mixed $value, int $default, int $max): int
    {
        $limit = $this->positiveInt($value) ?? $default;

        return max(1, min($limit, $max));
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
}
