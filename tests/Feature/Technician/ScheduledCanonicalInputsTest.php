<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\Scheduled\ApprovalEnvelope;
use App\Services\Technician\Scheduled\MailboxDispatch;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercise the actual coordinator/adapter/HTTP boundary, not a mocked intent. */
class ScheduledCanonicalInputsTest extends ScheduledMailboxTest
{
    public static function divergentCopies(): array
    {
        return [
            'same domain address' => [['external_smtp' => 'other@example.test']],
            'dropped field' => [[]],
            'missing copy' => [null],
            'wrong type' => ['approved@example.test'],
            'extra boolean' => [['external_smtp' => 'approved@example.test', 'keep_copy' => false]],
            'nested input' => [['external_smtp' => 'approved@example.test', 'extra' => ['value' => null]]],
        ];
    }

    #[DataProvider('divergentCopies')]
    public function test_independent_confirmation_divergence_refuses_before_dispatch(mixed $independent): void
    {
        $this->proposal('cipp_stage_set_mailbox_forwarding', ['mode' => 'external', 'keep_copy' => true, 'external_domain' => 'example.test']);
        $id = $this->admit(['external_smtp' => 'approved@example.test']);
        $row = DB::table('scheduled_authorizations')->find($id);
        $envelope = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        // Simulate a provider that produced a different copy at admission. The
        // envelope remains validly sealed; this is not a bad-MAC refusal test.
        $envelope['human_inputs'] = $independent;
        DB::table('scheduled_authorizations')->where('id', $id)->update(ApprovalEnvelope::seal($envelope));
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(0, $this->wire, 'Divergent confirmation must never reach a vendor write');
        $row = DB::table('scheduled_authorizations')->find($id);
        $this->assertSame('blocked', $row->state);
        $this->assertSame('preflight_refused', $row->reason);
        $this->assertNull($row->intent_at);
        $this->assertSame(1, (int) $row->attempt);
    }

    public function test_equal_copies_with_different_object_key_order_dispatch_exact_body_once(): void
    {
        $this->proposal('cipp_stage_set_mailbox_out_of_office', ['state' => 'Enabled', 'internal_length' => 5, 'external_length' => 5]);
        $id = $this->admit(['internal_message' => 'hello', 'external_message' => 'world']);
        $row = DB::table('scheduled_authorizations')->find($id);
        $envelope = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $envelope['human_inputs'] = array_reverse($envelope['human_inputs'], true);
        DB::table('scheduled_authorizations')->where('id', $id)->update(ApprovalEnvelope::seal($envelope));
        $this->time = $this->time->setTime(1, 0);
        app(MailboxDispatch::class)->run($id);
        app(MailboxDispatch::class)->run($id);
        $this->assertCount(1, $this->wire);
        $this->assertSame('uncertain', DB::table('scheduled_authorizations')->value('state'));
        $this->assertStringContainsString('hello', json_encode($this->wire[0]['body']));
        $this->assertStringContainsString('world', json_encode($this->wire[0]['body']));
    }
}
