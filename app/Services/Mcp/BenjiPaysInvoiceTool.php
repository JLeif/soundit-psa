<?php

namespace App\Services\Mcp;

use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;

/**
 * benjipays_get_invoice — READ-ONLY BenjiPays view of ONE PSA invoice
 * (balance, status, totals), card 6abec4f9.
 *
 * One GET /v2/invoices/{id} through the existing BenjiPaysClient::invoice()
 * (no `include=accounting`). Fenced by BenjiPaysReadFence: the invoice must
 * be a PSA invoice mapped to QuickBooks whose client is mapped too, and the
 * vendor's answer must name that invoice and that customer.
 */
final class BenjiPaysInvoiceTool
{
    public const NAME = 'benjipays_get_invoice';

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Reads ONE invoice as BenjiPays sees it: status (open/paid/overdue), paid flag, total, balance (amount remaining, so a partial payment shows as balance below total), subtotal, tax, currency, invoice/due dates and payment terms. Give exactly one of qbo_invoice_id or doc_number; it must be a PSA invoice mapped to QuickBooks whose client is mapped to its QuickBooks customer, or the call is refused. Use benjipays_list_transactions for the individual payments. Never returns customer names, memo or reference text. A 403 means the BenjiPays key lacks organizations:invoices:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'qbo_invoice_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'doc_number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                ],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(array $input, ?int $clientId, bool $clientIdSupplied): array
    {
        $target = BenjiPaysReadFence::resolve($input, $clientId, $clientIdSupplied, true);
        if (isset($target['error'])) {
            return $target;
        }
        if ($refusal = BenjiPaysReadFence::notConfigured()) {
            return $refusal;
        }
        $invoiceId = (string) $target['invoice']->qbo_invoice_id;

        try {
            $invoice = BenjiPaysReadProjection::invoice($this->client->invoice($invoiceId), $invoiceId, $target['customer_id']);
        } catch (BenjiPaysException $e) {
            return BenjiPaysReadFence::error($e, BenjiPaysReadFence::scopeMessage('invoice', 'organizations:invoices:read'), 'invoice');
        }

        return [
            'client_id' => $target['client_id'],
            'qbo_invoice_id' => $invoiceId,
        ] + $invoice + [
            'source' => 'BenjiPays GET /v2/invoices/{invoiceId}',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }
}
