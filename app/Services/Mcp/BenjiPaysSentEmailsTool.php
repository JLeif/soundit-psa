<?php

namespace App\Services\Mcp;

use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;

/**
 * benjipays_list_sent_emails — READ-ONLY sent-email history (receipts,
 * reminders, invoices) for one PSA client, card 6abec4f9 stage 2.
 *
 * One GET /v2/emails?customerId= through BenjiPaysClient (existing send(), no
 * retries, status-only errors). Fenced by BenjiPaysReadFence and redacted by
 * BenjiPaysReadProjection::emails() to date, type, status and the recipient
 *
 * masked as ruled (`j***@example.com`): never a subject, body, sender, cc/bcc
 * or full address.
 */
final class BenjiPaysSentEmailsTool
{
    public const NAME = 'benjipays_list_sent_emails';

    public const MAX_LIMIT = 50;

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Lists the emails BenjiPays sent about ONE PSA client, newest first: sent date, type (invoice, receipt, emailreminder, cardrequest, invoicepaid, ...), delivery status (sent/queued/error) and each To recipient masked to its first letter and full domain (j***@example.com; at most 10 per email, recipient_count is the full number). Proves whether a receipt or reminder went out. Give client_id (PSA client); optional type and status filters use BenjiPays\' own values. The client must be mapped to its QuickBooks customer, or the call is refused. limit default 25, max 50; has_more says whether older rows exist. Never returns subjects, bodies, senders, cc/bcc or full addresses. A 403 means the BenjiPays key lacks organizations:emails:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'PSA client id.'],
                    'type' => ['type' => 'string', 'enum' => BenjiPaysReadProjection::EMAIL_TYPES],
                    'status' => ['type' => 'string', 'enum' => BenjiPaysReadProjection::EMAIL_STATUSES],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                ],
                'required' => ['client_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(array $input, ?int $clientId, bool $clientIdSupplied): array
    {
        if (! $clientIdSupplied) {
            return ['error' => 'client_id (PSA client id) is required.'];
        }
        $type = $input['type'] ?? null;
        $status = $input['status'] ?? null;
        if ($type !== null && ! in_array($type, BenjiPaysReadProjection::EMAIL_TYPES, true)) {
            return ['error' => 'type must be one of: '.implode(', ', BenjiPaysReadProjection::EMAIL_TYPES).'.'];
        }
        if ($status !== null && ! in_array($status, BenjiPaysReadProjection::EMAIL_STATUSES, true)) {
            return ['error' => 'status must be one of: '.implode(', ', BenjiPaysReadProjection::EMAIL_STATUSES).'.'];
        }
        $target = BenjiPaysReadFence::resolve([], $clientId, $clientIdSupplied, false);
        if (isset($target['error'])) {
            return $target;
        }
        $limit = BenjiPaysReadFence::limit($input, self::MAX_LIMIT);
        if (is_array($limit)) {
            return $limit;
        }
        if ($refusal = BenjiPaysReadFence::notConfigured()) {
            return $refusal;
        }

        try {
            $page = BenjiPaysReadProjection::emails(
                $this->client->emails($target['customer_id'], $type, $status, $limit),
                $target['customer_id'],
            );
        } catch (BenjiPaysException $e) {
            return BenjiPaysReadFence::error($e, BenjiPaysReadFence::scopeMessage('sent-email', 'organizations:emails:read'), 'sent-email');
        }

        return [
            'client_id' => $target['client_id'],
            'count' => count($page['rows']),
            'has_more' => $page['has_more'],
            'total' => $page['total'],
            'emails' => $page['rows'],
            'source' => 'BenjiPays GET /v2/emails',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }
}
