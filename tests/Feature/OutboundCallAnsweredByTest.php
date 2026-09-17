<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * logOutboundCall() resolves the placing user from the SIP endpoint and hands
 * 'answered_by' to PhoneCall::updateOrCreate(...) — a MASS-ASSIGNMENT path.
 * Before this guard existed, `answered_by` was absent from PhoneCall::$fillable,
 * so Eloquent silently discarded it and every outbound call was stored with a
 * null answered_by. The inbound path (handleCallAnswered) assigns the property
 * directly, which is not mass assignment, which is why only outbound was blind.
 *
 * These tests assert the STORED row, not the in-memory object: the drop happens
 * inside fill(), so a fresh read from the database is the only honest witness.
 */
class OutboundCallAnsweredByTest extends TestCase
{
    use RefreshDatabase;

    private function endpointFor(User $user, string $sipUri): SipEndpoint
    {
        return SipEndpoint::create([
            'sip_uri' => $sipUri,
            'sip_username' => 'testendpoint',
            'sip_password' => 'irrelevant',
            'user_id' => $user->id,
            'label' => 'Test endpoint',
            'is_active' => true,
        ]);
    }

    public function test_outbound_call_persists_the_placing_user_as_answered_by(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tester@phone.plivo.com');

        app(PhoneCallService::class)->logOutboundCall([
            'CallUUID' => 'outbound-answered-by-1',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550123',
        ]);

        $stored = PhoneCall::where('call_uuid', 'outbound-answered-by-1')->firstOrFail();

        $this->assertSame(
            $user->id,
            $stored->answered_by,
            'logOutboundCall() must persist answered_by; a non-fillable key is dropped silently by mass assignment.'
        );
        $this->assertSame(CallDirection::Outbound, $stored->direction);
    }

    public function test_answered_by_survives_a_repeat_webhook_for_the_same_call_uuid(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->endpointFor($user, 'sip:tester@phone.plivo.com');
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'outbound-answered-by-2',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550124',
        ];

        $service->logOutboundCall($payload);
        // Plivo re-delivers webhooks; updateOrCreate takes the update branch the
        // second time, which is the other half of the mass-assignment surface.
        $service->logOutboundCall($payload);

        $stored = PhoneCall::where('call_uuid', 'outbound-answered-by-2')->firstOrFail();

        $this->assertSame($user->id, $stored->answered_by);
        $this->assertSame(1, PhoneCall::where('call_uuid', 'outbound-answered-by-2')->count());
    }

    public function test_answered_by_is_mass_assignable_on_the_model_itself(): void
    {
        $user = User::factory()->create();

        $call = PhoneCall::create([
            'call_uuid' => 'mass-assign-answered-by',
            'direction' => CallDirection::Outbound,
            'from_number' => '+15555550125',
            'answered_by' => $user->id,
            'started_at' => now(),
        ]);

        $this->assertSame(
            $user->id,
            $call->fresh()->answered_by,
            'answered_by must appear in PhoneCall::$fillable — the outbound writer sets it through updateOrCreate().'
        );
    }

    public function test_outbound_call_with_no_matching_endpoint_stores_a_null_answered_by(): void
    {
        Queue::fake();

        app(PhoneCallService::class)->logOutboundCall([
            'CallUUID' => 'outbound-answered-by-3',
            'From' => 'sip:nobody@phone.plivo.com',
            'To' => '+15555550126',
        ]);

        $stored = PhoneCall::where('call_uuid', 'outbound-answered-by-3')->firstOrFail();

        // Honest null, not an invented attribution: no endpoint resolved, so no user.
        $this->assertNull($stored->answered_by);
    }
}
