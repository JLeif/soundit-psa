<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Models\TacticalScript;

/**
 * The MANDATORY platform-safety gate for Tactical check creation (psa-0pb9m
 * revise). A wrong-platform script check fails on 100% of runs forever and
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
 *  - Script whose metadata cannot be resolved → refused. Metadata comes from
 *    the caller's vendor-sourced claim (an upstream getScripts row, the
 *    provisioner's shipped definition) or the local synced catalog
 *    (tactical:sync-scripts); creating blind is exactly how the original
 *    always-failing check shipped.
 *  - AGENT target with a provably incompatible script → refused, no override.
 *  - POLICY target with a script that some platform cannot run → refused
 *    UNLESS the caller passes an explicit acknowledgement
 *    ($acknowledgePlatformRisk). Policy membership is not enumerable here and
 *    a mixed-fleet policy is how the original wrong-platform check reached
 *    every Mac — the acknowledgement is the operator's pre-write confirmation
 *    that the policy's fleet is compatible, replacing the old (useless)
 *    post-write warning.
 *
 * Throws TacticalClientException so existing caller error paths surface the
 * refusal; nothing is sent upstream on refusal.
 */
class TacticalCheckPlatformGuard
{
    /**
     * @param  array<string, mixed>  $payload  The POST checks/ body about to be sent.
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     *                                                                                             Optional vendor-sourced script metadata claim from the caller
     *                                                                                             (e.g. the upstream getScripts row the MCP executor already
     *                                                                                             resolved, or the provisioner's shipped definition). When null,
     *                                                                                             metadata is resolved from the local synced script catalog.
     * @param  bool  $acknowledgePlatformRisk  Explicit pre-write confirmation
     *                                         for POLICY targets whose script is platform-bound.
     *
     * @throws TacticalClientException on refusal — nothing was sent upstream.
     */
    public static function assertSafe(array $payload, ?array $scriptMeta = null, bool $acknowledgePlatformRisk = false): void
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

        if ($isScriptCheck) {
            self::assertPolicyTargetSafe($payload, $scriptMeta, $acknowledgePlatformRisk);
        }
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
     * @param  array<string, mixed>  $payload
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     */
    private static function assertPolicyTargetSafe(array $payload, ?array $scriptMeta, bool $acknowledgePlatformRisk): void
    {
        $meta = self::resolveScriptMeta($payload, $scriptMeta);
        $blocked = self::incompatiblePlatforms($meta['shell'], $meta['supported_platforms']);

        if ($blocked === [] || $acknowledgePlatformRisk) {
            return;
        }

        throw new TacticalClientException(
            'Refusing to create this policy check before any write: the script cannot run on '.implode('/', $blocked).' agents, '
            .'and policy membership cannot be verified from here — if the policy covers any such agent, the check fails on every run there '
            .'and manufactures broken coverage (the original psa-0pb9m defect arrived through exactly this route). '
            .'First confirm the policy applies ONLY to compatible platforms, then retry with the explicit acknowledgement flag '
            .'(acknowledge_platform_risk). A post-write warning is not a safety control; this refusal happens before anything is sent.'
        );
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
     * local synced catalog; otherwise REFUSE — a script whose platform
     * constraints cannot be read must not be attached blind.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{shell?: ?string, supported_platforms?: ?array<int, mixed>}|null  $scriptMeta
     * @return array{shell: ?string, supported_platforms: ?array<int, mixed>}
     */
    private static function resolveScriptMeta(array $payload, ?array $scriptMeta): array
    {
        if ($scriptMeta !== null) {
            return [
                'shell' => isset($scriptMeta['shell']) && is_scalar($scriptMeta['shell']) ? (string) $scriptMeta['shell'] : null,
                'supported_platforms' => is_array($scriptMeta['supported_platforms'] ?? null) ? $scriptMeta['supported_platforms'] : null,
            ];
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

        return [
            'shell' => $local->shell,
            'supported_platforms' => is_array($local->supported_platforms) ? $local->supported_platforms : null,
        ];
    }
}
