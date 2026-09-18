<?php

namespace Tests\Feature;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Call Log index display guards (issue #2078):
 *  - the answered-by fallback must not imply a person ("Staff" did);
 *  - the caller's name must be a real link to their person page;
 *  - every row carries a dedicated Open control, and the phone number is
 *    plain "To" text rather than the only way into the call detail.
 */
class CallLogIndexDisplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * person_id / client_id are deliberately NOT in PhoneCall::$fillable (no
     * writer in app/ mass-assigns them — ResolveCallerFromPeople sets the
     * properties directly), so this helper does the same rather than widening
     * the model's mass-assignment surface for a test's convenience.
     */
    private function outboundCall(array $attrs = []): PhoneCall
    {
        $direct = array_intersect_key($attrs, array_flip(['person_id', 'client_id']));

        $call = new PhoneCall(array_merge([
            'call_uuid' => uniqid('display_', true),
            'direction' => CallDirection::Outbound,
            'from_number' => '+15555550111',
            'status' => CallStatus::Completed,
            'started_at' => now(),
        ], array_diff_key($attrs, $direct)));

        foreach ($direct as $column => $value) {
            $call->{$column} = $value;
        }

        $call->save();

        return $call;
    }

    public function test_unattributed_call_reads_unassigned_not_staff(): void
    {
        $this->outboundCall();

        $response = $this->actingAs(User::factory()->create())->get('/calls');

        $response->assertOk();
        // "Staff" implied a person handled the call when answered_by is simply
        // unknown. Historic rows stay null (no backfill was authorized), so the
        // fallback has to be honest about the absence. Asserted against the exact
        // old markup — a bare ">Staff<" also matches the sidebar's Staff nav item.
        $response->assertDontSee('<span class="text-muted">Staff</span>', false);
        $response->assertSee('Unassigned');
    }

    public function test_attributed_call_shows_the_staff_member_name(): void
    {
        $user = User::factory()->create(['name' => 'Dana Operator']);
        $this->outboundCall(['answered_by' => $user->id]);

        $response = $this->actingAs(User::factory()->create())->get('/calls');

        $response->assertOk();
        $response->assertSee('Dana Operator');
        $response->assertDontSee('Unassigned');
    }

    public function test_caller_name_links_to_the_person_page(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'first_name' => 'Ada',
            'last_name' => 'Caller',
            'is_active' => true,
        ]);
        $this->outboundCall(['person_id' => $person->id, 'client_id' => $client->id]);

        $response = $this->actingAs(User::factory()->create())->get('/calls');

        $response->assertOk();
        $response->assertSee(route('people.show', $person), false);
        $response->assertSee('Ada Caller');
    }

    public function test_every_row_has_a_dedicated_open_control_and_a_plain_number(): void
    {
        $call = $this->outboundCall();

        $response = $this->actingAs(User::factory()->create())->get('/calls');

        $response->assertOk();
        $html = $response->getContent();
        $showUrl = route('calls.show', $call);

        // The dedicated row action, in a fixed position (last cell), is present…
        $this->assertStringContainsString(
            '<a href="'.$showUrl.'" class="btn btn-sm btn-outline-secondary"',
            $html,
            'Each row needs a dedicated Open button linking to the call detail.'
        );
        // …and the phone number is no longer itself a link into the detail view.
        $this->assertStringNotContainsString(
            '<a href="'.$showUrl.'">',
            $html,
            'The phone number must render as plain "To" text, not as the row link.'
        );
    }
}
