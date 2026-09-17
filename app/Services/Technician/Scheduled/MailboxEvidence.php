<?php

namespace App\Services\Technician\Scheduled;

use App\Models\Client;
use App\Models\Person;
use App\Models\TechnicianRun;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Support\CippConfig;
use App\Support\TechnicianConfig;
use InvalidArgumentException;

/** Fresh ListTenants.customerId and ListUsers.id+userPrincipalName, never UPN/domain-only binding.
 * Producer: CIPP-API Get-Tenants / Invoke-ListUsers (same complete-row read as OffboardingScope).
 */
final class MailboxEvidence implements ScheduledEvidence
{
    public function __construct(private StaffCippWriteToolExecutor $executor, private CippRestWriteClient $client) {}

    public function approve(TechnicianRun $run, User $approver, array $humanInputs): array
    {
        $this->enabled();
        $plan = $this->executor->scheduledMailboxPlan($run, $humanInputs);
        try {
            $tenants = $this->client->offboardingRead('tenants');
            $users = $this->client->offboardingRead('users', ['tenantFilter' => $plan['tenant']]);
        } catch (\Throwable) {
            throw new ScheduledUnavailable('read_unavailable');
        }
        $matches = array_values(array_filter($tenants, fn ($t) => in_array(strtolower($plan['tenant']), $this->aliases($t), true)));
        if (count($matches) !== 1 || ! $this->uuid($matches[0]['customerId'] ?? null)) {
            throw new InvalidArgumentException('tenant_identity_unknown');
        }
        $aliases = $this->aliases($matches[0]);
        foreach (Client::whereNotNull('cipp_tenant_domain')->where('id', '!=', $run->client_id)->get() as $other) {
            if (in_array(strtolower(trim($other->cipp_tenant_domain)), $aliases, true)) {
                throw new InvalidArgumentException('tenant_mapping_ambiguous');
            }
        }
        foreach ($plan['people'] as $role => $person) {
            $id = strtolower($person['id']);
            $upn = strtolower($person['upn']);
            $rows = array_values(array_filter($users, fn ($u) => strtolower((string) ($u['id'] ?? '')) === $id || strtolower((string) ($u['userPrincipalName'] ?? '')) === $upn));
            if (! $this->uuid($id) || count($rows) !== 1 || strtolower((string) ($rows[0]['id'] ?? '')) !== $id
                || strtolower((string) ($rows[0]['userPrincipalName'] ?? '')) !== $upn
                || ($role === 'delegate_person' && $plan['params']['operation'] === 'grant' && ($rows[0]['accountEnabled'] ?? null) !== true)) {
                throw new InvalidArgumentException('person_identity_changed');
            }
            if (Person::where('client_id', $run->client_id)->where('id', '!=', $person['person_id'])
                ->where(fn ($q) => $q->whereRaw('LOWER(cipp_user_id) = ?', [$id])->orWhereRaw('LOWER(cipp_upn) = ?', [$upn]))->exists()) {
                throw new InvalidArgumentException('person_identity_ambiguous');
            }
        }
        // Resolve at approval and every fire-time preflight; a changed integration cannot retarget a grant.
        $namespace = hash('sha256', ApprovalEnvelope::canonical([
            CippConfig::get('api_url'), CippConfig::get('tenant_id'), CippConfig::get('client_id'), CippConfig::get('application_id'),
        ]));

        return ['payload' => $plan, 'target' => ['tenant_id' => strtolower($matches[0]['customerId']),
            'object_id' => strtolower($plan['people']['owner']['id'])], 'integration' => $namespace, 'human_inputs' => $humanInputs];
    }

    public function revalidate(TechnicianRun $run, User $approver, array $approved): array
    {
        return $this->approve($run, $approver, $approved['human_inputs']);
    }

    private function enabled(): void
    {
        if (! TechnicianConfig::scheduledApprovalsEnabled() || TechnicianConfig::killSwitchEngaged()) {
            throw new ScheduledUnavailable('kill_switch');
        }
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured()) {
            throw new InvalidArgumentException('integration_disabled');
        }
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    private function aliases(array $row): array
    {
        $values = [];
        foreach (['customerId', 'defaultDomainName', 'initialDomainName'] as $key) {
            if (is_string($row[$key] ?? null)) {
                $values[] = strtolower(trim($row[$key]));
            }
        }
        foreach (is_array($row['domains'] ?? null) ? $row['domains'] : [] as $domain) {
            if (is_string($domain)) {
                $values[] = strtolower(trim($domain));
            }
        }

        return array_unique($values);
    }
}
