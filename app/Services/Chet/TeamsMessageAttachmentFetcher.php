<?php

namespace App\Services\Chet;

use App\Services\Assistant\AssistantToolExecutor;
use App\Services\AttachmentService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * get_teams_message_attachment (card 2Cj3kOsy): one inline image from a known
 * Teams chat, in get_ticket_attachment's return shape and under its ceilings.
 *
 * Read-only: two Graph GETs, no writes anywhere. The caller's attachment_id is
 * our ordinal ("inline-N"); it is resolved against the hosted-content ids in
 * the message Graph returns, so no caller-supplied id reaches a Graph path
 * except the message id, which must be the all-digit Graph id shape.
 *
 * File attachments (contentType reference) are SharePoint/OneDrive links. The
 * Graph permission that reads chats does not read those drives, so they are
 * refused with their name rather than fetched through a new Files grant.
 */
class TeamsMessageAttachmentFetcher
{
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @param  Closure(string): bool  $isKnownConversation  the SAME fence get_teams_chat_history uses
     * @return array<string, mixed>
     */
    public function fetch(?string $chatId, array $input, Closure $isKnownConversation): array
    {
        if ($chatId === null) {
            return ['error' => 'chat_id is required'];
        }

        if (! $isKnownConversation($chatId)) {
            return ['error' => "Teams chat '{$chatId}' denied: not a known Teams conversation"];
        }

        $messageId = is_scalar($input['message_id'] ?? null) ? trim((string) $input['message_id']) : '';
        if (! preg_match('/^[0-9]{1,32}$/', $messageId)) {
            return ['error' => 'message_id is required (the numeric Teams message id)'];
        }

        $attachmentId = is_scalar($input['attachment_id'] ?? null) ? trim((string) $input['attachment_id']) : '';
        if (! preg_match('/^(inline|file)-[1-9][0-9]{0,2}$/', $attachmentId)) {
            return ['error' => 'attachment_id is required (e.g. "inline-1" from the message\'s attachments list)'];
        }

        $graph = app(GraphClient::class);
        $messagePath = "chats/{$chatId}/messages/{$messageId}";

        try {
            $message = $graph->get($messagePath);
        } catch (\Throwable $e) {
            return $this->graphFailure($e, $chatId, 'message');
        }

        $ref = null;
        foreach (TeamsMessageAttachments::fromGraphMessage(is_array($message) ? $message : []) as $candidate) {
            if ($candidate['attachment_id'] === $attachmentId) {
                $ref = $candidate;
                break;
            }
        }

        // Same-shaped refusal for an absent ordinal and an absent message body ref.
        if ($ref === null || $ref['_vendor_id'] === '') {
            return ['error' => 'Attachment not found on this message'];
        }

        if ($ref['kind'] === 'file') {
            $name = $ref['filename'] ?? 'unnamed file';

            return ['error' => "Attachment {$attachmentId} is a shared file ({$name}), not an inline image. Shared files live in SharePoint/OneDrive and are not fetchable with this tool's Graph permission; ask the operator to paste it into the chat as an image or attach it to a ticket."];
        }

        try {
            $content = $graph->getRaw($messagePath.'/hostedContents/'.rawurlencode($ref['_vendor_id']).'/$value');
        } catch (\Throwable $e) {
            return $this->graphFailure($e, $chatId, 'hosted content');
        }

        if ($content === null || $content === '') {
            return ['error' => 'Attachment content could not be read from Teams (not found or empty)'];
        }

        // Byte ceiling BEFORE any GD decode (get_ticket_attachment's order).
        if (strlen($content) > AssistantToolExecutor::MAX_ATTACHMENT_BYTES) {
            return ['error' => 'Attachment is too large to return inline ('.strlen($content).' bytes, limit '.AssistantToolExecutor::MAX_ATTACHMENT_BYTES.'); open it in Teams.'];
        }

        // The type comes from the bytes, never from a header or a name.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
        if (! in_array($mime, self::IMAGE_MIME_TYPES, true)) {
            return ['error' => "Attachment is not an image this tool can return ({$mime}); open it in Teams."];
        }

        // Pixel ceiling before GD decode: header-only read (decompression-bomb guard).
        $info = @getimagesizefromstring($content);
        if ($info !== false && ($info[0] * $info[1]) > AssistantToolExecutor::MAX_IMAGE_PIXELS) {
            return ['error' => 'Image dimensions too large to process ('.$info[0].'x'.$info[1].').'];
        }

        $data = $this->attachments->resizeImageBytesForAi($content, $mime);
        if ($data === null || $data === '') {
            return ['error' => 'Attachment could not be decoded as an image'];
        }

        return [
            'attachment_id' => $attachmentId,
            'filename' => $ref['filename'],
            'media_type' => match ($mime) {
                'image/png', 'image/gif' => 'image/png',
                'image/webp' => 'image/webp',
                default => 'image/jpeg',
            },
            'is_image' => true,
            'data_base64' => $data,
        ];
    }

    /** @return array{error: string} */
    private function graphFailure(\Throwable $e, string $chatId, string $what): array
    {
        $status = $e instanceof GraphClientException ? $e->getHttpStatus() : 0;

        Log::warning('[ChetDataSurface] Teams message attachment read failed', [
            'chat_id' => $chatId,
            'stage' => $what,
            'status' => $status,
        ]);

        return match ($status) {
            401, 403 => ['error' => "Teams refused the {$what} read (HTTP {$status}): the PSA's Microsoft Graph app registration needs the ".TeamsChatReadToolset::HOSTED_CONTENT_PERMISSION.' application permission with admin consent to read chat images. Ask the operator to grant it; nothing was read.'],
            404 => ['error' => 'Attachment not found on this message'],
            default => ['error' => "Teams {$what} read failed (HTTP {$status}); nothing was read."],
        };
    }
}
