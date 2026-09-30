<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\User;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Card mfmgS3XO: the far end of a phone call, and the "Previous calls" panel
 * that used to key on the wrong column for outbound calls.
 *
 * FIXTURE SHAPE IS THE POINT. Every row here is written by the production
 * writers, PhoneCallService::logIncomingCall() and logOutboundCall(), with a
 * synthetic DID configured. They are never hand-built. Production outbound rows carry
 * from_number = the dialled far end and to_number = our DID. DevDataSeeder and
 * CallerResolverTest use the opposite (legacy) layout, which production has
 * never written, and a control built on that layout would pass against data
 * production does not have. test_fixture_rows_have_the_production_layout pins
 * the layout, so a writer change that breaks it fails here first rather than
 * silently turning the other assertions vacuous.
 *
 * Every assertion runs on an OUTBOUND call and on an INBOUND call. The defect
 * read correctly on inbound and wrongly on outbound, so a control exercised on
 * one direction only would reproduce it.
 *
 * History is read from the view data (the ids the panel renders), not from the
 * HTML. calls.show also renders a client picker that lists EVERY active
 * client by name, so "other client's name absent from the page" would fail on
 * a correct panel and could never pass.
 *
 * All numbers and names are synthetic (555-01xx range); no client data.
 */
class CallHistoryFarEndTest extends TestCase
{
    use RefreshDatabase;

    private const DID = '+15550100000';

    private const FAR_A = '+15550101111';

    private const FAR_B = '+15550102222';

    private PhoneCallService $service;

    private int $uuid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // ResolveCallerFromPeople is dispatched by both writers
        config(['services.plivo.did_number' => self::DID]);
        $this->service = app(PhoneCallService::class);
    }

    private function inbound(string $caller, ?Client $client = null, int $minutesAgo = 0): PhoneCall
    {
        $call = $this->service->logIncomingCall([
            'CallUUID' => 'far-end-in-'.(++$this->uuid),
            'From' => $caller,
            'To' => self::DID,
        ]);

        return $this->settle($call, $client, $minutesAgo);
    }

    private function outbound(string $dialled, ?Client $client = null, int $minutesAgo = 0): PhoneCall
    {
        $call = $this->service->logOutboundCall([
            'CallUUID' => 'far-end-out-'.(++$this->uuid),
            'From' => 'sip:synthetic-tech@phone.plivo.com',
            'To' => $dialled,
        ]);

        return $this->settle($call, $client, $minutesAgo);
    }

    /** client_id is not fillable; set it directly, as ResolveCallerFromPeople does. */
    private function settle(PhoneCall $call, ?Client $client, int $minutesAgo): PhoneCall
    {
        $call->client_id = $client?->id;
        $call->started_at = now()->subMinutes($minutesAgo);
        $call->save();

        return $call->fresh();
    }

    /** @return array<int, int> ids the call page's "Previous calls" panel renders */
    private function historyIds(PhoneCall $call): array
    {
        $response = $this->actingAs(User::factory()->create())->get(route('calls.show', $call));
        $response->assertOk();

        return $response->viewData('callHistory')->pluck('id')->sort()->values()->all();
    }

    /** @return array<string, PhoneCall> */
    private function twoClientsBothDirections(): array
    {
        $a = Client::factory()->create(['name' => 'Synthetic Client A']);
        $b = Client::factory()->create(['name' => 'Synthetic Client B']);

        return [
            'a_in' => $this->inbound(self::FAR_A, $a, 50),
            'a_out' => $this->outbound(self::FAR_A, $a, 40),
            'b_in' => $this->inbound(self::FAR_B, $b, 30),
            'b_out' => $this->outbound(self::FAR_B, $b, 20),
        ];
    }

    public function test_fixture_rows_have_the_production_layout(): void
    {
        $c = $this->twoClientsBothDirections();

        // Outbound: far end in from_number, our DID in to_number - the layout
        // every production outbound row has, and the one the defect lived on.
        $this->assertSame(CallDirection::Outbound, $c['a_out']->direction);
        $this->assertSame(self::FAR_A, $c['a_out']->from_number);
        $this->assertSame(self::DID, $c['a_out']->to_number);

        $this->assertSame(CallDirection::Inbound, $c['a_in']->direction);
        $this->assertSame(self::FAR_A, $c['a_in']->from_number);
        $this->assertSame(self::DID, $c['a_in']->to_number);
    }

    public function test_outbound_call_history_lists_only_calls_with_the_same_far_end(): void
    {
        $c = $this->twoClientsBothDirections();

        // Before the fix this returned every other call that touched our DID:
        // b_in and b_out (the other client) as well as a_in.
        $this->assertSame([$c['a_in']->id], $this->historyIds($c['a_out']));
        $this->assertSame([$c['b_in']->id], $this->historyIds($c['b_out']));
    }

    public function test_inbound_call_history_lists_only_calls_with_the_same_far_end(): void
    {
        $c = $this->twoClientsBothDirections();

        $this->assertSame([$c['a_out']->id], $this->historyIds($c['a_in']));
        $this->assertSame([$c['b_out']->id], $this->historyIds($c['b_in']));
    }

    /**
     * The removed orWhere('to_number') arm. Production never stores a far end
     * in to_number, so the only rows that arm can match are calls INTO one of
     * our own lines. A call whose far end IS our DID (production has one such
     * row in each direction) would list every inbound call again.
     */
    public function test_history_never_matches_on_to_number_in_either_direction(): void
    {
        $c = $this->twoClientsBothDirections();

        $outToDid = $this->outbound(self::DID, null, 10);
        $inFromDid = $this->inbound(self::DID, null, 5);

        $this->assertSame([$inFromDid->id], $this->historyIds($outToDid));
        $this->assertSame([$outToDid->id], $this->historyIds($inFromDid));
        $this->assertNotContains($c['a_in']->id, $this->historyIds($outToDid));
    }

    /** Anonymous inbound (empty caller ID) must not match the other anonymous calls. */
    public function test_history_is_empty_when_there_is_no_far_end(): void
    {
        $this->twoClientsBothDirections();
        $anon1 = $this->inbound('', null, 15);
        $this->inbound('', null, 12);

        $this->assertSame([], $this->historyIds($anon1));
    }

    /**
     * The softphone JSON path (calls/latest) renders the same panel for an
     * unresolved ringing call. Exercised on both directions.
     */
    public function test_latest_json_call_history_uses_the_far_end_in_both_directions(): void
    {
        $c = $this->twoClientsBothDirections();
        $user = User::factory()->create();

        foreach (['outbound', 'inbound'] as $direction) {
            PhoneCall::query()->whereNotIn('id', collect($c)->pluck('id'))->delete();
            // The writers create the row as Ringing, started now, no client.
            $live = $direction === 'outbound'
                ? $this->service->logOutboundCall(['CallUUID' => 'far-end-live-out', 'From' => 'sip:synthetic-tech@phone.plivo.com', 'To' => self::FAR_A])
                : $this->service->logIncomingCall(['CallUUID' => 'far-end-live-in', 'From' => self::FAR_A, 'To' => self::DID]);

            $ids = collect($this->actingAs($user)->getJson(route('calls.latest'))->assertOk()->json('call.call_history'))
                ->pluck('id')->sort()->values()->all();

            $expected = collect([$c['a_in']->id, $c['a_out']->id])->sort()->values()->all();
            $this->assertSame($expected, $ids, "{$direction} live call: history must be exactly client A's two calls");
        }
    }

    public function test_far_end_number_and_provenance_on_both_directions(): void
    {
        $c = $this->twoClientsBothDirections();

        $this->assertSame(self::FAR_A, $c['a_out']->farEndNumber());
        $this->assertSame('dialled', $c['a_out']->farEndProvenance());

        $this->assertSame(self::FAR_A, $c['a_in']->farEndNumber());
        $this->assertSame('caller_id', $c['a_in']->farEndProvenance());

        $anon = $this->inbound('');
        $this->assertNull($anon->farEndNumber());
        $this->assertNull($anon->farEndProvenance());
    }
}
