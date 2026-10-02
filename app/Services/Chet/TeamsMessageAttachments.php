<?php

namespace App\Services\Chet;

/**
 * Attachment refs for a Teams chat message (card 2Cj3kOsy).
 *
 * Two producers, one ref shape. The attachment_id is OUR ordinal token
 * ("inline-N" / "file-N"), never a vendor id, so a ref read from the operator
 * poll (built from the Bot Framework activity at ingest) and a ref read from
 * get_teams_chat_history (built from the Graph chatMessage) name the same
 * attachment, and get_teams_message_attachment never puts a caller-supplied id
 * into a Graph path: it re-reads the message and resolves the ordinal against
 * the ids Graph itself returned.
 *
 * Producer shapes (Microsoft Learn, Graph v1.0, read 2026-10-02):
 *  - chatMessage (chat-list-messages / chatmessage-get examples): an inline
 *    image is `<img src="https://graph.microsoft.com/v1.0/chats/{chat}/messages/{msg}/hostedContents/{id}/$value">`
 *    in body.content; attachments[] is empty for it.
 *  - chatMessageAttachment: contentType `reference` is a link to a file
 *    (contentUrl, name); the body carries `<attachment id="{id}"></attachment>`.
 *    Other contentTypes (cards, forwardedMessageReference, code snippets) are
 *    not files and get no ref.
 *  - Bot Framework activity (Teams bots-filesv4 and the Teams vision sample):
 *    an inline image arrives as an attachment with contentType `image/*`; a
 *    file as `application/vnd.microsoft.teams.file.download.info` with `name`.
 */
class TeamsMessageAttachments
{
    public const FILENAME_MAX_CHARS = 100;

    /** Ceiling on refs per kind, so a hostile body cannot mint unbounded refs. */
    public const MAX_PER_KIND = 20;

    private const ACTIVITY_FILE_CONTENT_TYPE = 'application/vnd.microsoft.teams.file.download.info';

    private const GRAPH_FILE_CONTENT_TYPE = 'reference';

    /**
     * Refs parsed from a Graph chatMessage, each carrying the vendor id it
     * resolves to under `_vendor_id` (internal: strip with publicRefs()).
     *
     * @return array<int, array{attachment_id: string, kind: string, filename: ?string, mime_type: ?string, size_bytes: ?int, _vendor_id: string}>
     */
    public static function fromGraphMessage(array $message): array
    {
        $refs = [];

        $content = $message['body']['content'] ?? null;
        $isHtml = strtolower((string) ($message['body']['contentType'] ?? '')) === 'html';
        if ($isHtml && is_string($content)) {
            foreach (self::hostedContentIds($content) as $i => $hostedId) {
                $refs[] = [
                    'attachment_id' => 'inline-'.($i + 1),
                    'kind' => 'inline',
                    'filename' => null,
                    'mime_type' => null,
                    'size_bytes' => null,
                    '_vendor_id' => $hostedId,
                ];
            }
        }

        $n = 0;
        foreach (is_array($message['attachments'] ?? null) ? $message['attachments'] : [] as $attachment) {
            if (! is_array($attachment) || ($attachment['contentType'] ?? null) !== self::GRAPH_FILE_CONTENT_TYPE) {
                continue;
            }
            if (++$n > self::MAX_PER_KIND) {
                break;
            }
            $refs[] = [
                'attachment_id' => 'file-'.$n,
                'kind' => 'file',
                'filename' => self::sanitizeFilename($attachment['name'] ?? null),
                'mime_type' => null,
                'size_bytes' => null,
                '_vendor_id' => is_scalar($attachment['id'] ?? null) ? (string) $attachment['id'] : '',
            ];
        }

        return $refs;
    }

    /**
     * Refs from an inbound Bot Framework activity, for the operator inbox row.
     * Same ordinals as fromGraphMessage(); no URLs are kept (a file's
     * downloadUrl is a pre-authorised link and must not be stored).
     *
     * @return array<int, array{attachment_id: string, kind: string, filename: ?string, mime_type: ?string, size_bytes: ?int}>
     */
    public static function fromActivity(array $activity): array
    {
        $refs = [];
        $images = 0;
        $files = 0;
        $seenImageUrls = [];

        foreach (is_array($activity['attachments'] ?? null) ? $activity['attachments'] : [] as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $type = is_string($attachment['contentType'] ?? null) ? strtolower($attachment['contentType']) : '';

            // One image can be announced twice with the same contentUrl; count it once.
            $url = is_string($attachment['contentUrl'] ?? null) ? $attachment['contentUrl'] : null;
            if (str_starts_with($type, 'image/') && $url !== null && isset($seenImageUrls[$url])) {
                continue;
            }

            if (str_starts_with($type, 'image/') && $images < self::MAX_PER_KIND) {
                if ($url !== null) {
                    $seenImageUrls[$url] = true;
                }
                $refs[] = [
                    'attachment_id' => 'inline-'.(++$images),
                    'kind' => 'inline',
                    'filename' => null,
                    'mime_type' => null,
                    'size_bytes' => null,
                ];
            } elseif ($type === self::ACTIVITY_FILE_CONTENT_TYPE && $files < self::MAX_PER_KIND) {
                $refs[] = [
                    'attachment_id' => 'file-'.(++$files),
                    'kind' => 'file',
                    'filename' => self::sanitizeFilename($attachment['name'] ?? null),
                    'mime_type' => null,
                    'size_bytes' => null,
                ];
            }
        }

        return $refs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array{attachment_id: string, kind: string, filename: ?string, mime_type: ?string, size_bytes: ?int}>
     */
    public static function publicRefs(array $refs): array
    {
        return array_values(array_map(static fn (array $ref): array => [
            'attachment_id' => (string) $ref['attachment_id'],
            'kind' => (string) $ref['kind'],
            'filename' => $ref['filename'] ?? null,
            'mime_type' => $ref['mime_type'] ?? null,
            'size_bytes' => $ref['size_bytes'] ?? null,
        ], $refs));
    }

    /**
     * publicRefs() for output. The filename is sender-typed text, so it goes
     * through the same fence and redaction as every other sender string;
     * marker() never carries it.
     *
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array{attachment_id: string, kind: string, filename: ?string, mime_type: ?string, size_bytes: ?int}>
     */
    public static function fencedRefs(array $refs, ChetDataSurfaceTextSanitizer $sanitizer): array
    {
        return array_map(static function (array $ref) use ($sanitizer): array {
            $ref['filename'] = $sanitizer->sanitizeNullable('Teams chat attachment filename', $ref['filename'], self::FILENAME_MAX_CHARS);

            return $ref;
        }, self::publicRefs($refs));
    }

    /**
     * Our marker for one ref: "[image 1]" or "[file 1]". It sits outside the
     * untrusted fence, so it carries nothing the sender typed.
     */
    public static function marker(array $ref): string
    {
        $n = (int) substr((string) $ref['attachment_id'], strrpos((string) $ref['attachment_id'], '-') + 1);

        return ($ref['kind'] ?? null) === 'inline' ? "[image {$n}]" : "[file {$n}]";
    }

    /** @param  array<int, array<string, mixed>>  $refs */
    public static function markers(array $refs): ?string
    {
        return $refs === [] ? null : implode(' ', array_map(self::marker(...), $refs));
    }

    /**
     * Untrusted filename -> a short, inert display name: last path segment,
     * letters/digits/space/._()- only (so no fence '=', role ':', marker
     * brackets, markup or control/bidi characters survive), capped.
     */
    public static function sanitizeFilename(mixed $name): ?string
    {
        if (! is_string($name) || $name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name;
        }

        $name = str_replace('\\', '/', $name);
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }

        $name = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name) ?? '';
        $name = preg_replace('/_{2,}/', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " ._\t");
        $name = mb_substr($name, 0, self::FILENAME_MAX_CHARS);

        return $name === '' ? null : $name;
    }

    /** @return array<int, string> hosted content ids in body order, de-duplicated */
    private static function hostedContentIds(string $html): array
    {
        $ids = [];
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags) === false) {
            return [];
        }
        foreach ($tags[0] as $tag) {
            $id = self::hostedContentIdFromImgTag($tag);
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
                if (count($ids) >= self::MAX_PER_KIND) {
                    break;
                }
            }
        }

        return $ids;
    }

    private static function hostedContentIdFromImgTag(string $tag): ?string
    {
        if (! preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
            return null;
        }
        $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('#/hostedContents/(.{1,2048}?)/\$value(?:[?\#]|$)#', $src, $id)) {
            return null;
        }
        $id = rawurldecode($id[1]);

        // Base64-family alphabet only (the documented ids are base64 of an
        // internal locator). Anything else is not a ref we will ever fetch.
        // The fetcher rawurlencodes it into its own path segment.
        return preg_match('#^[A-Za-z0-9+/=_-]{1,2048}$#', $id) ? $id : null;
    }
}
