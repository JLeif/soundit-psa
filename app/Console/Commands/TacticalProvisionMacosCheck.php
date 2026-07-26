<?php

namespace App\Console\Commands;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalMacosCheckProvisioner;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;

/**
 * psa-0pb9m — install the shipped macOS disk-capacity check on darwin
 * Tactical agents. Dry-run by default (prints the plan); --apply executes.
 * Plan-first and no-clobber: ambiguous scopes and any drift on an existing
 * same-name script ABORT before anything is written — there is deliberately
 * no overwrite flag (the script object is global; reconcile a drifted
 * same-name script in Tactical by renaming or editing it there). Idempotent:
 * agents already carrying the check are skipped. Operator-run only — never
 * scheduled; re-run it after enrolling new Macs.
 */
class TacticalProvisionMacosCheck extends Command
{
    protected $signature = 'tactical:provision-macos-check
        {--client-id= : Limit to one PSA client id}
        {--hostname= : Limit to one agent hostname (must resolve to exactly one macOS agent; combine with --client-id when hostnames repeat across clients)}
        {--agent-id= : Limit to exactly one agent by Tactical agent id (the unambiguous form of --hostname)}
        {--apply : Execute the plan (default is dry-run)}';

    protected $description = 'Provision the PSA macOS Disk Capacity Check script + per-agent script checks on darwin Tactical agents (dry-run by default; plan-first, no-clobber)';

    public function handle(): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->warn('Tactical RMM is not configured.');

            return self::FAILURE;
        }

        $clientId = $this->option('client-id') !== null ? (int) $this->option('client-id') : null;
        $hostname = $this->option('hostname') !== null ? (string) $this->option('hostname') : null;
        $agentId = $this->option('agent-id') !== null ? (string) $this->option('agent-id') : null;
        $apply = (bool) $this->option('apply');

        $provisioner = new TacticalMacosCheckProvisioner(app(TacticalClient::class));

        try {
            $result = $provisioner->provision(
                apply: $apply,
                clientId: $clientId,
                hostname: $hostname,
                agentId: $agentId,
            );
        } catch (\Throwable $e) {
            $this->error('Provisioning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $mode = $result['dry_run'] ? 'DRY-RUN (no changes made — pass --apply to execute)' : 'APPLY';
        $this->info("Mode: {$mode}");
        $this->info("Script '".TacticalMacosCheckProvisioner::SCRIPT_NAME."': {$result['script_action']}".($result['script_id'] !== null ? " (Tactical script id {$result['script_id']})" : ''));

        if ($result['aborted']) {
            foreach ($result['errors'] as $error) {
                $this->error("  {$error}");
            }
            $this->error('Aborted before any write — resolve the above and re-run.');

            return self::FAILURE;
        }

        if ($result['targets'] === []) {
            $this->warn('No macOS agents matched the scope; nothing was written. (Targets come from the synced tactical_assets snapshot — run tactical:sync-devices first if the fleet looks incomplete.)');
        }

        foreach ($result['targets'] as $target) {
            $line = "  {$target['hostname']} ({$target['agent_id']}): {$target['action']}";
            if ($target['reason'] !== null) {
                $line .= " — {$target['reason']}";
            }
            $this->line($line);
        }

        foreach ($result['errors'] as $error) {
            $this->error("  {$error}");
        }

        if (! $result['dry_run'] && $result['targets'] !== []) {
            $this->info('Next: the check runs on the agents\' next check interval. Verify end-to-end per docs/INSTALL.md — '
                .'tactical_get_device_checks should show "'.TacticalMacosCheckProvisioner::SCRIPT_NAME.'" passing and checks_coverage=verified, '
                .'and any legacy wrong-platform check (platform_mismatch=true) should be removed in Tactical.');
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
