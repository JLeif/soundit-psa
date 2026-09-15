<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\Offboarding\OffboardingProgress;
use PHPUnit\Framework\TestCase;

class OffboardingProgressTest extends TestCase
{
    public static function snapshot(): array
    {
        return ['namespace' => ['installation', 'integration', '33333333-3333-4333-8333-333333333333'],
            'target_upn' => 'leaver@example.test', 'input' => ['actions' => ['revoke_sessions', 'disable_sign_in']],
            'body' => ['tenantFilter' => 'example.test', 'user' => [['value' => 'leaver@example.test']], 'reference' => 'synthetic-reference', 'RevokeSessions' => true, 'DisableSignIn' => true]];
    }

    public static function task(): array
    {
        return ['Reference' => 'synthetic-reference', 'Command' => 'Invoke-CIPPOffboardingJob', 'Name' => 'Offboarding: leaver@example.test',
            'Tenant' => ['type' => 'Tenant', 'value' => 'example.test'], 'RowKey' => '44444444-4444-4444-8444-444444444444',
            'Parameters' => ['Username' => 'leaver@example.test', 'APIName' => 'Scheduled Offboarding', 'RunScheduled' => true,
                'options' => ['reference' => 'synthetic-reference', 'RevokeSessions' => true, 'DisableSignIn' => true],
                'DeploymentId' => '55555555-5555-4555-8555-555555555555'], 'Recurrence' => 'Once', 'TaskState' => 'Completed'];
    }

    public static function progress(): array
    {
        return ['Name' => 'leaver@example.test', 'Source' => 'Offboarding', 'TenantFilter' => 'example.test',
            'TaskId' => '44444444-4444-4444-8444-444444444444', 'Status' => 'succeeded', 'Steps' => [
                ['Title' => 'Revoke all sessions', 'Status' => 'succeeded', 'Message' => 'synthetic success'],
                ['Title' => 'Disable sign in', 'Status' => 'succeeded', 'Message' => 'synthetic success'],
            ]];
    }

    public function test_completed_is_only_scheduler_evidence_and_missing_progress_is_unknown(): void
    {
        $result = OffboardingProgress::task([self::task()], self::snapshot(), null, null);
        $this->assertTrue($result['task_persisted']);
        $this->assertSame('terminal_reported', $result['execution']);
        $this->assertSame('unverified', $result['verification']);
        $this->assertSame('unknown', OffboardingProgress::parse([], self::snapshot(), self::task()['RowKey'])['execution']);
    }

    public function test_exact_manifest_reports_success_but_never_verified_effects(): void
    {
        $result = OffboardingProgress::parse([self::progress()], self::snapshot(), self::task()['RowKey']);
        $this->assertSame('reported_succeeded', $result['execution']);
        $this->assertSame('unverified', $result['verification']);
        $this->assertSame(['revoke_sessions', 'disable_sign_in'], array_column($result['steps'], 'action'));
        $this->assertSame(['unverified', 'unverified'], array_column($result['steps'], 'verification'));
        $this->assertStringNotContainsString('synthetic success', json_encode($result));
    }

    public function test_overall_success_cannot_hide_failed_pending_missing_duplicate_or_foreign_steps(): void
    {
        foreach (['failed', 'pending', 'running', 'skipped'] as $status) {
            $row = self::progress();
            $row['Steps'][1]['Status'] = $status;
            $result = OffboardingProgress::parse([$row], self::snapshot(), self::task()['RowKey']);
            $this->assertNotSame('reported_succeeded', $result['execution']);
            $this->assertSame('unverified', $result['verification']);
        }
        $row = self::progress();
        $row['Steps'][1]['Message'] = 'Error hidden behind overall success';
        $this->assertSame('reported_failed', OffboardingProgress::parse([$row], self::snapshot(), self::task()['RowKey'])['execution']);
        foreach (['missing', 'extra', 'duplicate', 'notify', 'unknown_status', 'wrong_name', 'wrong_tenant', 'wrong_task', 'wrong_source', 'multiple', 'message_type'] as $case) {
            $row = self::progress();
            match ($case) {
                'missing' => array_pop($row['Steps']),
                'extra' => $row['Steps'][] = ['Title' => 'Notify via PSA', 'Status' => 'succeeded', 'Message' => 'Unexpected work'],
                'duplicate' => $row['Steps'][1] = $row['Steps'][0],
                'notify' => $row['Steps'][1]['Kind'] = 'notify',
                'unknown_status' => $row['Steps'][1]['Status'] = 'done',
                'wrong_name' => $row['Name'] = 'foreign@example.test',
                'wrong_tenant' => $row['TenantFilter'] = 'foreign.test',
                'wrong_task' => $row['TaskId'] = 'other',
                'wrong_source' => $row['Source'] = 'Other',
                'message_type' => $row['Steps'][1]['Message'] = [],
                default => null,
            };
            $result = OffboardingProgress::parse($case === 'multiple' ? [$row, $row] : [$row], self::snapshot(), self::task()['RowKey']);
            $this->assertSame('unknown', $result['execution'], $case);
            $this->assertSame([], $result['steps'], $case);
            $this->assertStringNotContainsString('foreign', json_encode($result), $case);
        }
    }

    public function test_task_binding_rejects_foreign_missing_multiple_or_changed_payload(): void
    {
        foreach (['none', 'multiple', 'reference', 'tenant', 'command', 'target', 'options', 'extra', 'deployment', 'task_id', 'recurrence', 'notification', 'state'] as $case) {
            $row = self::task();
            match ($case) {
                'reference' => $row['Reference'] = 'foreign',
                'tenant' => $row['Tenant']['value'] = 'foreign.test',
                'command' => $row['Command'] = 'Other',
                'target' => $row['Parameters']['Username'] = 'foreign@example.test',
                'options' => $row['Parameters']['options']['RemoveLicenses'] = false,
                'extra' => $row['Parameters']['StepIndexes'] = [0],
                'deployment' => $row['Parameters']['DeploymentId'] = 'different',
                'task_id' => $row['RowKey'] = 'different',
                'recurrence' => $row['Recurrence'] = 'Daily',
                'notification' => $row['PostExecution'] = 'PSA',
                'state' => $row['TaskState'] = 'Unknown',
                default => null,
            };
            $rows = match ($case) {
                'none' => [], 'multiple' => [$row, $row], default => [$row]
            };
            $result = OffboardingProgress::task($rows, self::snapshot(), self::task()['RowKey'], self::task()['Parameters']['DeploymentId']);
            $this->assertFalse($result['task_persisted'], $case);
            $this->assertSame('unknown', $result['execution'], $case);
        }
    }

    public function test_missing_deployment_keeps_task_evidence_without_manufacturing_progress(): void
    {
        $row = self::task();
        $row['Parameters']['DeploymentId'] = null;
        $result = OffboardingProgress::task([$row], self::snapshot(), null, null);
        $this->assertTrue($result['task_persisted']);
        $this->assertNull($result['deployment_id']);
    }
}
