<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CallNumberMeaningDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const FAR = '+15550101111';

    private const OUR = '+15550100000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.plivo.did_number' => self::OUR]);
        $this->actingAs(User::factory()->create());
    }

    private function makeCall(bool $outbound, string $far = self::FAR): PhoneCall
    {
        $service = app(PhoneCallService::class);
        $call = $outbound
            ? $service->logOutboundCall(['CallUUID' => 'synthetic-out', 'From' => 'sip:synthetic@example.test', 'To' => $far])
            : $service->logIncomingCall(['CallUUID' => 'synthetic-in', 'From' => $far, 'To' => self::OUR]);
        $this->assertSame($far, $call->from_number);
        $this->assertSame(self::OUR, $call->to_number);

        return $call;
    }

    private function table(PhoneCall $call): string
    {
        $html = $this->get(route('calls.show', $call))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/Call Information.*?(<table.*?<\/table>)/s', $html, $matches));

        return $matches[1];
    }

    private function row(string $table, string $label): string
    {
        $this->assertSame(1, preg_match('/<th[^>]*>'.preg_quote($label, '/').'<\/th>\s*<td>(.*?)<\/td>/s', $table, $matches), 'Missing row: '.$label);

        return $matches[1];
    }

    private function assertMapping(bool $outbound, string $hint): void
    {
        $table = $this->table($this->makeCall($outbound));
        $other = $this->row($table, 'Other party');
        $ours = $this->row($table, 'Our line');
        $this->assertStringContainsString(PhoneNumber::format(self::FAR), $other);
        $this->assertStringContainsString($hint, $other);
        $this->assertStringContainsString('data-phone="'.self::FAR.'"', $other);
        $this->assertStringNotContainsString(self::OUR, $other);
        $this->assertStringContainsString(self::OUR, $ours);
        $this->assertStringNotContainsString(PhoneNumber::format(self::FAR), $ours);
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>\s*(From|To)\s*<\/th>/', $table);
        $this->assertStringContainsString($outbound ? 'Outbound' : 'Inbound', $this->row($table, 'Direction'));
    }

    public function test_outbound_call_page_maps_numbers_and_dialled_hint(): void
    {
        $this->assertMapping(true, 'dialled');
    }

    public function test_inbound_call_page_maps_numbers_and_caller_id_hint(): void
    {
        $this->assertMapping(false, 'caller ID');
    }

    private function pair(PhoneCall $call): string
    {
        $ticket = Ticket::factory()->for(Client::factory())->create();
        $call->ticket_id = $ticket->id;
        $call->save();
        $html = $this->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<div class="small">\s*([^<]*&rarr;[^<]*)/s', $html, $matches), 'Missing call number pair');

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($matches[1])));
    }

    public function test_outbound_ticket_pair_is_our_line_then_other_party(): void
    {
        $this->assertSame(self::OUR.' → '.PhoneNumber::format(self::FAR), $this->pair($this->makeCall(true)));
    }

    public function test_inbound_ticket_pair_is_other_party_then_our_line(): void
    {
        $this->assertSame(PhoneNumber::format(self::FAR).' → '.self::OUR, $this->pair($this->makeCall(false)));
    }

    public function test_anonymous_inbound_has_no_number_link_or_provenance(): void
    {
        $call = $this->makeCall(false, '');
        $this->assertNull($call->farEndNumber());
        $other = $this->row($this->table($call), 'Other party');
        $this->assertSame('Unknown', trim(strip_tags($other)));
        $this->assertStringNotContainsString('data-phone', $other);
        $this->assertDoesNotMatchRegularExpression('/[0-9]|caller ID|dialled/', $other);
        $this->assertSame('Unknown → '.self::OUR, $this->pair($call));
    }

    public function test_empty_our_line_uses_em_dash_on_both_pages(): void
    {
        $call = $this->makeCall(true);
        $call->to_number = '';
        $call->save();
        $this->assertSame('—', trim($this->row($this->table($call), 'Our line')));
        $this->assertSame('— → '.PhoneNumber::format(self::FAR), $this->pair($call));
    }
}
