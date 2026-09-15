<?php

namespace App\Services\Cipp\Offboarding;

/** Parser pinned to CIPP-API c04bde0f. Reports are never independent effect verification. */
final class OffboardingProgress
{
    public const TITLES = [
        'revoke_sessions' => 'Revoke all sessions',
        'disable_sign_in' => 'Disable sign in',
        'hide_from_gal' => 'Hide from Global Address List',
        'forward_to_successor' => 'Forward email',
        'disable_forwarding' => 'Disable email forwarding',
        'grant_onedrive_access' => 'Grant OneDrive full access',
        'grant_mailbox_full_access_no_automap' => 'Grant full access (no automap)',
        'grant_mailbox_full_access_automap' => 'Grant full access (automap)',
        'grant_mailbox_send_as' => 'Grant Send As access',
        'grant_mailbox_send_on_behalf' => 'Grant Send on Behalf access',
        'convert_to_shared' => 'Convert to shared mailbox',
    ];

    public static function unknown(string $reason): array
    {
        return ['evidence' => $reason, 'execution' => 'unknown', 'verification' => 'unverified',
            'task_persisted' => false, 'steps' => [], 'needs_operator_review' => true];
    }

    /** A complete tenant-filtered scheduler lookup; foreign references are NOT adopted. */
    public static function task(array $rows, array $snapshot, ?string $taskId, ?string $deploymentId): array
    {
        $own = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return self::unknown('invalid_scheduler_rows');
            }
            if (($row['Reference'] ?? null) === $snapshot['body']['reference'] || $taskId !== null) {
                $own[] = $row;
            }
        }
        if (count($own) !== 1) {
            return self::unknown(count($own) ? 'conflicting_tasks' : 'no_task_evidence');
        }
        $row = $own[0];
        $p = $row['Parameters'] ?? null;
        $options = $snapshot['body'];
        unset($options['user'], $options['tenantFilter']);
        $tenant = $snapshot['body']['tenantFilter'];
        if (($row['Reference'] ?? null) !== $snapshot['body']['reference']
            || ($row['Command'] ?? null) !== 'Invoke-CIPPOffboardingJob'
            || ($row['Name'] ?? null) !== 'Offboarding: '.$snapshot['target_upn']
            || ($row['Tenant']['type'] ?? null) !== 'Tenant'
            || ! in_array(strtolower((string) ($row['Tenant']['value'] ?? '')), [$tenant, $snapshot['namespace'][2]], true)
            || ! self::uuid($row['RowKey'] ?? null) || ($taskId !== null && $row['RowKey'] !== $taskId)
            || ! is_array($p) || ($p['Username'] ?? null) !== $snapshot['target_upn']
            || ($p['APIName'] ?? null) !== 'Scheduled Offboarding' || ($p['RunScheduled'] ?? null) !== true
            || array_diff(array_keys($p), ['Username', 'APIName', 'options', 'RunScheduled', 'DeploymentId'])
            || ! is_array($p['options'] ?? null) || OffboardingPlan::canonical($p['options']) !== OffboardingPlan::canonical($options)
            || ! in_array($row['Recurrence'] ?? null, ['Once', 0, '0'], true)
            || ! in_array($row['PostExecution'] ?? null, [null, ''], true)
            || ! in_array($row['PsaTicketId'] ?? null, [null, ''], true)) {
            return self::unknown('task_binding_conflict');
        }
        $foundDeployment = $p['DeploymentId'] ?? null;
        if ($foundDeployment === '') {
            $foundDeployment = null;
        }
        if (($foundDeployment !== null && ! self::uuid($foundDeployment))
            || ($deploymentId !== null && $foundDeployment !== $deploymentId)) {
            return self::unknown('deployment_binding_conflict');
        }
        $state = $row['TaskState'] ?? null;
        if (! in_array($state, ['Planned', 'Running', 'Completed', 'Failed'], true)) {
            return self::unknown('unknown_scheduler_state');
        }

        return [...self::unknown('task_bound'), 'task_persisted' => true, 'task_id' => $row['RowKey'],
            'deployment_id' => $foundDeployment, 'scheduler_state' => $state,
            'execution' => match ($state) {
                'Planned' => 'queued', 'Running' => 'running', default => 'terminal_reported'
            }];
    }

    public static function parse(array $rows, array $snapshot, string $taskId): array
    {
        if (count($rows) !== 1 || ! isset($rows[0]) || ! is_array($rows[0])) {
            return self::unknown('missing_or_multiple_progress_rows');
        }
        $row = $rows[0];
        if (($row['Name'] ?? null) !== $snapshot['target_upn'] || ($row['Source'] ?? null) !== 'Offboarding'
            || ($row['TenantFilter'] ?? null) !== $snapshot['body']['tenantFilter']
            || ($row['TaskId'] ?? null) !== $taskId) {
            return self::unknown('progress_binding_conflict');
        }
        $expected = array_intersect_key(self::TITLES, array_flip($snapshot['input']['actions']));
        $steps = $row['Steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps) || count($steps) !== count($expected)) {
            return self::unknown('incomplete_step_manifest');
        }
        $result = [];
        foreach (array_keys($expected) as $index => $action) {
            $step = $steps[$index];
            if (! is_array($step) || ($step['Title'] ?? null) !== $expected[$action]
                || ! in_array($step['Kind'] ?? null, [null, ''], true)
                || ! in_array($step['Status'] ?? null, ['pending', 'running', 'succeeded', 'failed'], true)
                || ! is_string($step['Message'] ?? null)) {
                return self::unknown('step_contract_conflict');
            }
            // Never expose raw vendor text: it may contain another user's address or tokens.
            $status = $step['Status'];
            if (preg_match('/^\s*(error|failed)\b/i', $step['Message'])) {
                $status = 'failed';
            }
            $result[] = ['action' => $action, 'reported_status' => $status, 'verification' => 'unverified',
                'message_digest' => hash('sha256', $step['Message'])];
        }
        $overall = $row['Status'] ?? null;
        if (! in_array($overall, ['queued', 'running', 'succeeded', 'failed'], true)) {
            return self::unknown('unknown_progress_state');
        }
        $statuses = array_column($result, 'reported_status');
        $execution = ($overall === 'failed' || in_array('failed', $statuses, true)) ? 'reported_failed'
            : (($overall === 'succeeded' && count(array_unique($statuses)) === 1 && $statuses[0] === 'succeeded')
                ? 'reported_succeeded' : 'partial_or_incomplete');

        return ['evidence' => 'progress_bound', 'execution' => $execution, 'verification' => 'unverified',
            'steps' => $result, 'needs_operator_review' => true];
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value);
    }
}
