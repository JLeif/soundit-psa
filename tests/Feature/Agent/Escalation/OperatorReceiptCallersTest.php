<?php

namespace Tests\Feature\Agent\Escalation;

use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\EmailService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Services\Technician\Notify\SmsNotifier;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperatorReceiptCallersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([OperatorDelivery::class, EscalationNotifier::class, OperatorNotifier::class, WikiRedactor::class] as $class) {
            $this->assertStringStartsWith(base_path('app/'), (new \ReflectionClass($class))->getFileName());
        }
    }

    public static function transports(): array
    {
        return [[true, null], [false, null], [true, 'Open the cockpit'], [false, 'Open the cockpit']];
    }

    #[DataProvider('transports')]
    public function test_emergency_remains_unscanned_and_sms_bytes_unchanged(bool $posted, ?string $smsText): void
    {
        Http::preventStrayRequests();
        Log::spy();
        $user = User::factory()->create(['email' => 'operator@example.test']);
        TechnicianConfig::setOperatorPhone($user->id, '+15550001111');
        $body = 'Emergency https://example.test/emergency/ack/fixture/link password: fixture';
        $this->mock(WikiRedactor::class)->shouldNotReceive('scan');
        $this->mock(TeamsNotifier::class)->shouldReceive('post')->once()->with('Emergency', $body)->andReturn($posted);
        $this->mock(EmailService::class)->shouldReceive('sendNew')->once()->with($user->email, 'Emergency', $body, null, null, null);
        $this->mock(SmsNotifier::class)->shouldReceive('send')->once()->with('+15550001111', $smsText ?? 'Emergency — '.$body)->andReturnTrue();
        $this->assertNull(app(OperatorNotifier::class)->notifyUser($user->id, 'Emergency', $body, true, $smsText));
        Log::shouldHaveReceived('info')->once()->with('[Technician] Operator delivery receipt', [
            'posted' => $posted, 'posted_to_chat' => false, 'scan_status' => 'unassessed',
        ]);
    }

    public static function blockers(): array
    {
        return [
            ['plain blocker', false, false, []],
            ['[escalation detail withheld - open the ticket]', false, false, []],
            ['password: fixture', true, false, ['credential']],
            [str_repeat('a', 510), false, true, []],
            ['password: fixture '.str_repeat('a', 510), true, false, ['credential']],
            [str_repeat('a', 500).' password: fixture', false, true, []],
        ];
    }

    #[DataProvider('blockers')]
    public function test_escalation_records_authoritative_fragment_metadata_without_changing_sent_body(string $blocker, bool $withheld, bool $truncated, array $classes): void
    {
        Http::preventStrayRequests();
        Log::spy();
        $ticket = Ticket::factory()->create(['subject' => 'Fixture']);
        $run = TechnicianRun::create(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'action_type' => 'flag_attention', 'content_hash' => str_repeat('a', 64), 'state' => \App\Enums\TechnicianRunState::Flagged, 'proposed_meta' => ['escalation' => ['category' => 'judgment']]]);
        $body = null;
        $this->mock(EmailService::class)->shouldNotReceive('sendNew');
        $this->mock(TeamsNotifier::class)->shouldReceive('post')->once()->andReturnUsing(function ($subject, $text) use (&$body) {
            $body = $text;

            return false;
        });
        app(EscalationNotifier::class)->deliverTo($ticket, $run, null, $blocker, 2);
        $meta = $run->fresh()->proposed_meta['escalation'];
        $this->assertSame('judgment', $meta['category']);
        $this->assertSame(2, $meta['step']);
        $this->assertArrayHasKey('delivery_receipt', $meta);
        $this->assertSame([
            'posted' => false, 'posted_to_chat' => false,
            'scan_status' => 'assessed', 'text_withheld' => $withheld,
            'text_truncated' => $truncated, 'text_total_chars' => mb_strlen($blocker), 'scan_classes' => $classes,
        ], $meta['delivery_receipt']);
        $fragment = $withheld ? 'escalation detail withheld - open the ticket' : \App\Services\Technician\Notify\TeamsText::escape(mb_substr($blocker, 0, 500));
        $this->assertStringContainsString(': '.$fragment.'. Open the cockpit:', $body);
    }

    public function test_scan_runs_once_and_metadata_does_not_contain_raw_patterns(): void
    {
        $redactor = $this->mock(WikiRedactor::class);
        $redactor->shouldReceive('scan')->once()->with('fixture')->andReturn([
            ['class' => 'credential', 'pattern' => 'NEVER-SERIALIZE'],
            ['class' => 'credential', 'pattern' => 'SECOND-PATTERN'],
            ['class' => 'future-unsafe-class', 'pattern' => 'OTHER'],
        ]);
        $safe = app(OperatorDelivery::class)->sanitizeMessageWithMeta('fixture');
        $this->assertSame([
            'scan_status' => 'assessed', 'text_withheld' => true, 'text_truncated' => false,
            'text_total_chars' => 7, 'scan_classes' => ['credential'],
        ], $safe['meta']->toArray());
    }
}
