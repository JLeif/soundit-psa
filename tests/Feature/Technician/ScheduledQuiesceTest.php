<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\Scheduled\ScheduledCoordinator;
use Illuminate\Support\Facades\DB;

class ScheduledQuiesceTest extends ScheduledApprovalTest
{
    public function test_quiesce_refuses_admission_and_live_sender_without_overwriting_terminal(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertTrue($c->intent($id, $nonce, $this->evidence));
        $this->assertTrue($c->beforeSend($id, $nonce));
        app(\App\Services\Technician\Scheduled\ScheduledQuiescence::class)->begin();
        $this->assertFalse($c->beforeSend($id, 'unrelated-nonce'));
        $this->assertSame('dispatch_intent', DB::table('scheduled_authorizations')->value('state'));
        $this->assertFalse($c->beforeSend($id, $nonce));
        $this->assertSame('abandoned_no_send', DB::table('scheduled_authorizations')->value('state'));
        $this->assertFalse($c->beforeSend($id, $nonce));
        $this->assertDatabaseCount('scheduled_note_outbox', 2);
        $this->expectExceptionMessage('scheduling_quiesced');
        $this->admit();
    }

    public function test_late_receipt_appends_evidence_without_reversal_or_fence_release(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertTrue($c->intent($id, $nonce, $this->evidence));
        $this->time = $this->time->addSeconds(640);
        $c->recover($id);
        $before = DB::table('scheduled_authorizations')->find($id);
        $this->assertSame('uncertain', $before->state);
        $this->assertFalse($c->settle($id, $nonce, 'submitted'));
        $c->lateReceipt($id, $nonce, 'tactical', 'synthetic-operation', 'submitted');
        $this->assertEquals($before, DB::table('scheduled_authorizations')->find($id));
        $this->assertDatabaseHas('scheduled_late_receipts', ['authorization_id' => $id, 'nonce' => $nonce, 'vendor_id' => 'synthetic-operation']);
        $this->assertDatabaseHas('scheduled_target_fences', ['authorization_id' => $id]);
        $c->lateReceipt($id, $nonce, 'tactical', 'second-observation', 'submitted');
        $this->assertDatabaseCount('scheduled_late_receipts', 2);
    }

    public function test_drain_preserves_both_wait_and_grace_and_never_dispatches(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertTrue($c->intent($id, $nonce, $this->evidence));
        $this->artisan('technician:scheduled-drain')->expectsOutput('{"status":"quiesced_wait","wait_seconds":610}')->assertExitCode(1);
        $this->assertDatabaseHas('scheduled_note_outbox', ['note_id' => null]);
        $at = app(\App\Services\Technician\Scheduled\ScheduledQuiescence::class)->at();
        $this->time = $this->time->addSeconds(610);
        $this->artisan('technician:scheduled-drain')->assertExitCode(1);
        $this->assertSame('dispatch_intent', DB::table('scheduled_authorizations')->value('state'));
        $this->time = $this->time->addSeconds(30);
        $this->artisan('technician:scheduled-drain')->assertExitCode(0);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertEquals($at, app(\App\Services\Technician\Scheduled\ScheduledQuiescence::class)->at());
        $this->assertDatabaseHas('scheduled_target_fences', ['authorization_id' => $id]);
    }

    public function test_sweep_and_drain_share_the_same_overlap_lock(): void
    {
        app(\App\Services\Technician\Scheduled\ScheduledQuiescence::class)->begin();
        $this->time = $this->time->addSeconds(640);
        $lock = \Illuminate\Support\Facades\Cache::lock(\App\Services\Technician\Scheduled\ScheduledPolicy::OVERLAP_LOCK);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(1, app(\App\Services\Technician\Scheduled\ScheduledSweep::class)->run()['errors']);
            $this->artisan('technician:scheduled-drain')->expectsOutput('{"status":"overlap_busy"}')->assertExitCode(1);
        } finally {
            $lock->release();
        }
    }

    public function test_receipt_at_400_seconds_is_not_lost_to_recovery(): void
    {
        $id = $this->admit();
        $this->time = $this->time->setTime(1, 0);
        $c = app(ScheduledCoordinator::class);
        $nonce = $c->claim($id);
        $this->assertTrue($c->intent($id, $nonce, $this->evidence));
        $this->time = $this->time->addSeconds(400);
        $c->recover($id);
        $this->assertTrue($c->settle($id, $nonce, 'submitted'));
        $this->assertSame('submitted', DB::table('scheduled_authorizations')->where('id', $id)->value('state'));
    }
}
