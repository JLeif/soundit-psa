<?php

namespace Tests\Feature\Email;

use App\Enums\EmailDirection;
use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\Email;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The 5-minute email:poll fallback re-runs processInbound() on every inbound email
 * that is still unresolved (ticket_id NULL, dismissed_at NULL) inside its lookback
 * window. Because the poll cursor can never advance past the newest message, the
 * newest unresolved email is re-processed on every pass — which sent the operator a
 * duplicate "Unresolved inbound email" notification every 5 minutes for 17 hours
 * (208 notifications for a single spam message, 2026-08-02).
 *
 * The notification must fire once per email, not once per processing pass.
 */
class UnresolvedEmailNotifyOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        User::factory()->create([
            'is_active' => true,
            'email' => 'operator@example.com',
            'notification_preferences' => [
                NotificationEventType::UnresolvedInboundEmail->value => true,
            ],
        ]);
    }

    private function unresolvedEmail(): Email
    {
        // Unknown sender, but nothing that trips the auto-reply or spam filters —
        // this is the "real support email we can't place" case that must notify.
        return Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@unknown-company.example',
            'from_name' => 'Someone',
            'subject' => 'Our printer is jammed again',
            'body_text' => 'The printer in the back office is jammed and we cannot clear it.',
            'received_at' => now(),
        ]);
    }

    public function test_first_processing_pass_notifies_the_operator(): void
    {
        $email = $this->unresolvedEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNull($email->fresh()->ticket_id, 'email should still be unresolved');
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_reprocessing_the_same_unresolved_email_does_not_notify_again(): void
    {
        $email = $this->unresolvedEmail();

        $service = app(EmailService::class);

        // Three poll passes, 5 minutes apart, over the same still-unresolved email.
        $service->processInbound($email);
        $service->processInbound($email->fresh());
        $service->processInbound($email->fresh());

        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_a_different_unresolved_email_still_notifies(): void
    {
        $service = app(EmailService::class);

        $first = $this->unresolvedEmail();
        $service->processInbound($first);

        $second = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'other@unknown-company.example',
            'from_name' => 'Other',
            'subject' => 'Scanner will not feed paper',
            'body_text' => 'The scanner refuses to pull pages through the feeder.',
            'received_at' => now(),
        ]);
        $service->processInbound($second);

        Bus::assertDispatchedTimes(SendTicketNotification::class, 2);
    }
}
