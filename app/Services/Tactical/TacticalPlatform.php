<?php

namespace App\Services\Tactical;

/**
 * Platform resolution + script/platform compatibility probe (psa-0pb9m).
 *
 * Tactical macOS/Linux agents only run SCRIPT checks (vendor constraint), so a
 * script check whose script cannot execute on the agent's platform fails on
 * 100% of runs, forever — the "one check on every Mac fails on all of them"
 * defect class. Two conservative primitives, shared by the read surfaces
 * (annotating why a check always fails) and the create-check write guard
 * (refusing to attach one in the first place):
 *
 *  - fromAgentPayload(): the agent's platform from Tactical's own `plat` field
 *    when present, falling back to a conservative operating_system sniff.
 *    Unknown resolves to null, never a guess.
 *  - scriptIncompatibility(): a human-readable reason a script cannot (or very
 *    likely cannot) run on a platform, or null when compatible/unknown. The
 *    vendor's own supported_platforms metadata is authoritative in BOTH
 *    directions; shell heuristics apply only when metadata is absent, and only
 *    for shells that are definitively platform-bound.
 */
class TacticalPlatform
{
    public const WINDOWS = 'windows';

    public const DARWIN = 'darwin';

    public const LINUX = 'linux';

    /**
     * Resolve an agent's platform from the Tactical payload. Prefers the
     * explicit `plat` field (windows|darwin|linux); falls back to sniffing the
     * operating_system string. Returns null when neither identifies a known
     * platform — callers must treat null as "unknown", never assume.
     */
    public static function fromAgentPayload(?string $plat, ?string $os): ?string
    {
        $normalized = mb_strtolower(trim((string) $plat));
        if (in_array($normalized, [self::WINDOWS, self::DARWIN, self::LINUX], true)) {
            return $normalized;
        }

        $osLower = mb_strtolower(trim((string) $os));
        if ($osLower === '') {
            return null;
        }

        return match (true) {
            str_contains($osLower, 'darwin'),
            str_contains($osLower, 'macos'),
            str_contains($osLower, 'mac os') => self::DARWIN,
            str_contains($osLower, 'windows') => self::WINDOWS,
            str_contains($osLower, 'linux'),
            str_contains($osLower, 'ubuntu'),
            str_contains($osLower, 'debian'),
            str_contains($osLower, 'centos'),
            str_contains($osLower, 'fedora') => self::LINUX,
            default => null,
        };
    }

    /**
     * Why a script cannot (or very likely cannot) run on $platform, or null
     * when it is compatible or we cannot honestly claim otherwise.
     *
     * Rules, most authoritative first:
     *  1. Unknown platform or shell → null (no claim on missing data).
     *  2. Non-empty supported_platforms is the vendor's own declaration and is
     *     final both ways: excludes the platform → incompatible; includes it →
     *     compatible even for a powershell script (the operator has declared
     *     pwsh present).
     *  3. Without metadata: `cmd` exists only on Windows → incompatible on
     *     darwin/linux. `powershell` needs pwsh, which is rarely installed on
     *     darwin/linux → likely-incompatible. Cross-platform shells (shell,
     *     python, nushell, deno, …) are never flagged by heuristic alone.
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     */
    public static function scriptIncompatibility(?string $platform, ?string $shell, ?array $supportedPlatforms): ?string
    {
        $platform = mb_strtolower(trim((string) $platform));
        if (! in_array($platform, [self::WINDOWS, self::DARWIN, self::LINUX], true)) {
            return null;
        }

        $declared = array_values(array_filter(array_map(
            static fn (mixed $p): string => is_scalar($p) ? mb_strtolower(trim((string) $p)) : '',
            $supportedPlatforms ?? [],
        )));

        if ($declared !== []) {
            if (! in_array($platform, $declared, true)) {
                return "the script's supported_platforms (".implode(', ', $declared).") does not include {$platform}";
            }

            return null; // vendor metadata vouches for this platform
        }

        $shell = mb_strtolower(trim((string) $shell));
        if ($shell === '') {
            return null;
        }

        if ($shell === 'cmd' && $platform !== self::WINDOWS) {
            return "the script's shell is cmd, which only exists on Windows (agent platform: {$platform})";
        }

        if ($shell === 'powershell' && $platform !== self::WINDOWS) {
            return "the script's shell is powershell, which requires PowerShell (pwsh) — rarely present on {$platform} agents";
        }

        return null;
    }

    /**
     * Platform-mismatch verdict for ONE check row (getAgentChecks shape),
     * resolved against the LOCAL synced script catalog — zero live calls.
     * Shared by TacticalReadOnlyToolset and TriageToolExecutor so both AI
     * surfaces speak the same idiom (psa-0pb9m).
     *
     * Tri-state: mismatch true + reason when the attached script cannot (or
     * very likely cannot) run on the platform; false when a script check is
     * assessable and compatible; null when we cannot honestly tell (non-script
     * check, unknown platform, or script not in the synced catalog).
     *
     * @param  array<string, mixed>  $check
     * @return array{mismatch: ?bool, reason: ?string}
     */
    public static function checkScriptMismatch(array $check, ?string $platform): array
    {
        if ($platform === null || (($check['check_type'] ?? null) !== 'script')) {
            return ['mismatch' => null, 'reason' => null];
        }

        $script = $check['script'] ?? null;
        $scriptId = is_array($script)
            ? ($script['id'] ?? $script['pk'] ?? null)
            : $script;

        if (! is_numeric($scriptId) || (int) $scriptId <= 0) {
            return ['mismatch' => null, 'reason' => null];
        }

        $local = \App\Models\TacticalScript::where('tactical_script_id', (int) $scriptId)->first();
        if (! $local) {
            return ['mismatch' => null, 'reason' => null];
        }

        $reason = self::scriptIncompatibility(
            $platform,
            $local->shell,
            is_array($local->supported_platforms) ? $local->supported_platforms : null,
        );

        return ['mismatch' => $reason !== null, 'reason' => $reason];
    }
}
