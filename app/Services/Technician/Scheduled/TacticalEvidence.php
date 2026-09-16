<?php

namespace App\Services\Technician\Scheduled;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Tactical\TacticalClient;
use App\Support\TacticalConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final class TacticalEvidence implements ScheduledEvidence
{
    public function __construct(private TacticalClient $client, private StaffTacticalActionToolExecutor $executor) {}

    public function approve(TechnicianRun $run, User $approver, array $humanInputs): array
    {
        if (! config('scheduled_approvals.enabled') || TechnicianConfig::killSwitchEngaged()) {
            throw new ScheduledUnavailable('kill_switch');
        }
        if (! TacticalConfig::isEnabled() || ! TacticalConfig::isConfigured() || ! TacticalPlan::supports($run->action_type)) {
            throw new InvalidArgumentException('integration_or_adapter_unavailable');
        }
        $payload = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload']), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_diff(array_keys($payload), ['direct_tool', 'asset_id', 'ticket_id', 'client_id', 'params'])
            || ($payload['direct_tool'] ?? null) !== ActionRegistry::directTool($run->action_type)
            || ($payload['ticket_id'] ?? null) !== $run->ticket_id || ($payload['client_id'] ?? null) !== $run->client_id
            || ! is_int($payload['asset_id'] ?? null) || ! is_array($payload['params'] ?? null)) {
            throw new InvalidArgumentException('proposal_binding_changed');
        }
        $params = TacticalPlan::params($run->action_type, $payload['params']);
        $asset = Asset::with('tacticalAsset')->find($payload['asset_id']);
        $ticket = Ticket::find($run->ticket_id);
        $owner = Client::find($run->client_id);
        $agent = $asset?->tacticalAsset?->agent_id;
        $site = $owner?->tactical_site_id;
        if (! $asset || (int) $asset->client_id !== $run->client_id || ! $ticket || (int) $ticket->client_id !== $run->client_id
            || ! $ticket->assets()->whereKey($asset->id)->exists() || ! is_string($agent) || $agent === '' || ! $site
            || Client::where('id', '!=', $owner->id)->where('tactical_site_id', $site)->exists()) {
            throw new InvalidArgumentException('device_binding_changed');
        }
        $this->executor->assertScheduledTacticalConfirmation($run, $asset, $params, $humanInputs);
        try {
            $live = $this->client->getAgent($agent);
            $services = isset($params['service_name']) ? $this->client->getServices($agent) : null;
        } catch (\Throwable) {
            throw new ScheduledUnavailable('read_unavailable');
        }
        // AgentSerializer (tacticalrmm@1e786d37) excludes only id: agent_id/hostname/site
        // are model fields; site is the FK, NOT site_name or client display-name aliases.
        if (($live['agent_id'] ?? null) !== $agent || ! is_int($live['site'] ?? null) || (string) $live['site'] !== (string) $site
            || ! is_string($live['hostname'] ?? null) || $live['hostname'] !== ($asset->hostname ?: $asset->name)) {
            throw new InvalidArgumentException('live_device_identity_changed');
        }
        if (($live['status'] ?? null) !== 'online') {
            throw new ScheduledUnavailable('offline');
        }
        if ($services !== null) {
            // services/views.py returns agent.services: use exact SCM name, never display alias.
            $matches = 0;
            foreach ($services as $service) {
                if (! is_array($service) || ! is_string($service['name'] ?? null)) {
                    throw new InvalidArgumentException('service_identity_unknown');
                }
                $matches += (int) ($service['name'] === $params['service_name']);
            }
            if ($matches !== 1) {
                throw new InvalidArgumentException('service_identity_changed');
            }
        }
        $namespace = hash('sha256', TacticalConfig::apiUrl());

        return ['payload' => ['type' => $run->action_type, 'asset_id' => $asset->id, 'agent_id' => $agent, 'params' => $params],
            'target' => ['tenant_id' => 'tactical:'.$namespace.':'.$site, 'object_id' => $agent],
            'hostname' => $live['hostname'], 'integration' => $namespace, 'human_inputs' => $humanInputs];
    }

    public function revalidate(TechnicianRun $run, User $approver, array $approved): array
    {
        return $this->approve($run, $approver, $approved['human_inputs']);
    }
}
