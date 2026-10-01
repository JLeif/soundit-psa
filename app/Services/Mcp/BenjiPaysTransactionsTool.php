<?php

namespace App\Services\Mcp;

use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;

/**
 * benjipays_list_transactions — READ-ONLY payment transactions for one PSA
 * client (or one of its invoices), card 6abec4f9.
 *
 * One GET /v2/transactions through BenjiPaysClient::transactions() (existing
 * send(), x-api-key from BenjiPaysConfig, no retries, status-only errors).
 * Fenced by BenjiPaysReadFence and redacted by BenjiPaysReadProjection:
 * method type and last four digits only, no customer name, gateway strings,
 * references or settlement data.
 */
final class BenjiPaysTransactionsTool
{
    public const NAME = 'benjipays_list_transactions';

    public const MAX_LIMIT = 50;

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Lists BenjiPays payment transactions for ONE PSA client, newest first: date, type (payment/void/refund/deposit), status (approved/declined/queued/pending/...), approved flag, amount, currency, surcharge amount and rate, method type (card/bank) with the last four digits only, and the invoice(s) it applied to (at most 20 listed per transaction; applied_to_count is the full number and applied_to_truncated is true when some were left out). Answers "how was this paid", "did a card fail" and "was a surcharge charged". Give client_id (PSA client), or one invoice by qbo_invoice_id or doc_number to see only that invoice\'s transactions. The client must be mapped to its QuickBooks customer, or the call is refused. limit default 25, max 50; has_more says whether older rows exist. Never returns card numbers, customer names or gateway references. A 403 means the BenjiPays key lacks organizations:transactions:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'PSA client id.'],
                    'qbo_invoice_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'doc_number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                ],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(array $input, ?int $clientId, bool $clientIdSupplied): array
    {
        $target = BenjiPaysReadFence::resolve($input, $clientId, $clientIdSupplied, false);
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
        $invoiceId = $target['invoice']?->qbo_invoice_id;

        try {
            $page = BenjiPaysReadProjection::transactions(
                $this->client->transactions($target['customer_id'], $invoiceId === null ? null : (string) $invoiceId, $limit),
                $target['customer_id'],
            );
        } catch (BenjiPaysException $e) {
            return BenjiPaysReadFence::error($e, BenjiPaysReadFence::scopeMessage('transactions', 'organizations:transactions:read'), 'transactions');
        }

        return [
            'client_id' => $target['client_id'],
            'qbo_invoice_id' => $invoiceId === null ? null : (string) $invoiceId,
            'count' => count($page['rows']),
            'has_more' => $page['has_more'],
            'total' => $page['total'],
            'transactions' => $page['rows'],
            'source' => 'BenjiPays GET /v2/transactions',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }
}
