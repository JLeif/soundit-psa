<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Models\TacticalScript;
use Illuminate\Support\Facades\Log;

/**
 * Provision the shipped macOS disk-capacity check (psa-0pb9m).
 *
 * macOS/Linux Tactical agents support SCRIPT checks only, and the fleet's
 * single Mac check failed on 100% of runs — Macs were visible in RMM while
 * nothing verified them. This provisioner installs the curated darwin-native
 * check (resources/tactical/checks/psa-macos-disk-capacity-check.sh) so Macs
 * regain VERIFIED coverage: a check that genuinely runs, reports real state,
 * and can pass. Its verdict gates on disk capacity ONLY — the name says what
 * it does, so a pass is never read as a whole-device all-clear.
 *
 * Operator-run (artisan tactical:provision-macos-check), never scheduled.
 * Contract (revise: plan-first, no-clobber, scope-safe):
 *
 *   - DRY-RUN by default: reads compute the real plan; zero writes.
 *   - PLAN BEFORE MUTATION: the script decision and the full target plan are
 *     resolved read-only first; any ambiguity (duplicate same-name scripts,
 *     a hostname matching more than one agent, an explicitly-named non-Mac)
 *     ABORTS the run before anything is written — including the script.
 *   - NO-CLOBBER script ownership: the managed script is created only when
 *     absent. An existing same-name script is REUSED only when its body,
 *     shell, and supported_platforms match the shipped definition exactly;
 *     any drift is REFUSED, always — there is deliberately NO overwrite
 *     switch (psa-0pb9m R2: the script object is global, so an overwrite
 *     would rewrite every check referencing it fleet-wide, and a same-name
 *     script without a provable ownership marker may be operator-owned).
 *     Reconciliation is out-of-band, in Tactical where ownership is visible:
 *     rename the existing script to release the reserved name (this tool
 *     then creates the managed one), or edit/delete it there deliberately.
 *     More than one same-name script is an unresolvable ownership collision
 *     and always refuses.
 *   - LOCAL CATALOG UPSERT on apply: the synced TacticalScript row is written
 *     alongside the upstream script, so the client-boundary platform guard
 *     and the per-check platform_mismatch annotations see the script's
 *     constraints immediately (not only after the next daily script sync).
 *   - Per-agent idempotence: an agent already carrying a check that points at
 *     our script is skipped, never duplicated.
 *   - darwin agents only. A non-darwin or unknown-platform host explicitly
 *     targeted by hostname/agent-id is refused with a reason — this tool
 *     exists because a wrong-platform check IS the defect, so it must be
 *     impossible to recreate one here. Fleet sweeps silently pass over
 *     non-Macs.
 *   - Per-agent read failures become error rows; the run continues.
 *   - NOT concurrency-safe by design: operator-run, one at a time. Two
 *     simultaneous --apply runs could race the create-then-resolve step;
 *     sequential re-runs are idempotent.
 *   - Deliberately never deletes or retargets the pre-existing wrong-platform
 *     check — removing an operator's check is a human decision. The runbook
 *     (docs/INSTALL.md) covers finding it (platform_mismatch=true) and
 *     cleaning it up.
 */
class TacticalMacosCheckProvisioner
{
    public const SCRIPT_NAME = 'PSA macOS Disk Capacity Check';

    public const CHECK_TIMEOUT_SECONDS = 60;

    private const SCRIPT_RELATIVE_PATH = 'tactical/checks/psa-macos-disk-capacity-check.sh';

    private const SCRIPT_SHELL = 'shell';

    private const SCRIPT_PLATFORMS = [TacticalPlatform::DARWIN];

    public function __construct(private readonly TacticalClient $client) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     aborted: bool,
     *     script_action: string,
     *     script_id: ?int,
     *     targets: array<int, array{hostname: string, agent_id: string, action: string, reason: ?string}>,
     *     errors: array<int, string>,
     * }
     */
    public function provision(
        bool $apply,
        ?int $clientId = null,
        ?string $hostname = null,
        ?string $agentId = null,
    ): array {
        $errors = [];
        $scriptBody = $this->shippedScriptBody();

        // ── 1. Script decision (READ-ONLY — nothing is written yet). ──
        $script = $this->resolveScriptPlan($scriptBody, $errors);
        if ($script['abort']) {
            return $this->aborted($apply, $script['action'], $script['id'], $errors);
        }

        // ── 2. Target plan (READ-ONLY). Ambiguity aborts before any write. ──
        $agents = $this->resolveTargetAgents($clientId, $hostname, $agentId, $errors);
        if ($agents === null) {
            return $this->aborted($apply, $script['action'], $script['id'], $errors);
        }

        // ── 3. Apply the script decision — only when there are Macs in scope
        //       to attach it to; an empty plan writes nothing at all. ──
        $scriptId = $script['id'];
        if ($apply && $agents !== []) {
            $scriptId = $this->applyScriptPlan($script['action'], $script['id'], $scriptBody);
            $this->upsertLocalCatalogRow($scriptId);
        }

        // ── 4. Per-agent checks: skip when ours is already attached. ──
        $targets = [];
        foreach ($agents as $agent) {
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
                $row['reason'] = 'already has the '.self::SCRIPT_NAME;
                $targets[] = $row;

                continue;
            }

            if ($apply) {
                try {
                    // The scriptMeta claim mirrors the shipped definition, so
                    // the client-boundary platform guard assesses the same
                    // vendor truth we just provisioned.
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
                    ], scriptMeta: [
                        'shell' => self::SCRIPT_SHELL,
                        'supported_platforms' => self::SCRIPT_PLATFORMS,
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
            'script_action' => $script['action'],
            'targets' => count($targets),
            'errors' => count($errors),
        ]);

        return [
            'dry_run' => ! $apply,
            'aborted' => false,
            'script_action' => $script['action'],
            'script_id' => $scriptId,
            'targets' => $targets,
            'errors' => $errors,
        ];
    }

    /** The shipped script body, read fresh from the repo file. */
    private function shippedScriptBody(): string
    {
        $path = resource_path(self::SCRIPT_RELATIVE_PATH);
        $body = @file_get_contents($path);
        if ($body === false || trim($body) === '') {
            throw new \RuntimeException("Shipped macOS disk-capacity check script missing or empty at {$path}.");
        }

        return $body;
    }

    /**
     * Decide what would happen to the managed script WITHOUT writing:
     *   create         — no script with the reserved name exists.
     *   unchanged      — exactly one exists and matches the shipped body,
     *                    shell, and supported_platforms; it is reused as-is.
     *   drift-refused  — exactly one exists and it DRIFTS from the shipped
     *                    definition: ABORT, always (no-clobber — there is no
     *                    overwrite switch; see the class docblock).
     *   ambiguous      — multiple same-name scripts: ownership cannot be
     *                    claimed; ABORT.
     *
     * @param  array<int, string>  $errors
     * @return array{action: string, id: ?int, abort: bool}
     */
    private function resolveScriptPlan(string $shippedBody, array &$errors): array
    {
        $matches = $this->scriptsNamed(self::SCRIPT_NAME);

        if (count($matches) > 1) {
            $ids = implode(', ', array_map(fn (array $s): string => (string) ($s['id'] ?? '?'), $matches));
            $errors[] = 'Refusing to provision: '.count($matches)." Tactical scripts are named '".self::SCRIPT_NAME."' (ids {$ids}). "
                .'Ownership of the managed script cannot be established — rename or remove the duplicates in Tactical, then re-run.';

            return ['action' => 'ambiguous', 'id' => null, 'abort' => true];
        }

        if ($matches === []) {
            return ['action' => 'create', 'id' => null, 'abort' => false];
        }

        $existing = $matches[0];
        $existingId = (int) $existing['id'];

        if ($this->matchesShippedDefinition($existing, $existingId, $shippedBody)) {
            return ['action' => 'unchanged', 'id' => $existingId, 'abort' => false];
        }

        // An ownership collision ALWAYS refuses (psa-0pb9m R2). The script
        // object is GLOBAL: overwriting it would rewrite every check that
        // references it fleet-wide — far beyond any --client-id/--hostname
        // scope on this run — and a drifted same-name body may simply be the
        // operator's own script. Reconciliation happens in Tactical, where
        // ownership is visible, never via a provisioner switch.
        $errors[] = "Refusing to touch Tactical script '".self::SCRIPT_NAME."' (id {$existingId}): its body or platform metadata "
            .'differs from the shipped definition (operator-edited, or from an older PSA version), so ownership cannot be proven. '
            .'This tool never overwrites it — the script object is global, and rewriting it would change every check that references it. '
            .'Reconcile in Tactical instead: RENAME the existing script to release the reserved name (then re-run to create the managed one), '
            .'or deliberately edit/delete it there. Nothing was written.';

        return ['action' => 'drift-refused', 'id' => $existingId, 'abort' => true];
    }

    /**
     * Does the existing upstream script match the shipped definition exactly
     * (raw body via GET scripts/{pk}/download/?with_snippets=false — vendor
     * returns {filename, code}, scripts/views.py `download` — plus shell and
     * supported_platforms from the list row)? Read failures count as drift:
     * when we cannot prove ownership we do not overwrite.
     */
    private function matchesShippedDefinition(array $listRow, int $scriptId, string $shippedBody): bool
    {
        $shell = mb_strtolower(trim((string) ($listRow['shell'] ?? '')));
        if ($shell !== self::SCRIPT_SHELL) {
            return false;
        }

        $platforms = array_map(
            static fn (mixed $p): string => is_scalar($p) ? mb_strtolower(trim((string) $p)) : '',
            is_array($listRow['supported_platforms'] ?? null) ? $listRow['supported_platforms'] : [],
        );
        sort($platforms);
        if ($platforms !== self::SCRIPT_PLATFORMS) {
            return false;
        }

        try {
            $download = $this->client->downloadScript($scriptId, withSnippets: false);
        } catch (\Throwable) {
            return false;
        }

        $existingBody = is_array($download) && is_scalar($download['code'] ?? null) ? (string) $download['code'] : '';

        return trim($existingBody) === trim($shippedBody);
    }

    /** Execute the (already-validated) script decision; returns the script id. */
    private function applyScriptPlan(string $action, ?int $existingId, string $scriptBody): int
    {
        if ($action === 'create') {
            $this->client->createScript($this->scriptUpsertBody($scriptBody));
            $created = $this->scriptsNamed(self::SCRIPT_NAME);
            if (count($created) !== 1 || ! is_int($created[0]['id'] ?? null)) {
                throw new TacticalClientException(
                    'Tactical script "'.self::SCRIPT_NAME.'" was created but could not be uniquely resolved by name in getScripts.'
                );
            }

            return (int) $created[0]['id'];
        }

        // 'unchanged' writes nothing (drift always aborted before this point —
        // there is no overwrite action; see resolveScriptPlan).
        return (int) $existingId;
    }

    /**
     * Mirror the managed script into the local synced catalog so the
     * client-boundary platform guard and platform_mismatch annotations can
     * read its constraints immediately (the daily tactical:sync-scripts would
     * otherwise leave a blind window).
     */
    private function upsertLocalCatalogRow(int $scriptId): void
    {
        TacticalScript::updateOrCreate(
            ['tactical_script_id' => $scriptId],
            [
                'name' => self::SCRIPT_NAME,
                'description' => $this->scriptDescription(),
                'shell' => self::SCRIPT_SHELL,
                'default_timeout' => self::CHECK_TIMEOUT_SECONDS,
                'supported_platforms' => self::SCRIPT_PLATFORMS,
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Resolve the darwin agents in scope, or null to ABORT (ambiguous or
     * explicitly-targeted-but-invalid scope). Explicit targets (--hostname /
     * --agent-id) must resolve to exactly one darwin agent; fleet/client
     * sweeps silently pass over non-Macs.
     *
     * @param  array<int, string>  $errors
     * @return array<int, TacticalAsset>|null
     */
    private function resolveTargetAgents(?int $clientId, ?string $hostname, ?string $agentId, array &$errors): ?array
    {
        $hostname = $hostname !== null && trim($hostname) !== '' ? trim($hostname) : null;
        $agentId = $agentId !== null && trim($agentId) !== '' ? trim($agentId) : null;
        $explicit = $hostname !== null || $agentId !== null;

        $query = TacticalAsset::query();
        if ($agentId !== null) {
            $query->where('agent_id', $agentId);
        }
        if ($hostname !== null) {
            $query->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)]);
        }
        if ($clientId !== null) {
            $query->whereHas('asset', fn ($assetQuery) => $assetQuery->where('client_id', $clientId));
        }

        $matched = $query->orderByRaw('LOWER(COALESCE(hostname, ""))')->get();

        if ($explicit) {
            if ($matched->isEmpty()) {
                $errors[] = 'No synced Tactical agent matches the requested '
                    .($agentId !== null ? "agent id '{$agentId}'" : "hostname '{$hostname}'")
                    .'. (Targets come from the tactical_assets snapshot — run tactical:sync-devices first.)';

                return null;
            }

            if ($matched->count() > 1) {
                // A hostname is NOT unique across clients — writing to every
                // match would fan out beyond the requested scope (revise).
                $candidates = $matched->map(fn (TacticalAsset $a): string => "'{$a->hostname}' (agent {$a->agent_id}"
                    .($a->asset?->client_id !== null ? ", PSA client {$a->asset->client_id}" : ', unlinked').')')->implode('; ');
                $errors[] = "Hostname '{$hostname}' matches ".$matched->count()." Tactical agents: {$candidates}. "
                    .'Narrow the scope with --client-id together with --hostname, or target exactly one agent with --agent-id. Nothing was written.';

                return null;
            }
        }

        $targets = [];
        foreach ($matched as $agent) {
            $platform = $agent->platform();

            if ($platform !== TacticalPlatform::DARWIN) {
                // Only complain when the operator explicitly named this host;
                // fleet sweeps silently pass over non-Macs.
                if ($explicit) {
                    $errors[] = "'{$agent->hostname}' is not a macOS agent (platform: ".($platform ?? 'unknown').') — refusing to attach the macOS check. Nothing was written.';

                    return null;
                }

                continue;
            }

            $targets[] = $agent;
        }

        return $targets;
    }

    /** The TRMM script payload for create/update. */
    private function scriptUpsertBody(string $body): array
    {
        return [
            'name' => self::SCRIPT_NAME,
            'description' => $this->scriptDescription(),
            'shell' => self::SCRIPT_SHELL,
            'category' => 'PSA',
            'script_body' => $body,
            'default_timeout' => self::CHECK_TIMEOUT_SECONDS,
            'supported_platforms' => self::SCRIPT_PLATFORMS,
            'args' => [],
            'env_vars' => [],
            'run_as_user' => false,
        ];
    }

    private function scriptDescription(): string
    {
        return 'PSA-provisioned macOS disk-capacity check: fails at >=90% used or <10 GB free on the data volume; '
            .'uptime, load, SIP, FileVault, and macOS version are REPORT-ONLY and never affect the verdict. '
            .'Refuses to run on non-Darwin hosts. darwin-native tools only; no TCC-protected reads. '
            .'Shipped in the PSA repo (resources/tactical/checks/) and managed by tactical:provision-macos-check — '
            .'edits here will be flagged as drift and never silently overwritten.';
    }

    /**
     * All getScripts rows carrying the reserved name (case-insensitive) with a
     * usable integer id. Tactical allows duplicate names, so the caller
     * decides what multiplicity means.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scriptsNamed(string $name): array
    {
        $matches = [];
        foreach ($this->client->getScripts(true, true) as $script) {
            if (! is_array($script) || strcasecmp((string) ($script['name'] ?? ''), $name) !== 0) {
                continue;
            }

            $id = $script['id'] ?? null;
            if (is_int($id) && $id > 0) {
                $matches[] = $script;
            }
        }

        return $matches;
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

    /**
     * @param  array<int, string>  $errors
     * @return array{dry_run: bool, aborted: bool, script_action: string, script_id: ?int, targets: array<int, mixed>, errors: array<int, string>}
     */
    private function aborted(bool $apply, string $scriptAction, ?int $scriptId, array $errors): array
    {
        Log::warning('[TacticalMacosCheck] provision aborted before any write', [
            'apply' => $apply,
            'script_action' => $scriptAction,
            'errors' => $errors,
        ]);

        return [
            'dry_run' => ! $apply,
            'aborted' => true,
            'script_action' => $scriptAction,
            'script_id' => $scriptId,
            'targets' => [],
            'errors' => $errors,
        ];
    }
}
