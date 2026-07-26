<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Models\TacticalScript;

/**
 * The MANDATORY platform-safety gate for Tactical check creation (psa-0pb9m
 * revise). A wrong-platform check fails on 100% of runs forever and
 * manufactures broken coverage — the exact defect this bead removes — so the
 * invariant is enforced where every check creation converges
 * (TacticalClient::createCheck, the psa-mocr choke-point rule), not in
 * selected callers. Per-surface pre-checks (StaffTacticalAdminToolExecutor,
 * TacticalMacosCheckProvisioner) remain as defence in depth and produce the
 * friendlier audited refusals; THIS gate is what makes bypass impossible for
 * any future caller.
 *
 * EVIDENCE, NEVER ASSERTION (psa-0pb9m R3): every input to the safety
 * decision is resolved INSIDE this boundary from server-derived state — the
 * synced local snapshot/catalog or a live read over the same client. There is
 * deliberately NO parameter through which a caller can supply script metadata,
 * platform claims, or membership facts: R2/R3 proved that any caller-assertable
 * claim (acknowledge_platform_risk, a scriptMeta array) is retried or
 * fabricated by an AI caller and reopens the original defect.
 *
 * Fail-closed rules (each refusal names its remedy):
 *  - Payload targeting BOTH an agent and a policy → refused. The upstream
 *    Check model holds both nullable FKs with no XOR validation (checks/
 *    models.py @ 632a37a4), so such a row lands in BOTH the agent's and the
 *    policy's check lists while safety was proven for only one target.
 *  - AGENT target with an UNKNOWN platform → refused. An agent's platform is
 *    knowable — sync it (tactical:sync-devices). No override: guessing here
 *    recreates the original bug.
 *  - AGENT target, non-script check on a darwin/linux agent → refused.
 *    Tactical macOS/Linux agents run SCRIPT checks only (vendor constraint,
 *    also documented on the provisioner) — a non-script check there never
 *    reports and reads as broken coverage.
 *  - Script whose metadata cannot be resolved from a server-derived source,
 *    OR carries no usable platform signal (no shell AND no
 *    supported_platforms) → refused. Resolution order: the local synced
 *    catalog (tactical:sync-scripts / the provisioner's post-create upsert),
 *    then a live getScripts read over this client for a not-yet-synced
 *    script. Caller claims are not a source. Absence of constraints is NOT
 *    compatibility: treating an empty claim as "runs anywhere" is exactly how
 *    a wrong-platform always-failing check ships (psa-0pb9m R2).
 *  - AGENT target with a provably incompatible script → refused, no override.
 *  - POLICY target whose check could not run on some platform (a
 *    platform-bound script, or ANY non-script check — those are Windows-only
 *    per the vendor constraint above) → allowed ONLY on SERVER-DERIVED
 *    MEMBERSHIP PROOF: the policy's current membership is resolved live from
 *    Tactical (GET automation/policies/{pk}/related/ + the fleet agents
 *    list) and every member agent must be on a provably compatible platform.
 *    Any member on a blocked platform, any member whose platform cannot be
 *    resolved, any failure to enumerate membership, or any STRUCTURALLY
 *    INCOMPLETE membership payload → refused. A 200 response missing the
 *    serializer's fields is drift/degradation, and absence of proof is never
 *    zero members (psa-0pb9m R3: related={} previously proved true with
 *    members_checked=0).
 *
 * Membership resolution (producers read at amidaware/tacticalrmm 632a37a4,
 * 2026-07-24 — cite, don't guess; captured in
 * tests/Fixtures/tactical/upstream_producers.json):
 *  - GET automation/policies/{pk}/related/ → PolicyRelatedSerializer
 *    (automation/serializers.py:41-89): direct `agents`
 *    ({id, hostname, agent_id, client, site} per AgentHostnameSerializer,
 *    agents/serializers.py:190-203), `workstation_clients`/`server_clients`
 *    (ClientMinimumSerializer — all Client fields incl. `name`),
 *    `workstation_sites`/`server_sites` (SiteMinimumSerializer — all Site
 *    fields incl. `name` + `client_name`), plus `is_default_server_policy` /
 *    `is_default_workstation_policy` (SerializerMethodFields returning real
 *    booleans). All seven fields are emitted on EVERY healthy response — the
 *    five collections as JSON lists, the two flags as JSON booleans — so this
 *    guard REQUIRES that exact runtime shape and refuses anything less
 *    (see REQUIRED_RELATED_LIST_FIELDS / REQUIRED_RELATED_FLAG_FIELDS, which
 *    TacticalSchemaDriftTest proves against the captured producer).
 *  - GET agents/ → AgentTableSerializer rows carrying `agent_id`, `plat`,
 *    `operating_system`, `monitoring_type`, `client_name`, `site_name`.
 *    When the related payload contains a client/site/default assignment, the
 *    fleet rows are the ONLY join evidence, so every row must carry the join
 *    keys that assignment needs (FLEET_JOIN_FIELDS subset) — a row missing
 *    them could belong to the policy invisibly, which means membership cannot
 *    be completely enumerated → refused.
 *  - Composition OVER-approximates Policy.related_agents()
 *    (automation/models.py:91+): upstream subtracts excluded agents/sites/
 *    clients and block_policy_inheritance; we do not, so our member set is a
 *    superset — strictly more refusals, never fewer (fail-closed).
 *  - The proof covers membership AS OF the write. Agents added to the policy
 *    later are not covered — the allow-note says so.
 *
 * Throws TacticalClientException so existing caller error paths surface the
 * refusal; nothing is sent upstream on refusal.
 */
class TacticalCheckPlatformGuard
{
    /** Refusals name at most this many offending member hostnames. */
    private const MAX_NAMED_MEMBERS = 5;

    /**
     * PolicyRelatedSerializer fields the membership proof REQUIRES at runtime
     * as JSON lists. Proven against the captured vendor producer by
     * TacticalSchemaDriftTest — one list, enforced here, pinned there.
     *
     * @var string[]
     */
    public const REQUIRED_RELATED_LIST_FIELDS = [
        'agents',
        'workstation_clients',
        'server_clients',
        'workstation_sites',
        'server_sites',
    ];

    /**
     * PolicyRelatedSerializer fields the membership proof REQUIRES at runtime
     * as JSON booleans. Same drift-test binding as the list fields.
     *
     * @var string[]
     */
    public const REQUIRED_RELATED_FLAG_FIELDS = [
        'is_default_server_policy',
        'is_default_workstation_policy',
    ];

    /**
     * Fleet (agents-list) keys the client/site/default membership joins read.
     * When such an assignment exists, every fleet row must carry the keys that
     * join needs, or membership cannot be completely enumerated.
     *
     * @var string[]
     */
    public const FLEET_JOIN_FIELDS = [
        'monitoring_type',
        'client_name',
        'site_name',
    ];

    /**
     * @param  array<string, mixed>  $payload  The POST checks/ body about to be sent.
     * @param  TacticalClient  $client  Used ONLY for read calls (script
     *                                  metadata for a not-yet-synced script;
     *                                  policy membership proof); never to
     *                                  write.
     *
     * @throws TacticalClientException on refusal — nothing was sent upstream.
     */
    public static function assertSafe(array $payload, TacticalClient $client): void
    {
        $agentId = isset($payload['agent']) && is_scalar($payload['agent']) ? trim((string) $payload['agent']) : '';
        $policyId = isset($payload['policy']) && is_numeric($payload['policy']) ? (int) $payload['policy'] : null;

        if ($agentId !== '' && $policyId !== null) {
            throw new TacticalClientException(
                'Refusing to create this check: the payload targets BOTH an agent and a policy. The upstream Check model '
                .'accepts either foreign key with no exactly-one validation, so such a row would appear in both the '
                ."agent's and the policy's check lists while platform safety was proven for only one of them (psa-0pb9m). "
                .'Create two separate checks, one per target.'
            );
        }

        if ($agentId === '' && $policyId === null) {
            throw new TacticalClientException(
                'Refusing to create this check: the payload targets neither an agent nor a policy, so platform safety cannot be assessed.'
            );
        }

        $checkType = isset($payload['check_type']) && is_scalar($payload['check_type'])
            ? mb_strtolower(trim((string) $payload['check_type']))
            : '';
        $isScriptCheck = $checkType === 'script' || isset($payload['script']);

        if ($agentId !== '') {
            self::assertAgentTargetSafe($agentId, $isScriptCheck, $payload, $client);

            return;
        }

        self::assertPolicyTargetSafe((int) $policyId, $isScriptCheck, $payload, $client);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function assertAgentTargetSafe(string $agentId, bool $isScriptCheck, array $payload, TacticalClient $client): void
    {
        $platform = TacticalAsset::where('agent_id', $agentId)->first()?->platform();

        if ($platform === null) {
            // Fail CLOSED on the unknown: an unknown platform is precisely the
            // state in which the original always-failing check was attached.
            throw new TacticalClientException(
                "Refusing to create this check: the platform of agent '{$agentId}' is unknown to the PSA "
                .'(no synced platform on the local snapshot). Run tactical:sync-devices to resolve it, then retry — '
                .'creating a check against an unknown platform is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        if (! $isScriptCheck) {
            if ($platform !== TacticalPlatform::WINDOWS) {
                throw new TacticalClientException(
                    "Refusing to create a '{$payload['check_type']}' check on agent '{$agentId}': Tactical {$platform} agents "
                    .'run SCRIPT checks only (vendor constraint) — a non-script check there never reports and reads as broken coverage (psa-0pb9m).'
                );
            }

            return;
        }

        $meta = self::resolveScriptMeta($payload, $client);

        $incompatibility = TacticalPlatform::scriptIncompatibility(
            $platform,
            $meta['shell'],
            $meta['supported_platforms'],
        );

        if ($incompatibility !== null) {
            throw new TacticalClientException(
                "Refusing to create this check: {$incompatibility}. It would fail on every run on agent '{$agentId}' "
                .'and register as broken coverage (psa-0pb9m). Use a script compatible with the agent platform instead.'
            );
        }
    }

    /**
     * A policy-target check is allowed only when its platform demands are
     * proven against the policy's CURRENT membership (server-derived, never
     * caller-asserted).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function assertPolicyTargetSafe(int $policyId, bool $isScriptCheck, array $payload, TacticalClient $client): void
    {
        if ($isScriptCheck) {
            $meta = self::resolveScriptMeta($payload, $client);
            $blocked = self::incompatiblePlatforms($meta['shell'], $meta['supported_platforms']);
        } else {
            // Non-script checks exist on WINDOWS agents only (vendor
            // constraint) — on a policy they demand an all-Windows membership
            // exactly like a Windows-bound script (psa-0pb9m R2: this path
            // previously bypassed the guard entirely).
            $blocked = [TacticalPlatform::DARWIN, TacticalPlatform::LINUX];
        }

        if ($blocked === []) {
            return;
        }

        $proof = self::provePolicyMembership($client, $policyId, $blocked);
        if (! $proof['proven']) {
            throw new TacticalClientException(
                'Refusing to create this policy check before any write: '.$proof['reason']
                .' A check that cannot run on a member platform fails on every run there and manufactures broken coverage '
                .'(the original psa-0pb9m defect arrived through exactly this route). There is no override: either make the '
                .'policy cover only compatible platforms, or target the compatible agents directly (one agent-target check each).'
            );
        }
    }

    /**
     * SERVER-DERIVED membership proof: every agent the policy currently
     * reaches must be on a platform outside $blockedPlatforms. Public so the
     * MCP executor can pre-check with audited, surface-friendly copy; the
     * client-boundary guard re-asserts the same proof (defence in depth).
     *
     * Never trusts caller claims, and never treats ABSENT response data as
     * evidence (psa-0pb9m R3): the related payload must carry the vendor
     * serializer's full runtime shape (REQUIRED_RELATED_LIST_FIELDS as lists,
     * REQUIRED_RELATED_FLAG_FIELDS as booleans), assignment rows must carry
     * the keys their join needs, and — when a client/site/default assignment
     * exists — every fleet row must carry that join's keys. Anything less is
     * a drifted or degraded response, and proving "zero members" from it
     * would authorize the exact wrong-platform write this guard exists to
     * stop. Zero members is accepted ONLY from a structurally complete
     * response whose collections are genuinely empty.
     *
     * A member whose platform cannot be resolved — absent from the fleet
     * list, or a row without a usable `plat`/`operating_system` — fails the
     * proof (unknown is never compatible).
     *
     * @param  array<int, string>  $blockedPlatforms
     * @return array{proven: bool, reason: ?string, members_checked: int}
     */
    public static function provePolicyMembership(TacticalClient $client, int $policyId, array $blockedPlatforms): array
    {
        try {
            $related = $client->getAutomationPolicyRelated($policyId);
            $fleet = $client->getAgents();
        } catch (\Throwable $e) {
            return [
                'proven' => false,
                'reason' => "the membership of policy {$policyId} could not be read from Tactical (".$e::class.'), so platform compatibility cannot be verified.',
                'members_checked' => 0,
            ];
        }

        $shapeError = self::relatedShapeError($related, $policyId) ?? self::fleetShapeError($related, $fleet, $policyId);
        if ($shapeError !== null) {
            return ['proven' => false, 'reason' => $shapeError, 'members_checked' => 0];
        }

        $members = self::resolveMembers($related, $fleet);
        if (isset($members['error'])) {
            return ['proven' => false, 'reason' => $members['error'], 'members_checked' => 0];
        }

        $offending = [];
        $unresolved = [];
        foreach ($members['agents'] as $member) {
            $platform = TacticalPlatform::fromAgentPayload(
                is_scalar($member['plat'] ?? null) ? (string) $member['plat'] : null,
                is_scalar($member['operating_system'] ?? null) ? (string) $member['operating_system'] : null,
            );

            if ($platform === null) {
                $unresolved[] = (string) ($member['hostname'] ?? $member['agent_id'] ?? 'unknown-agent');
            } elseif (in_array($platform, $blockedPlatforms, true)) {
                $offending[] = (string) ($member['hostname'] ?? $member['agent_id'] ?? 'unknown-agent')." ({$platform})";
            }
        }

        if ($offending !== []) {
            return [
                'proven' => false,
                'reason' => 'this check cannot run on '.implode('/', $blockedPlatforms)." agents, and policy {$policyId}'s current membership includes "
                    .count($offending).' such agent(s): '.self::nameSome($offending).'.',
                'members_checked' => count($members['agents']),
            ];
        }

        if ($unresolved !== []) {
            return [
                'proven' => false,
                'reason' => 'the platform of '.count($unresolved)." member agent(s) of policy {$policyId} could not be resolved from the Tactical fleet list ("
                    .self::nameSome($unresolved).') — unknown is never compatible.',
                'members_checked' => count($members['agents']),
            ];
        }

        return ['proven' => true, 'reason' => null, 'members_checked' => count($members['agents'])];
    }

    /**
     * Structural validation of the policies/{pk}/related/ payload against the
     * vendor serializer's runtime shape. A healthy PolicyRelatedSerializer
     * response ALWAYS carries the five collections as lists (of objects) and
     * the two default-policy flags as booleans; a 200 missing any of them is
     * drift or degradation, and absence of proof is never zero members
     * (psa-0pb9m R3: related={} previously proved true, members_checked=0).
     *
     * @param  array<string, mixed>  $related
     */
    private static function relatedShapeError(array $related, int $policyId): ?string
    {
        foreach (self::REQUIRED_RELATED_LIST_FIELDS as $field) {
            if (! array_key_exists($field, $related)) {
                return "the membership payload of policy {$policyId} (GET automation/policies/{$policyId}/related/) is missing `{$field}`, "
                    .'which the vendor serializer always emits — a drifted or degraded response proves nothing about membership, and absence of proof is never zero members.';
            }
            if (! is_array($related[$field])) {
                return "the membership payload of policy {$policyId} carries `{$field}` as ".get_debug_type($related[$field])
                    .' where the vendor serializer emits a list — a drifted or degraded response; membership cannot be proven.';
            }
            foreach ($related[$field] as $row) {
                if (! is_array($row)) {
                    return "a `{$field}` assignment row of policy {$policyId} is not an object (".get_debug_type($row)
                        .') — a drifted or degraded response; membership cannot be completely enumerated.';
                }
            }
        }

        foreach (self::REQUIRED_RELATED_FLAG_FIELDS as $field) {
            if (! array_key_exists($field, $related)) {
                return "the membership payload of policy {$policyId} is missing `{$field}`, which the vendor serializer always emits "
                    .'as a boolean — a drifted or degraded response; whether this is a fleet-default policy cannot be determined.';
            }
            if (! is_bool($related[$field])) {
                return "the membership payload of policy {$policyId} carries `{$field}` as ".get_debug_type($related[$field])
                    .' where the vendor serializer emits a boolean — a drifted or degraded response; whether this is a fleet-default policy cannot be determined.';
            }
        }

        return null;
    }

    /**
     * Structural validation of the fleet list AS JOIN EVIDENCE. Runs after
     * relatedShapeError, so $related is known well-formed. Every fleet row
     * must be an object; and when the policy has client/site/default
     * assignments, every fleet row must carry the join keys those assignments
     * enumerate through — a row missing them could belong to the policy
     * invisibly. An EMPTY fleet is accepted as evidence only when the local
     * synced snapshot agrees the fleet is empty; zero rows while the snapshot
     * knows agents is a degraded read wearing a 200.
     *
     * @param  array<string, mixed>  $related
     * @param  array<int, mixed>  $fleet
     */
    private static function fleetShapeError(array $related, array $fleet, int $policyId): ?string
    {
        foreach ($fleet as $row) {
            if (! is_array($row)) {
                return 'a row of the Tactical fleet list (GET agents/) is not an object ('.get_debug_type($row)
                    .") — a drifted or degraded response; policy {$policyId}'s membership cannot be completely enumerated.";
            }
        }

        $requiredKeys = [];
        if ($related['is_default_server_policy'] === true || $related['is_default_workstation_policy'] === true) {
            $requiredKeys['monitoring_type'] = 'default-policy';
        }
        if ($related['workstation_clients'] !== [] || $related['server_clients'] !== []) {
            $requiredKeys['monitoring_type'] = $requiredKeys['monitoring_type'] ?? 'client';
            $requiredKeys['client_name'] = 'client';
        }
        if ($related['workstation_sites'] !== [] || $related['server_sites'] !== []) {
            $requiredKeys['monitoring_type'] = $requiredKeys['monitoring_type'] ?? 'site';
            $requiredKeys['client_name'] = $requiredKeys['client_name'] ?? 'site';
            $requiredKeys['site_name'] = 'site';
        }

        if ($requiredKeys === []) {
            return null; // only direct-agent assignments (or none) — no fleet join needed
        }

        if ($fleet === []) {
            $known = TacticalAsset::query()->count();
            if ($known > 0) {
                return "the Tactical fleet list (GET agents/) returned zero agents while the local synced snapshot knows {$known} — "
                    ."a degraded or drifted read, not an empty fleet, so policy {$policyId}'s "
                    .implode('/', array_unique(array_values($requiredKeys))).' assignment(s) cannot be enumerated. '
                    .'If agents were genuinely removed, run tactical:sync-devices and retry.';
            }

            return null; // fleet genuinely empty on both live and synced evidence — the assignments reach nobody
        }

        foreach ($fleet as $row) {
            foreach ($requiredKeys as $key => $assignmentKind) {
                $value = $row[$key] ?? null;
                if (! is_scalar($value) || trim((string) $value) === '') {
                    $who = is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : 'unknown hostname';

                    return "a Tactical fleet row ({$who}) is missing `{$key}`, so policy {$policyId}'s {$assignmentKind} assignment(s) "
                        .'cannot be completely enumerated — an agent could belong to this policy invisibly, and absent keys are never evidence.';
                }
                // EXACT vendor vocabulary, because the membership joins compare
                // raw (===): a 'Server'/' server' row would pass a folded
                // validation yet silently escape every join — the precise
                // invisible-member hole this validation exists to close.
                if ($key === 'monitoring_type' && ! in_array($value, ['server', 'workstation'], true)) {
                    $who = is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : 'unknown hostname';

                    return "a Tactical fleet row ({$who}) carries monitoring_type '".trim((string) $value)
                        ."' where the vendor emits exactly server|workstation — such a row would silently escape policy {$policyId}'s "
                        .'membership joins, so membership cannot be completely enumerated.';
                }
            }
        }

        return null;
    }

    /**
     * Compose the policy's current member agents from the (shape-validated)
     * related payload + fleet list. Over-approximates upstream
     * related_agents() (exclusions and block_policy_inheritance are ignored —
     * a superset is fail-closed).
     *
     * @param  array<string, mixed>  $related
     * @param  array<int, mixed>  $fleet
     * @return array{agents: array<int, array<string, mixed>>}|array{error: string}
     */
    private static function resolveMembers(array $related, array $fleet): array
    {
        /** @var array<int, array<string, mixed>> $fleetRows (shape-validated: every row is an array) */
        $fleetRows = array_values($fleet);
        $byAgentId = [];
        foreach ($fleetRows as $row) {
            if (is_scalar($row['agent_id'] ?? null)) {
                $byAgentId[(string) $row['agent_id']] = $row;
            }
        }

        $members = [];
        $fallbackKey = 0;

        // Default policies reach the whole fleet of that monitoring type.
        if ($related['is_default_server_policy'] === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'server') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }
        if ($related['is_default_workstation_policy'] === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'workstation') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }

        // Directly-assigned agents ({agent_id, hostname, …}).
        foreach ($related['agents'] as $direct) {
            $directId = is_scalar($direct['agent_id'] ?? null) && trim((string) $direct['agent_id']) !== ''
                ? (string) $direct['agent_id']
                : null;
            $directName = is_scalar($direct['hostname'] ?? null) ? (string) $direct['hostname'] : 'unknown hostname';
            if ($directId === null) {
                return ['error' => "a directly-assigned member agent of this policy ({$directName}) carries no agent_id — "
                    .'a drifted or degraded row; it cannot be resolved against the fleet list, and absent keys are never evidence.'];
            }
            if (! isset($byAgentId[$directId])) {
                return ['error' => "a directly-assigned member agent of this policy ({$directName}) is missing from the "
                    .'Tactical fleet list, so its platform cannot be resolved — unknown is never compatible.'];
            }
            $members[$directId] = $byAgentId[$directId];
        }

        // Client- and site-scoped assignment, per monitoring type. Names are
        // the join key the vendor exposes on both sides (client_name/site_name
        // on AgentTableSerializer; name/client_name on the minimum
        // serializers).
        foreach (['workstation_clients' => 'workstation', 'server_clients' => 'server'] as $key => $monType) {
            foreach ($related[$key] as $clientRow) {
                $clientName = is_scalar($clientRow['name'] ?? null) ? (string) $clientRow['name'] : null;
                if ($clientName === null) {
                    return ['error' => "a {$monType}-client assignment of this policy carries no name, so its member agents cannot be resolved."];
                }
                foreach ($fleetRows as $row) {
                    if (($row['client_name'] ?? null) === $clientName && ($row['monitoring_type'] ?? null) === $monType) {
                        $members[self::memberKey($row, $fallbackKey)] = $row;
                    }
                }
            }
        }
        foreach (['workstation_sites' => 'workstation', 'server_sites' => 'server'] as $key => $monType) {
            foreach ($related[$key] as $siteRow) {
                $siteName = is_scalar($siteRow['name'] ?? null) ? (string) $siteRow['name'] : null;
                $siteClient = is_scalar($siteRow['client_name'] ?? null) ? (string) $siteRow['client_name'] : null;
                if ($siteName === null || $siteClient === null) {
                    return ['error' => "a {$monType}-site assignment of this policy carries no name/client_name, so its member agents cannot be resolved."];
                }
                foreach ($fleetRows as $row) {
                    if (($row['site_name'] ?? null) === $siteName
                        && ($row['client_name'] ?? null) === $siteClient
                        && ($row['monitoring_type'] ?? null) === $monType) {
                        $members[self::memberKey($row, $fallbackKey)] = $row;
                    }
                }
            }
        }

        return ['agents' => array_values($members)];
    }

    /**
     * Dedup key for one fleet row. agent_id when present; otherwise a
     * guaranteed-unique fallback so a keyless row still COUNTS as a member
     * (dropping or colliding it would shrink the proven set — fail-open).
     *
     * @param  array<string, mixed>  $row
     */
    private static function memberKey(array $row, int &$fallbackKey): string
    {
        if (is_scalar($row['agent_id'] ?? null) && trim((string) $row['agent_id']) !== '') {
            return (string) $row['agent_id'];
        }

        return 'keyless-'.(++$fallbackKey);
    }

    /** @param array<int, string> $names */
    private static function nameSome(array $names): string
    {
        $shown = array_slice($names, 0, self::MAX_NAMED_MEMBERS);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? " (+{$more} more)" : '');
    }

    /**
     * The platforms this script provably cannot (or very likely cannot) run
     * on, per the shared TacticalPlatform rules (vendor supported_platforms
     * metadata first, definitive shell heuristics second).
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     * @return array<int, string>
     */
    public static function incompatiblePlatforms(?string $shell, ?array $supportedPlatforms): array
    {
        return array_values(array_filter(
            [TacticalPlatform::WINDOWS, TacticalPlatform::DARWIN, TacticalPlatform::LINUX],
            fn (string $platform): bool => TacticalPlatform::scriptIncompatibility($platform, $shell, $supportedPlatforms) !== null,
        ));
    }

    /**
     * Script metadata for the payload's script id, resolved INSIDE the
     * boundary from server-derived sources only — the local synced catalog
     * first (tactical:sync-scripts, or the provisioner's post-create upsert),
     * then a live getScripts read over this client for a script the catalog
     * has not synced yet. There is deliberately no way for a caller to supply
     * this: R3 proved a caller-supplied metadata array is an assertion, not
     * evidence — a fabricated cross-platform claim for a Windows-only script
     * sailed straight to POST (psa-0pb9m R3 A3/S3).
     *
     * In EVERY case the resolved metadata must carry a usable platform
     * signal — a non-empty shell or a non-empty supported_platforms list.
     * Metadata that says nothing is not "no constraints": treating absence as
     * compatibility is how a wrong-platform always-failing check ships.
     *
     * @param  array<string, mixed>  $payload
     * @return array{shell: ?string, supported_platforms: ?array<int, mixed>}
     */
    private static function resolveScriptMeta(array $payload, TacticalClient $client): array
    {
        $scriptId = isset($payload['script']) && is_numeric($payload['script']) ? (int) $payload['script'] : null;
        if ($scriptId === null || $scriptId <= 0) {
            throw new TacticalClientException(
                'Refusing to create this script check: the payload carries no numeric script id, so the script\'s platform '
                .'constraints cannot be verified (psa-0pb9m).'
            );
        }

        $local = TacticalScript::where('tactical_script_id', $scriptId)->first();
        if ($local !== null) {
            $resolved = [
                'shell' => is_string($local->shell) && trim($local->shell) !== '' ? $local->shell : null,
                'supported_platforms' => is_array($local->supported_platforms) ? $local->supported_platforms : null,
            ];

            if (! self::hasUsablePlatformSignal($resolved['shell'], $resolved['supported_platforms'])) {
                throw new TacticalClientException(
                    "Refusing to create this script check: the synced catalog row for script {$scriptId} carries neither "
                    .'a shell nor any supported_platforms, so its platform constraints cannot be verified — absence of metadata is '
                    .'not compatibility (psa-0pb9m). Re-run tactical:sync-scripts, or verify the script in Tactical.'
                );
            }

            return $resolved;
        }

        // Not in the catalog yet (e.g. created upstream since the last sync):
        // read the vendor's own getScripts row live over the same client. A
        // failed or empty read REFUSES — never degrades to a caller claim.
        try {
            $upstream = $client->getScripts(true, true);
        } catch (\Throwable $e) {
            throw new TacticalClientException(
                "Refusing to create this script check: script {$scriptId} is not in the local synced script catalog, and its "
                .'metadata could not be read live from Tactical ('.$e::class.'), so its platform constraints cannot be verified. '
                .'Run tactical:sync-scripts, then retry — attaching a script blind is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        $row = null;
        foreach ($upstream as $candidate) {
            if (is_array($candidate) && is_numeric($candidate['id'] ?? null) && (int) $candidate['id'] === $scriptId) {
                $row = $candidate;
                break;
            }
        }

        if ($row === null) {
            throw new TacticalClientException(
                "Refusing to create this script check: script {$scriptId} is not in the local synced script catalog and is not "
                .'visible in Tactical getScripts, so its platform constraints cannot be verified. '
                .'Run tactical:sync-scripts first — attaching a script blind is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        $resolved = [
            'shell' => isset($row['shell']) && is_scalar($row['shell']) && trim((string) $row['shell']) !== ''
                ? (string) $row['shell']
                : null,
            'supported_platforms' => is_array($row['supported_platforms'] ?? null) ? $row['supported_platforms'] : null,
        ];

        if (! self::hasUsablePlatformSignal($resolved['shell'], $resolved['supported_platforms'])) {
            throw new TacticalClientException(
                "Refusing to create this script check: the Tactical getScripts row for script {$scriptId} carries neither a "
                .'shell nor any supported_platforms, so its platform constraints cannot be verified — absence of metadata is '
                .'not compatibility (psa-0pb9m). Verify the script in Tactical.'
            );
        }

        return $resolved;
    }

    /** @param array<int, mixed>|null $supportedPlatforms */
    private static function hasUsablePlatformSignal(?string $shell, ?array $supportedPlatforms): bool
    {
        if (is_string($shell) && trim($shell) !== '') {
            return true;
        }

        foreach ($supportedPlatforms ?? [] as $platform) {
            if (is_scalar($platform) && trim((string) $platform) !== '') {
                return true;
            }
        }

        return false;
    }
}
