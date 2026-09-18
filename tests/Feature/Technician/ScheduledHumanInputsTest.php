<?php

namespace Tests\Feature\Technician;

use App\Services\Technician\Scheduled\ApprovalEnvelope;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledOutbox;
use Illuminate\Support\Facades\DB;

/** The deliberately lossy evidence fixture must not erase human confirmation. */
class ScheduledHumanInputsTest extends ScheduledApprovalTest
{
    private function admitInputs(array $inputs): int
    {
        $lossy = new class($this->evidence) implements \App\Services\Technician\Scheduled\ScheduledEvidence
        {
            public function __construct(private \App\Services\Technician\Scheduled\ScheduledEvidence $base) {}

            public function approve(\App\Models\TechnicianRun $run, ?\App\Models\User $user, array $inputs): array
            {
                // Preserve the original #1780 deliberately lossy-provider control:
                // different confirmations produce exactly the same binding.
                return $this->base->approve($run, $user, []);
            }

            public function revalidate(\App\Models\TechnicianRun $run, ?\App\Models\User $user, array $approved): array
            {
                return $approved;
            }
        };

        return app(ScheduledAdmission::class)->admit($this->run->id, \App\Services\Technician\Scheduled\ScheduledApprover::human($this->user->id), $this->run->content_hash, null,
            '2026-09-15 01:00:00', '2026-09-15 02:00:00', 'UTC', $inputs, $lossy);
    }

    public function test_exact_human_inputs_are_encrypted_independently_of_lossy_provider(): void
    {
        $inputs = ['external_address' => 'first@synthetic.test', 'internal_message' => 'Alpha',
            'external_message' => 'Bravo', 'keep_copy' => false, 'nested' => ['value' => null]];
        $id = $this->admitInputs($inputs);
        $row = DB::table('scheduled_authorizations')->find($id);
        $sealed = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $this->assertSame(ApprovalEnvelope::canonical($inputs), ApprovalEnvelope::canonical($sealed['human_inputs']));
        $this->assertSame(['forward' => 'synthetic@example.test'], $sealed['binding']['payload']);
        $this->assertSame($id, $this->admitInputs(array_reverse($inputs, true)));
        foreach (['first@synthetic.test', 'Alpha', 'Bravo'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
            $this->assertStringNotContainsString($secret, json_encode(DB::table('scheduled_note_outbox')->get()));
        }
        foreach (DB::table('scheduled_note_outbox')->pluck('id') as $item) {
            $this->assertTrue(app(ScheduledOutbox::class)->deliver($item));
        }
        $this->assertStringNotContainsString('first@synthetic.test', json_encode(DB::table('ticket_notes')->get()));
    }

    public function test_each_changed_confirmation_refuses_even_when_provider_binding_is_identical(): void
    {
        $original = ['external_address' => 'first@synthetic.test', 'internal_message' => 'Alpha',
            'external_message' => 'Bravo', 'keep_copy' => false, 'nested' => ['value' => null]];
        $id = $this->admitInputs($original);
        $before = DB::table('scheduled_authorizations')->find($id);
        $changes = [
            array_replace($original, ['external_address' => 'other@synthetic.test']),
            array_replace($original, ['internal_message' => 'Omega']),
            array_replace($original, ['external_message' => 'Delta']),
            array_replace($original, ['keep_copy' => true]),
            array_replace($original, ['keep_copy' => 0]),
            array_replace($original, ['nested' => ['value' => '']]),
            array_diff_key($original, ['external_address' => true]),
            [...$original, 'new_input' => 'unexpected'],
        ];
        foreach ($changes as $changed) {
            try {
                $this->admitInputs($changed);
                $this->fail('Changed exact human confirmation was accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('existing_authorization_conflict', $e->getMessage());
            }
            $this->assertEquals($before, DB::table('scheduled_authorizations')->find($id));
            $this->assertDatabaseCount('scheduled_authorizations', 1);
            $this->assertDatabaseCount('scheduled_note_outbox', 1);
        }
    }

    public function test_preflight_cannot_swap_the_sealed_confirmation_behind_the_final_lock(): void
    {
        $id = $this->admitInputs(['internal_message' => 'Alpha']);
        // This control targets the later locked comparison, so first make the
        // copies agree; the separate #1885 tests cover refusal before preflight.
        $row = DB::table('scheduled_authorizations')->find($id);
        $values = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        $values['binding']['human_inputs'] = $values['human_inputs'];
        DB::table('scheduled_authorizations')->where('id', $id)->update(ApprovalEnvelope::seal($values));
        $this->time = $this->time->setTime(1, 0);
        $coordinator = app(\App\Services\Technician\Scheduled\ScheduledCoordinator::class);
        $nonce = $coordinator->claim($id);
        $evidence = new class($id) implements \App\Services\Technician\Scheduled\ScheduledEvidence
        {
            public function __construct(private int $id) {}

            public function approve(\App\Models\TechnicianRun $run, ?\App\Models\User $user, array $inputs): array
            {
                throw new \LogicException('Not an admission fixture');
            }

            public function revalidate(\App\Models\TechnicianRun $run, ?\App\Models\User $user, array $approved): array
            {
                $row = DB::table('scheduled_authorizations')->find($this->id);
                $values = ApprovalEnvelope::open($row->ciphertext, $row->digest);
                $values['human_inputs']['internal_message'] = 'Omega';
                DB::table('scheduled_authorizations')->where('id', $this->id)->update(ApprovalEnvelope::seal($values));

                return $approved;
            }
        };
        $this->assertFalse($coordinator->intent($id, $nonce, $evidence));
        $row = DB::table('scheduled_authorizations')->find($id);
        $this->assertSame('authorization_changed', $row->reason);
        $this->assertSame('blocked', $row->state);
        $this->assertNull($row->intent_at);
    }

    public function test_legacy_envelope_without_independent_inputs_cannot_be_reconfirmed(): void
    {
        $id = $this->admitInputs([]);
        $row = DB::table('scheduled_authorizations')->find($id);
        $values = ApprovalEnvelope::open($row->ciphertext, $row->digest);
        unset($values['human_inputs']);
        DB::table('scheduled_authorizations')->where('id', $id)->update(ApprovalEnvelope::seal($values));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('existing_authorization_conflict');
        $this->admitInputs([]);
    }
}
