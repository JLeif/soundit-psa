<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\BenjiPays\BenjiPaysException;
use App\Support\BenjiPaysConfig;

/**
 * Client fence and shared plumbing for the read-only BenjiPays MCP tools
 * (card 6abec4f9, stage 1).
 *
 * Callers name PSA records only: a PSA client_id, or one PSA invoice by
 * doc_number / qbo_invoice_id. Each resolves to BenjiPays through the existing
 * QuickBooks mapping (clients.qbo_customer_id, invoices.qbo_invoice_id) and is
 * REFUSED when that mapping is missing. No tool accepts a raw BenjiPays or
 * QuickBooks customer id from the caller, so a caller cannot read a customer
 * the PSA has not mapped to one of its clients.
 */
final class BenjiPaysReadFence
{
    public const DEFAULT_LIMIT = 25;

    /**
     * Resolve the caller's PSA identifiers to the mapped QuickBooks customer
     * (and invoice). Returns ['error' => string] on any refusal.
     *
     * @return array{customer_id: string, client_id: int, invoice: ?Invoice}|array{error: string}
     */
    public static function resolve(array $input, ?int $clientId, bool $clientIdSupplied, bool $invoiceRequired): array
    {
        if ($clientIdSupplied && $clientId === null) {
            return ['error' => 'client_id must be a positive integer PSA client id.'];
        }
        $qboId = $input['qbo_invoice_id'] ?? null;
        $docNumber = $input['doc_number'] ?? null;
        if ($qboId !== null && $docNumber !== null) {
            return ['error' => 'Give at most one of qbo_invoice_id or doc_number.'];
        }
        if (($qboId !== null && (! is_string($qboId) || trim($qboId) === ''))
            || ($docNumber !== null && (! is_string($docNumber) || trim($docNumber) === ''))) {
            return ['error' => 'qbo_invoice_id / doc_number must be a non-empty string.'];
        }
        if ($invoiceRequired && $qboId === null && $docNumber === null) {
            return ['error' => 'Give one of qbo_invoice_id or doc_number.'];
        }
        if ($clientId === null && $qboId === null && $docNumber === null) {
            return ['error' => 'Give client_id, or one invoice by qbo_invoice_id or doc_number.'];
        }

        $invoice = null;
        if ($qboId !== null || $docNumber !== null) {
            $query = Invoice::query()->whereNotNull('qbo_invoice_id')->where('qbo_invoice_id', '!=', '');
            $query = $qboId !== null
                ? $query->where('qbo_invoice_id', trim($qboId))
                : $query->where(fn ($q) => $q->where('qbo_doc_number', trim($docNumber))->orWhere('invoice_number', trim($docNumber)));
            $matches = $query->limit(2)->get(['id', 'client_id', 'qbo_invoice_id', 'qbo_doc_number', 'invoice_number']);
            if ($matches->count() !== 1) {
                return ['error' => $matches->isEmpty()
                    ? ($qboId !== null
                        ? 'No PSA invoice carries that QuickBooks id. These tools read BenjiPays only for PSA invoices.'
                        : 'No PSA invoice with that DocNumber has a QuickBooks id. These tools read BenjiPays only for PSA invoices mapped to QuickBooks.')
                    : 'More than one PSA invoice matches; pass qbo_invoice_id instead.'];
            }
            $invoice = $matches->first();
            if ($clientId !== null && (int) $invoice->client_id !== $clientId) {
                return ['error' => 'That invoice does not belong to client_id '.$clientId.'.'];
            }
            $clientId = (int) $invoice->client_id;
        }

        $client = Client::query()->find($clientId, ['id', 'qbo_customer_id']);
        if ($client === null) {
            return ['error' => 'No PSA client with id '.$clientId.'.'];
        }
        $customerId = trim((string) $client->qbo_customer_id);
        if ($customerId === '') {
            return ['error' => 'PSA client '.$clientId.' has no QuickBooks customer mapping, so it is refused: map it to its QuickBooks customer first.'];
        }

        return ['customer_id' => $customerId, 'client_id' => (int) $clientId, 'invoice' => $invoice];
    }

    /** @return int|array{error: string} */
    public static function limit(array $input, int $max): int|array
    {
        $limit = $input['limit'] ?? self::DEFAULT_LIMIT;
        if (! is_int($limit) || $limit < 1) {
            return ['error' => 'limit must be a positive integer (max '.$max.').'];
        }

        return min($limit, $max);
    }

    public static function notConfigured(): ?array
    {
        return BenjiPaysConfig::isConfigured()
            ? null
            : ['error' => 'BenjiPays is not configured (no API key stored), so nothing can be read.'];
    }

    /** Status-only error text; a 403 names the scope the read needs. */
    public static function error(BenjiPaysException $e, string $scopeMessage, string $what): array
    {
        return ['error' => match ($e->reason) {
            'forbidden' => $scopeMessage,
            'http_error' => 'BenjiPays '.$what.' read failed (HTTP '.($e->httpStatus ?? 'unknown').').',
            default => $e->getMessage().($e->httpStatus !== null ? ' (HTTP '.$e->httpStatus.')' : ''),
        }];
    }

    public static function scopeMessage(string $what, string $scope): string
    {
        return 'BenjiPays refused the '.$what.' read (HTTP 403): the BenjiPays API key lacks the '.$scope.' scope. Ask Charlie to add that scope to the key; changing a key is his act. (BenjiPays also answers 403 when the key owner lacks the companyAdmin mapping or billing has lapsed.)';
    }
}
