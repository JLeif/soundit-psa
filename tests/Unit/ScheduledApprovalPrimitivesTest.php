<?php

namespace Tests\Unit;

use App\Services\Technician\Scheduled\ActionRegistry;
use App\Services\Technician\Scheduled\ApprovalEnvelope;
use App\Services\Technician\Scheduled\ApprovalWindow;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduledApprovalPrimitivesTest extends TestCase
{
    public function test_exact_allowlist_has_only_five_mailbox_adapters(): void
    {
        $this->assertCount(30, ActionRegistry::ACTIONS);
        foreach (ActionRegistry::ACTIONS as $action => $tool) {
            $this->assertSame($tool, ActionRegistry::directTool($action));
            $this->assertSame(in_array($action, ['cipp_stage_convert_mailbox', 'cipp_stage_set_mailbox_forwarding', 'cipp_stage_set_mailbox_gal_visibility', 'cipp_stage_set_mailbox_out_of_office', 'cipp_stage_set_mailbox_delegate', 'tactical_stage_command', 'tactical_stage_reboot', 'tactical_stage_shutdown', 'tactical_stage_recover_mesh', 'tactical_stage_maintenance', 'tactical_stage_start_service', 'tactical_stage_stop_service', 'tactical_stage_restart_service'], true), ActionRegistry::adapterAvailable($action));
        }
        foreach (['cipp_stage_reset_user_password', 'cipp_stage_create_user', 'cipp_stage_wipe_device', 'cipp_stage_offboard_user', 'tactical_stage_open_remote_control', 'cipp_stage_future'] as $action) {
            $this->assertNull(ActionRegistry::directTool($action));
            $this->assertFalse(ActionRegistry::adapterAvailable($action));
        }
    }

    public function test_utc_window_is_bounded_and_preserves_instants(): void
    {
        $w = ApprovalWindow::fromLocal('2026-09-16 01:00:00', '2026-09-16 02:00:00', 'America/Los_Angeles', new DateTimeImmutable('2026-09-15T00:00:00Z'));
        $this->assertSame('2026-09-16T08:00:00+00:00', $w->start->format('c'));
        $this->assertSame(-25200, $w->startOffset);
    }

    public function test_gaps_folds_and_invalid_windows_are_rejected(): void
    {
        $cases = [
            ['2026-03-08 02:30:00', '2026-03-08 04:00:00', 'America/Los_Angeles', '2026-03-07'],
            ['2026-11-01 01:30:00', '2026-11-01 03:00:00', 'America/Los_Angeles', '2026-10-31'],
            ['2026-04-05 01:45:00', '2026-04-05 03:00:00', 'Australia/Lord_Howe', '2026-04-04'],
            ['2026-10-04 02:15:00', '2026-10-04 04:00:00', 'Australia/Lord_Howe', '2026-10-03'],
            ['2026-09-16 00:00:00', '2026-09-17 00:00:01', 'UTC', '2026-09-15'],
            ['2026-09-23 00:00:01', '2026-09-23 01:00:00', 'UTC', '2026-09-15'],
            ['2026-09-15 00:00:00', '2026-09-16 00:00:00', 'UTC', '2026-09-15'],
            ['2026-09-16 00:00:00', '2026-09-16 00:00:00', 'UTC', '2026-09-15'],
            ['2026-09-16 00:00:00', '2026-09-16 01:00:00', 'Bogus/Zone', '2026-09-15'],
        ];
        foreach ($cases as [$a, $b, $zone, $now]) {
            try {
                ApprovalWindow::fromLocal($a, $b, $zone, new DateTimeImmutable($now.'T00:00:00Z'));
                $this->fail('accepted invalid window '.$a.' '.$zone);
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_envelope_binds_full_same_length_inputs_and_rejects_tampering(): void
    {
        $values = ['schema_version' => 1, 'message' => 'alpha', 'address' => 'one@example.test'];
        $sealed = ApprovalEnvelope::seal($values);
        $this->assertSame($values, array_replace($values, ApprovalEnvelope::open($sealed['ciphertext'], $sealed['digest'])));
        $other = ApprovalEnvelope::seal([...$values, 'message' => 'bravo', 'address' => 'two@example.test']);
        $this->assertNotSame($sealed['digest'], $other['digest']);
        $this->assertStringNotContainsString('alpha', $sealed['ciphertext']);
        $this->expectException(InvalidArgumentException::class);
        ApprovalEnvelope::open($other['ciphertext'], $sealed['digest']);
    }
}
