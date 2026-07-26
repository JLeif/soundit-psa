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
 * Fail-closed rules (each refusal names its remedy):
 *  - AGENT target with an UNKNOWN platform → refused. An agent's platform is
 *    knowable — sync it (tactical:sync-devices). No override: guessing here
 *    recreates the original bug.
 *  - AGENT target, non-script check on a darwin/linux agent → refused.
 *    Tactical macOS/Linux agents run SCRIPT checks only (vendor constraint,
 *    also documented on the provisioner) — a non-script check there never
 *    reports and reads as broken coverage.
 *  - Script whose metadata cannot be resolved OR carries no usable platform
 *    signal (no shell AND no supported_platforms) → refused. Metadata comes
 *    from the caller's vendor-sourced claim (an upstream getScripts row, the
 *    provisioner's shipped definition) or the local synced catalog
 *    (tactical:sync-scripts). Absence of constraints is NOT compatibility:
 *    treating an empty claim as "runs anywhere" is exactly how a
 *    wrong-platform always-failing check ships (psa-0pb9m R2).
 *  - AGENT target with a provably incompatible script → refused, no override.
 *  - POLICY target whose check could not run on some platform (a
 *    platform-bound script, or ANY non-script check — those are Windows-only
 *    per the vendor constraint above) → allowed ONLY on SERVER-DERIVED
 *    MEMBERSHIP PROOF: the policy's current membership is resolved live from
 *    Tactical (GET automation/policies/{pk}/related/ + the fleet agents
 *    list) and every member agent must be on a provably compatible platform.
 *    Any member on a blocked platform, any member whose platform cannot be
 *    resolved, or any failure to enumerate membership → refused. There is NO
 *    caller-assertable override (psa-0pb9m R2: the old
 *    acknowledge_platform_risk boolean was an AI-settable claim, not
 *    evidence, and reopened the original defect).
 *
 * Membership resolution (producers read at amidaware/tacticalrmm 632a37a4,
 * 2026-07-24 — cite, don't guess):
 *  - GET automation/policies/{pk}/related/ → PolicyRelatedSerializer
 *    (automation/serializers.py:41-89): direct `agents`
 *    ({id, hostname, agent_id, client, site} per AgentHostnameSerializer,
 *    agents/serializers.py:190-203), `workstation_clients`/`server_clients`
 *    (ClientMinimumSerializer — all Client fields incl. `name`),
 *    `workstation_sites`/`server_sites` (SiteMinimumSerializer — all Site
 *    fields incl. `name` + `client_name`), plus `is_default_server_policy` /
 *    `is_default_workstation_policy`.
 *  - GET agents/ → AgentTableSerializer rows carrying `agent_id`, `plat`,
 *    `operating_system`, `monitoring_type`, `client_name`, `site_name`.
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
     * @param  array<string, mixed>  $payload  The POST checks/ body about to be sent.
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     *                                                                                             Optional vendor-sourced script metadata claim from the caller
     *                                                                                             (e.g. the upstream getScripts row the MCP executor already
     *                                                                                             resolved, or the provisioner's shipped definition). When null,
     *                                                                                             metadata is resolved from the local synced script catalog.
     * @param  TacticalClient  $client  Used ONLY for read calls (policy
     *                                  membership proof); never to write.
     *
     * @throws TacticalClientException on refusal — nothing was sent upstream.
     */
    public static function assertSafe(array $payload, ?array $scriptMeta, TacticalClient $client): void
    {
        $agentId = isset($payload['agent']) && is_scalar($payload['agent']) ? trim((string) $payload['agent']) : '';
        $policyId = isset($payload['policy']) && is_numeric($payload['policy']) ? (int) $payload['policy'] : null;

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
            self::assertAgentTargetSafe($agentId, $isScriptCheck, $payload, $scriptMeta);

            return;
        }

        self::assertPolicyTargetSafe((int) $policyId, $isScriptCheck, $payload, $scriptMeta, $client);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     */
    private static function assertAgentTargetSafe(string $agentId, bool $isScriptCheck, array $payload, ?array $scriptMeta): void
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

        $meta = self::resolveScriptMeta($payload, $scriptMeta);

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
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     */
    private static function assertPolicyTargetSafe(int $policyId, bool $isScriptCheck, array $payload, ?array $scriptMeta, TacticalClient $client): void
    {
        if ($isScriptCheck) {
            $meta = self::resolveScriptMeta($payload, $scriptMeta);
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
     * Never trusts caller claims: membership comes from
     * GET automation/policies/{pk}/related/ and platforms from the fleet
     * agents list (producers cited in the class docblock). A member whose
     * platform cannot be resolved — absent from the fleet list, or a row
     * without a usable `plat` — fails the proof (runtime absent-key refusal;
     * unknown is never compatible).
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
     * Compose the policy's current member agents from the related payload +
     * the fleet list. Over-approximates upstream related_agents() (exclusions
     * and block_policy_inheritance are ignored — a superset is fail-closed).
     *
     * @param  array<string, mixed>  $related
     * @param  array<int, mixed>  $fleet
     * @return array{agents: array<int, array<string, mixed>>}|array{error: string}
     */
    private static function resolveMembers(array $related, array $fleet): array
    {
        $fleetRows = array_values(array_filter($fleet, 'is_array'));
        $byAgentId = [];
        foreach ($fleetRows as $row) {
            if (is_scalar($row['agent_id'] ?? null)) {
                $byAgentId[(string) $row['agent_id']] = $row;
            }
        }

        $members = [];
        $fallbackKey = 0;

        // Default policies reach the whole fleet of that monitoring type.
        if (($related['is_default_server_policy'] ?? false) === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'server') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }
        if (($related['is_default_workstation_policy'] ?? false) === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'workstation') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }

        // Directly-assigned agents ({agent_id, hostname, …}).
        foreach (self::rows($related['agents'] ?? null) as $direct) {
            $directId = is_scalar($direct['agent_id'] ?? null) ? (string) $direct['agent_id'] : null;
            if ($directId === null || ! isset($byAgentId[$directId])) {
                return ['error' => 'a directly-assigned member agent of this policy ('
                    .(is_scalar($direct['hostname'] ?? null) ? (string) $direct['hostname'] : 'unknown hostname')
                    .') is missing from the Tactical fleet list, so its platform cannot be resolved — unknown is never compatible.'];
            }
            $members[$directId] = $byAgentId[$directId];
        }

        // Client- and site-scoped assignment, per monitoring type. Names are
        // the join key the vendor exposes on both sides (client_name/site_name
        // on AgentTableSerializer; name/client_name on the minimum
        // serializers).
        foreach (['workstation_clients' => 'workstation', 'server_clients' => 'server'] as $key => $monType) {
            foreach (self::rows($related[$key] ?? null) as $clientRow) {
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
            foreach (self::rows($related[$key] ?? null) as $siteRow) {
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

    /** @return array<int, array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
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
     * Script metadata for the payload's script id: the caller's vendor-sourced
     * claim wins (it is fresher than the daily catalog sync); otherwise the
     * local synced catalog; otherwise REFUSE. In EVERY case the resolved
     * metadata must carry a usable platform signal — a non-empty shell or a
     * non-empty supported_platforms list. Metadata that says nothing is not
     * "no constraints": treating absence as compatibility is how a
     * wrong-platform always-failing check ships (psa-0pb9m R2 — a caller
     * passing scriptMeta=[] previously sailed straight to HTTP).
     *
     * @param  array<string, mixed>  $payload
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     * @return array{shell: ?string, supported_platforms: ?array<int, mixed>}
     */
    private static function resolveScriptMeta(array $payload, ?array $scriptMeta): array
    {
        if ($scriptMeta !== null) {
            $claim = [
                'shell' => isset($scriptMeta['shell']) && is_scalar($scriptMeta['shell']) ? (string) $scriptMeta['shell'] : null,
                'supported_platforms' => is_array($scriptMeta['supported_platforms'] ?? null) ? $scriptMeta['supported_platforms'] : null,
            ];

            if (! self::hasUsablePlatformSignal($claim['shell'], $claim['supported_platforms'])) {
                throw new TacticalClientException(
                    'Refusing to create this script check: the supplied script metadata carries neither a shell nor any '
                    .'supported_platforms, so its platform constraints cannot be verified — absence of metadata is not '
                    .'compatibility (psa-0pb9m). Pass the script\'s real vendor metadata (its getScripts row), or omit the '
                    .'claim so the local synced catalog is consulted.'
                );
            }

            return $claim;
        }

        $scriptId = isset($payload['script']) && is_numeric($payload['script']) ? (int) $payload['script'] : null;
        $local = $scriptId !== null && $scriptId > 0
            ? TacticalScript::where('tactical_script_id', $scriptId)->first()
            : null;

        if ($local === null) {
            throw new TacticalClientException(
                'Refusing to create this script check: the script ('.($scriptId ?? 'unknown id').') is not in the local synced '
                .'script catalog and no script metadata was supplied, so its platform constraints cannot be verified. '
                .'Run tactical:sync-scripts first — attaching a script blind is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        $resolved = [
            'shell' => $local->shell,
            'supported_platforms' => is_array($local->supported_platforms) ? $local->supported_platforms : null,
        ];

        if (! self::hasUsablePlatformSignal($resolved['shell'], $resolved['supported_platforms'])) {
            throw new TacticalClientException(
                'Refusing to create this script check: the synced catalog row for script '.($scriptId ?? '?').' carries neither '
                .'a shell nor any supported_platforms, so its platform constraints cannot be verified — absence of metadata is '
                .'not compatibility (psa-0pb9m). Re-run tactical:sync-scripts, or verify the script in Tactical.'
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
