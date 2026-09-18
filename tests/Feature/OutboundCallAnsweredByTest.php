<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Models\SipEndpoint;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * logOutboundCall() resolves the placing user from the SIP endpoint and stores
 * it as answered_by. The original defect was mass assignment: the value was
 * handed to PhoneCall::updateOrCreate(...) while `answered_by` was absent from
 * PhoneCall::$fillable, so Eloquent silently discarded it and every outbound
 * call was stored unattributed. The inbound path (handleCallAnswered) assigns
 * the property directly, which is why only outbound was blind.
 *
 * The fix landed in two parts and the second changed the mechanism: the column
 * is now assigned directly by logOutboundCall() as well, OUTSIDE the
 * updateOrCreate values array, because that array is applied on the UPDATE
 * branch and a redelivered webhook with an unresolved endpoint would null an
 * attribution already resolved. So no production writer mass-assigns this
 * column today; $fillable is still exercised by DevDataSeeder and fixtures
 * (see test_answered_by_is_mass_assignable_on_the_model_itself).
 *
 * These tests assert the STORED row, not the in-memory object: a silent drop
 * happens inside fill(), so a fresh read from the database is the only honest
 * witness.
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
            'logOutboundCall() must persist answered_by. The original defect was a non-fillable key dropped silently by mass assignment; at this tip the service assigns the property directly, so a failure here means that write is broken, not $fillable.'
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
        // second time. answered_by is no longer in that values array, so this
        // pins that the direct assignment below it is idempotent when the
        // endpoint resolves to the same user on both deliveries.
        $service->logOutboundCall($payload);

        $stored = PhoneCall::where('call_uuid', 'outbound-answered-by-2')->firstOrFail();

        $this->assertSame($user->id, $stored->answered_by);
        $this->assertSame(1, PhoneCall::where('call_uuid', 'outbound-answered-by-2')->count());
    }

    /**
     * The earlier repeat-webhook test resolved the endpoint on BOTH deliveries, so it
     * could not see the destructive case. Plivo re-delivers webhooks; if the endpoint no
     * longer resolves on the later delivery, the attribution already resolved must
     * survive.
     *
     * That is all this pins: a delivery that produces NO user never clears. It is NOT
     * "fills in, never clears" - a delivery that produces a DIFFERENT non-null user does
     * replace the stored one (see the service comment; the precedence question is issue
     * #2166). No test covers that case yet.
     *
     * "Produces no user", not "unresolved": the service guards on $endpoint?->user_id,
     * and sip_endpoints.user_id is nullable, so an endpoint that resolves without a user
     * takes the same branch. This test drives the is_active=false form of that branch
     * only; the null-user_id form is unexercised.
     */
    public function test_a_repeat_webhook_with_an_unresolved_endpoint_keeps_the_resolved_answered_by(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $endpoint = $this->endpointFor($user, 'sip:tester@phone.plivo.com');
        $service = app(PhoneCallService::class);

        $payload = [
            'CallUUID' => 'outbound-answered-by-unresolved-redelivery',
            'From' => 'sip:tester@phone.plivo.com',
            'To' => '+15555550199',
        ];

        $service->logOutboundCall($payload);
        $this->assertSame($user->id, PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->answered_by);

        // The endpoint is deactivated (or simply fails to match) before redelivery.
        $endpoint->update(['is_active' => false]);
        $service->logOutboundCall($payload);

        $this->assertSame($user->id, PhoneCall::where('call_uuid', $payload['CallUUID'])->firstOrFail()->answered_by,
            'a redelivered webhook must never null an attribution that was already resolved');
    }

    /**
     * The inbound path resolves attribution from DialBLegTo. A later outbound webhook for
     * the same call_uuid whose endpoint does not resolve must not undo that either.
     */
    public function test_an_unresolved_outbound_webhook_does_not_clear_an_existing_attribution(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        PhoneCall::create([
            'call_uuid' => 'outbound-answered-by-preexisting',
            'direction' => CallDirection::Outbound,
            'from_number' => '+15555550125',
            'to_number' => '+15555550126',
            'answered_by' => $user->id,
            'status' => CallStatus::Ringing,
            'started_at' => now(),
        ]);

        // No SipEndpoint exists for this URI, so the lookup resolves nothing.
        app(PhoneCallService::class)->logOutboundCall([
            'CallUUID' => 'outbound-answered-by-preexisting',
            'From' => 'sip:nobody@phone.plivo.com',
            'To' => '+15555550126',
        ]);

        $this->assertSame($user->id, PhoneCall::where('call_uuid', 'outbound-answered-by-preexisting')->firstOrFail()->answered_by,
            'an unresolved endpoint leaves the existing attribution alone');
    }

    /**
     * $fillable is no longer load-bearing for a production webhook writer (both
     * now assign the property directly), but DevDataSeeder mass-assigns
     * answered_by, as do fixtures here and in CallLogIndexDisplayTest. This pins
     * that surface deliberately rather than by accident.
     */
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
            'answered_by must stay in PhoneCall::$fillable — DevDataSeeder and test fixtures mass-assign it.'
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
