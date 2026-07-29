<?php

namespace Tests\Feature\Assistant;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * get_ticket_attachment (psa-#331): the MCP surface verb that lets an agent
 * actually see image/file attachments on a ticket. The web /attachments/ route
 * 302s an MCP bearer to the login page, so client error screenshots were
 * silently invisible; this verb serves the already-shipped attachment store
 * under the SAME client scope get_ticket_notes proves.
 *
 * The refusal tests are the load-bearing ones: a surface verb over client data
 * is only safe if cross-client and cross-ticket reads are refused, and refused
 * with the same shape whether the boundary crossed is the client or the ticket
 * (never confirming an attachment id exists on some other ticket).
 */
class GetTicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** A minimal, real PNG so resizeImageForAi's GD path exercises for real. */
    private function pngBytes(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function note(Ticket $ticket): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Charlie',
            'body' => 'See the error: ![shot](/attachments/PLACEHOLDER/shot.png)',
            'note_type' => 'note',
            'noted_at' => now(),
        ]);
    }

    private function imageAttachmentOn(string $attachableType, int $attachableId): Attachment
    {
        $bytes = $this->pngBytes();

        $att = Attachment::create([
            'filename' => 'shot.png',
            'original_filename' => 'shot.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'storage_path' => 'attachments/tmp',
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
        ]);

        $path = "attachments/{$att->id}/shot.png";
        Storage::disk('local')->put($path, $bytes);
        $att->update(['storage_path' => $path]);

        return $att;
    }

    public function test_reads_an_image_attachment_on_a_note_for_the_owning_client(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $note = $this->note($ticket);
        $att = $this->imageAttachmentOn(TicketNote::class, $note->id);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_attachment', ['ticket_id' => $ticket->id, 'attachment_id' => $att->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['is_image']);
        $this->assertSame('image/png', $result['media_type']);
        $this->assertNotEmpty($result['data_base64']);
        $this->assertNotFalse(base64_decode($result['data_base64'], true));
    }

    public function test_reads_an_image_attachment_linked_directly_to_the_ticket(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $att = $this->imageAttachmentOn(Ticket::class, $ticket->id);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_attachment', ['ticket_id' => $ticket->id, 'attachment_id' => $att->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('shot.png', $result['filename']);
    }

    public function test_refuses_a_cross_client_ticket_id(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $otherTicket = Ticket::factory()->create(['client_id' => $other->id]);
        $att = $this->imageAttachmentOn(Ticket::class, $otherTicket->id);

        // Scoped to MY client, but naming the OTHER client's ticket + attachment.
        $result = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_attachment', ['ticket_id' => $otherTicket->id, 'attachment_id' => $att->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('different client', $result['error']);
        $this->assertArrayNotHasKey('data_base64', $result);
    }

    public function test_refuses_an_attachment_that_belongs_to_another_ticket(): void
    {
        $client = Client::factory()->create();
        $ticketA = Ticket::factory()->create(['client_id' => $client->id]);
        $ticketB = Ticket::factory()->create(['client_id' => $client->id]);
        $att = $this->imageAttachmentOn(Ticket::class, $ticketA->id);

        // Same client owns both tickets, but the attachment is on ticket A and
        // we ask through ticket B — the ticket pin must refuse it.
        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_attachment', ['ticket_id' => $ticketB->id, 'attachment_id' => $att->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found on this ticket', $result['error']);
        $this->assertArrayNotHasKey('data_base64', $result);
    }

    public function test_refuses_without_client_context(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $att = $this->imageAttachmentOn(Ticket::class, $ticket->id);

        // No client scope at all (the general/no-client surface).
        $result = (new AssistantToolExecutor)
            ->execute('get_ticket_attachment', ['ticket_id' => $ticket->id, 'attachment_id' => $att->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('data_base64', $result);
    }
}
