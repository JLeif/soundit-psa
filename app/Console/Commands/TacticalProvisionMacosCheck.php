<?php

namespace App\Console\Commands;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalMacosCheckProvisioner;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;

/**
 * psa-0pb9m — install the shipped macOS health check on darwin Tactical
 * agents. Dry-run by default (prints the plan); --apply executes. Idempotent:
 * the script is upserted by name and agents already carrying the check are
 * skipped. Operator-run only — never scheduled.
 */
class TacticalProvisionMacosCheck extends Command
{
    protected $signature = 'tactical:provision-macos-check
        {--client-id= : Limit to one PSA client id}
        {--hostname= : Limit to one agent hostname (must be a macOS agent)}
        {--apply : Execute the plan (default is dry-run)}';

    protected $description = 'Provision the PSA macOS Health Check script + per-agent script checks on darwin Tactical agents (dry-run by default)';

    public function handle(): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->warn('Tactical RMM is not configured.');

            return self::FAILURE;
        }

        $clientId = $this->option('client-id') !== null ? (int) $this->option('client-id') : null;
        $hostname = $this->option('hostname') !== null ? (string) $this->option('hostname') : null;
        $apply = (bool) $this->option('apply');

        $provisioner = new TacticalMacosCheckProvisioner(app(TacticalClient::class));

        try {
            $result = $provisioner->provision(apply: $apply, clientId: $clientId, hostname: $hostname);
        } catch (\Throwable $e) {
            $this->error('Provisioning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $mode = $result['dry_run'] ? 'DRY-RUN (no changes made — pass --apply to execute)' : 'APPLY';
        $this->info("Mode: {$mode}");
        $this->info("Script '".TacticalMacosCheckProvisioner::SCRIPT_NAME."': {$result['script_action']}".($result['script_id'] !== null ? " (Tactical script id {$result['script_id']})" : ''));

        if ($result['targets'] === []) {
            $this->warn('No macOS agents matched the scope. (Targets come from the synced tactical_assets snapshot — run tactical:sync-devices first if the fleet looks incomplete.)');
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

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
