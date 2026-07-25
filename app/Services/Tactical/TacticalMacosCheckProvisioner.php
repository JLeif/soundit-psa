<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use Illuminate\Support\Facades\Log;

/**
 * Provision the shipped macOS health check (psa-0pb9m).
 *
 * macOS/Linux Tactical agents support SCRIPT checks only, and the fleet's
 * single Mac check failed on 100% of runs — Macs were visible in RMM while
 * nothing verified them. This provisioner installs the curated darwin-native
 * check (resources/tactical/checks/psa-macos-health-check.sh) so Macs regain
 * VERIFIED coverage: a check that genuinely runs, reports real state, and can
 * pass.
 *
 * Operator-run (artisan tactical:provision-macos-check), never scheduled.
 * Contract, mirroring TacticalProvisioningService's no-clobber discipline:
 *   - DRY-RUN by default: reads compute the real plan; zero writes.
 *   - Script upsert by exact name (create, or update-in-place to keep the
 *     body current) — id resolved by re-listing, highest id wins, because
 *     Tactical's create endpoints return a scalar, not an object.
 *   - Per-agent idempotence: an agent already carrying a check that points at
 *     our script is skipped, never duplicated.
 *   - darwin agents only. A non-darwin host explicitly targeted by hostname
 *     is refused with a reason — this tool exists because a wrong-platform
 *     check IS the defect, so it must be impossible to recreate one here.
 *   - Per-agent read failures become error rows; the run continues.
 */
class TacticalMacosCheckProvisioner
{
    public const SCRIPT_NAME = 'PSA macOS Health Check';

    public const CHECK_TIMEOUT_SECONDS = 60;

    private const SCRIPT_RELATIVE_PATH = 'tactical/checks/psa-macos-health-check.sh';

    public function __construct(private readonly TacticalClient $client) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     script_action: string,
     *     script_id: ?int,
     *     targets: array<int, array{hostname: string, agent_id: string, action: string, reason: ?string}>,
     *     errors: array<int, string>,
     * }
     */
    public function provision(bool $apply, ?int $clientId = null, ?string $hostname = null): array
    {
        $errors = [];

        // ── 1. Script upsert (by exact name; Tactical allows duplicates, so
        //       highest id wins — same discipline as the webhook provisioner).
        $scriptBody = $this->scriptUpsertBody();
        $existingId = $this->findScriptIdByName(self::SCRIPT_NAME);
        $scriptAction = $existingId === null ? 'create' : 'update';
        $scriptId = $existingId;

        if ($apply) {
            if ($existingId === null) {
                $this->client->createScript($scriptBody);
                $scriptId = $this->findScriptIdByName(self::SCRIPT_NAME);
                if ($scriptId === null) {
                    throw new TacticalClientException(
                        'Tactical script "'.self::SCRIPT_NAME.'" was created but could not be resolved by name in getScripts.'
                    );
                }
            } else {
                $this->client->updateScript($existingId, $scriptBody);
            }
        }

        // ── 2. Target selection: darwin agents only, never a guess.
        $query = TacticalAsset::query();
        if ($hostname !== null && trim($hostname) !== '') {
            $query->whereRaw('LOWER(hostname) = ?', [mb_strtolower(trim($hostname))]);
        }
        if ($clientId !== null) {
            $query->whereHas('asset', fn ($assetQuery) => $assetQuery->where('client_id', $clientId));
        }

        $targets = [];
        foreach ($query->orderByRaw('LOWER(COALESCE(hostname, ""))')->get() as $agent) {
            $platform = $agent->platform();

            if ($platform !== TacticalPlatform::DARWIN) {
                // Only complain when the operator explicitly named this host;
                // fleet sweeps silently pass over non-Macs.
                if ($hostname !== null && trim($hostname) !== '') {
                    $errors[] = "'{$agent->hostname}' is not a macOS agent (platform: ".($platform ?? 'unknown').') — refusing to attach the macOS check.';
                }

                continue;
            }

            $row = [
                'hostname' => (string) $agent->hostname,
                'agent_id' => (string) $agent->agent_id,
                'action' => 'create',
                'reason' => null,
            ];

            try {
                $checks = $this->client->getAgentChecks((string) $agent->agent_id);
            } catch (\Throwable $e) {
                $errors[] = "Could not read existing checks for '{$agent->hostname}': ".mb_substr($e->getMessage(), 0, 150);

                continue;
            }

            if ($scriptId !== null && $this->hasOurCheck($checks, $scriptId)) {
                $row['action'] = 'skip';
                $row['reason'] = 'already has the PSA macOS Health Check';
                $targets[] = $row;

                continue;
            }

            if ($apply) {
                try {
                    $this->client->createCheck([
                        'agent' => (string) $agent->agent_id,
                        'check_type' => 'script',
                        'script' => (int) $scriptId,
                        'name' => self::SCRIPT_NAME,
                        'fails_b4_alert' => 1,
                        'timeout' => self::CHECK_TIMEOUT_SECONDS,
                        'success_return_codes' => [0],
                        'info_return_codes' => [],
                        'warning_return_codes' => [],
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = "Check create failed for '{$agent->hostname}': ".mb_substr($e->getMessage(), 0, 150);

                    continue;
                }
            }

            $targets[] = $row;
        }

        Log::info('[TacticalMacosCheck] provision run', [
            'apply' => $apply,
            'script_action' => $scriptAction,
            'targets' => count($targets),
            'errors' => count($errors),
        ]);

        return [
            'dry_run' => ! $apply,
            'script_action' => $scriptAction,
            'script_id' => $scriptId,
            'targets' => $targets,
            'errors' => $errors,
        ];
    }

    /** The TRMM script payload, body read fresh from the shipped file. */
    private function scriptUpsertBody(): array
    {
        $path = resource_path(self::SCRIPT_RELATIVE_PATH);
        $body = @file_get_contents($path);
        if ($body === false || trim($body) === '') {
            throw new \RuntimeException("Shipped macOS health check script missing or empty at {$path}.");
        }

        return [
            'name' => self::SCRIPT_NAME,
            'description' => 'PSA-provisioned macOS health check: data-volume disk usage (fails at >=90% used or <10 GB free), plus report-only uptime, load, SIP, FileVault, and macOS version. darwin-native tools only; no TCC-protected reads. Shipped in the PSA repo (resources/tactical/checks/).',
            'shell' => 'shell',
            'category' => 'PSA',
            'script_body' => $body,
            'default_timeout' => self::CHECK_TIMEOUT_SECONDS,
            'supported_platforms' => [TacticalPlatform::DARWIN],
            'args' => [],
            'env_vars' => [],
            'run_as_user' => false,
        ];
    }

    /** Highest-id script matching the name, or null. Tactical allows duplicate names. */
    private function findScriptIdByName(string $name): ?int
    {
        $bestId = null;
        foreach ($this->client->getScripts(true, true) as $script) {
            if (! is_array($script) || strcasecmp((string) ($script['name'] ?? ''), $name) !== 0) {
                continue;
            }

            $id = $script['id'] ?? null;
            if (is_int($id) && $id > 0 && ($bestId === null || $id > $bestId)) {
                $bestId = $id;
            }
        }

        return $bestId;
    }

    /** @param array<int, mixed> $checks */
    private function hasOurCheck(array $checks, int $scriptId): bool
    {
        foreach ($checks as $check) {
            if (! is_array($check) || ($check['check_type'] ?? null) !== 'script') {
                continue;
            }

            $script = $check['script'] ?? null;
            $id = is_array($script) ? ($script['id'] ?? $script['pk'] ?? null) : $script;

            if (is_numeric($id) && (int) $id === $scriptId) {
                return true;
            }
        }

        return false;
    }
}
