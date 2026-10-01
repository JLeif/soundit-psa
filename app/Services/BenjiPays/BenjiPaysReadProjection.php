<?php

namespace App\Services\BenjiPays;

/**
 * Validation and REDACTION for the read-only BenjiPays MCP tools (card
 * 6abec4f9). Each method takes the vendor's documented shape and returns
 * an allow-list of fields. Nothing else is copied out. Any shape outside
 * the documentation throws `invalid_response` (STANDARDS C-56: fail closed,
 * never guess).
 *
 * Shape sources (OpenAPI 3.1 on developer.benjipays.com/reference, read
 * 2026-10-01): get_v2-transactions, get_v2-payment-methods,
 * get_v2-invoices-invoiceid.
 */
final class BenjiPaysReadProjection
{
    private const MAX_APPLIED = 20;

    /**
     * GET /v2/transactions item (TransactionListItem; required: id, details,
     * settlementDate, settlementEligible, settlementStatus; everything else
     * optional and nullable). KEPT: transactionDate, type, status, approved,
     * amount, currency, surchargeAmount, surchargeRate, paymentType mapped to
     * card/bank, the last four digits of details.maskedPan (card) or
     * details.account (bank), and the invoice refs (invoiceId, invoiceNumber,
     * paymentsToMake[].invoiceId/invoiceNumber/amount). DROPPED: customerName,
     * details.message/receiptNumber/paymentRef/cardType, the masked numbers
     * themselves, result (raw gateway string), gateway and settlement fields,
     * accounting payment and journal ids, integrationData, void/refund blocks.
     *
     * Every row must carry customerId equal to the customer the read was
     * filtered to; a row for any other or no customer fails the whole read.
     *
     * @param  array{data: list<mixed>, pagination: array<string, mixed>}  $envelope
     * @return array{rows: list<array<string, mixed>>, has_more: bool, total: int|float|null}
     */
    public static function transactions(array $envelope, string $customerId): array
    {
        $rows = [];
        foreach ($envelope['data'] as $row) {
            if (! is_array($row) || array_is_list($row) || ! is_string($row['id'] ?? null)
                || ! is_array($row['details'] ?? null) || ($row['customerId'] ?? null) !== $customerId) {
                throw new BenjiPaysException('invalid_response');
            }
            $type = self::nullableString($row, 'paymentType');
            $details = $row['details'];
            $masked = match ($type) {
                'cc' => self::nullableString($details, 'maskedPan'),
                'bank' => self::nullableString($details, 'account'),
                default => null,
            };
            $applied = [];
            $toMake = $row['paymentsToMake'] ?? null;
            if ($toMake !== null) {
                if (! is_array($toMake) || ! array_is_list($toMake)) {
                    throw new BenjiPaysException('invalid_response');
                }
                foreach (array_slice($toMake, 0, self::MAX_APPLIED) as $p) {
                    if (! is_array($p)) {
                        throw new BenjiPaysException('invalid_response');
                    }
                    $applied[] = [
                        'invoice_id' => self::nullableString($p, 'invoiceId'),
                        'invoice_number' => self::nullableString($p, 'invoiceNumber'),
                        'amount' => self::nullableNumber($p, 'amount'),
                    ];
                }
            }
            $rows[] = [
                'date' => self::isoDate($row, 'transactionDate'),
                'type' => self::word($row, 'type'),
                'status' => self::word($row, 'status'),
                'approved' => self::nullableBool($row, 'approved'),
                'amount' => self::nullableNumber($row, 'amount'),
                'currency' => self::currency($row),
                'surcharge_amount' => self::nullableNumber($row, 'surchargeAmount'),
                'surcharge_rate' => self::nullableNumber($row, 'surchargeRate'),
                'method_type' => self::methodType($type),
                'last4' => self::last4($masked),
                'invoice_id' => self::nullableString($row, 'invoiceId'),
                'invoice_number' => self::nullableString($row, 'invoiceNumber'),
                'applied_to' => $applied,
            ];
        }

        return ['rows' => $rows] + self::page($envelope['pagination']);
    }

    /**
     * GET /v2/payment-methods item (PaymentMethodListItem; required: id,
     * paymentType, autoPayEnabled, benjiSurchargeEnabled, declineCount).
     * KEPT: paymentType mapped to card/bank, cardBrand, last four of maskedPan,
     * expiryMonth/expiryYear, autoPayEnabled, autoPayPriority. The schema has
     * NO default-method field; autoPayPriority ("processing priority when
     * multiple methods exist") is passed through as itself, never relabelled
     * as a default. DROPPED: id, gatewayCustomerId, gateway ids, customerName,
     * maskedPan itself, note, creatorIpAddress, decline fields, dates.
     *
     * customerId is optional in the schema but REQUIRED here: a row the read
     * cannot tie to the mapped customer fails the whole read.
     *
     * @param  array{data: list<mixed>, pagination: array<string, mixed>}  $envelope
     * @return array{rows: list<array<string, mixed>>, has_more: bool, total: int|float|null}
     */
    public static function paymentMethods(array $envelope, string $customerId): array
    {
        $rows = [];
        foreach ($envelope['data'] as $row) {
            if (! is_array($row) || array_is_list($row) || ! is_string($row['id'] ?? null)
                || ($row['customerId'] ?? null) !== $customerId
                || ! is_bool($row['autoPayEnabled'] ?? null) || ! is_string($row['paymentType'] ?? null)) {
                throw new BenjiPaysException('invalid_response');
            }
            $card = $row['paymentType'] === 'cc';
            $rows[] = [
                'method_type' => self::methodType($row['paymentType']),
                'brand' => $card ? self::brand($row) : null,
                'last4' => self::last4(self::nullableString($row, 'maskedPan')),
                'expiry_month' => $card ? self::digits($row, 'expiryMonth', 1, 2) : null,
                'expiry_year' => $card ? self::digits($row, 'expiryYear', 2, 4) : null,
                'autopay_enabled' => $row['autoPayEnabled'],
                'autopay_priority' => self::nullableNumber($row, 'autoPayPriority'),
            ];
        }

        return ['rows' => $rows] + self::page($envelope['pagination']);
    }

    /**
     * GET /v2/invoices/{invoiceId} `data` (InvoiceSummary; required include
     * id, invoiceNumber, invoiceDate, dueDate, total, balance, subtotal,
     * taxTotal, currency, status enum open|paid|overdue, paid, terms,
     * customer). KEPT: those money/date/status fields and terms.name. DROPPED:
     * customer (name, parents), memo, reference, brandingTheme, emailed,
     * timestamps and any `accounting` block (never requested).
     *
     * FENCE: data.id must be the invoice asked for and data.customer.id the
     * mapped customer; a null customer cannot be verified and fails closed.
     *
     * @return array<string, mixed>
     */
    public static function invoice(array $data, string $invoiceId, string $customerId): array
    {
        $customer = $data['customer'] ?? null;
        if (($data['id'] ?? null) !== $invoiceId || ! is_array($customer) || ($customer['id'] ?? null) !== $customerId
            || ! in_array($data['status'] ?? null, ['open', 'paid', 'overdue'], true) || ! is_bool($data['paid'] ?? null)) {
            throw new BenjiPaysException('invalid_response');
        }
        foreach (['total', 'balance', 'subtotal', 'taxTotal', 'invoiceNumber', 'invoiceDate', 'dueDate', 'currency', 'terms'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new BenjiPaysException('invalid_response');
            }
        }
        $terms = $data['terms'];
        if ($terms !== null && ! is_array($terms)) {
            throw new BenjiPaysException('invalid_response');
        }

        return [
            'invoice_number' => self::nullableString($data, 'invoiceNumber'),
            'invoice_date' => self::isoDate($data, 'invoiceDate'),
            'due_date' => self::isoDate($data, 'dueDate'),
            'status' => $data['status'],
            'paid' => $data['paid'],
            'total' => self::nullableNumber($data, 'total'),
            'balance' => self::nullableNumber($data, 'balance'),
            'subtotal' => self::nullableNumber($data, 'subtotal'),
            'tax_total' => self::nullableNumber($data, 'taxTotal'),
            'currency' => self::currency($data),
            'terms' => $terms === null ? null : self::nullableString($terms, 'name'),
        ];
    }

    /** @return array{has_more: bool, total: int|float|null} */
    private static function page(array $pagination): array
    {
        if (! is_bool($pagination['hasMore'] ?? null)
            || ! (is_int($pagination['total'] ?? null) || is_float($pagination['total'] ?? null))) {
            throw new BenjiPaysException('invalid_response');
        }

        return ['has_more' => $pagination['hasMore'], 'total' => $pagination['total']];
    }

    /** Documented paymentType values are cc and bank ("e.g."); anything else fails closed. */
    private static function methodType(?string $type): ?string
    {
        return match ($type) {
            'cc' => 'card',
            'bank' => 'bank',
            null => null,
            default => throw new BenjiPaysException('invalid_response'),
        };
    }

    /** Only the last four digits of an already-masked number; never the masked string. */
    private static function last4(?string $masked): ?string
    {
        if ($masked === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $masked);

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    private static function brand(array $row): ?string
    {
        $brand = self::nullableString($row, 'cardBrand');
        if ($brand !== null && ! preg_match('/^[A-Za-z][A-Za-z .&-]{0,29}$/D', $brand)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $brand;
    }

    private static function digits(array $row, string $key, int $min, int $max): ?string
    {
        $value = self::nullableString($row, $key);
        if ($value !== null && ! preg_match('/^\d{'.$min.','.$max.'}$/D', $value)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    /** A short status/type word (approved, approved_void_pending, refund, ...). */
    private static function word(array $row, string $key): ?string
    {
        $value = self::nullableString($row, $key);
        if ($value !== null && ! preg_match('/^[A-Za-z][A-Za-z0-9_ -]{0,39}$/D', $value)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    private static function isoDate(array $row, string $key): ?string
    {
        $value = self::nullableString($row, $key);
        if ($value !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T[0-9:.]{5,15}(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    private static function currency(array $row): ?string
    {
        $value = self::nullableString($row, 'currency');
        if ($value !== null && ! preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    /** Absent or null is null; any other non-string fails closed. Bounded and control-free. */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    private static function nullableNumber(array $row, string $key): int|float|null
    {
        $value = $row[$key] ?? null;
        if ($value !== null && ! is_int($value) && ! (is_float($value) && is_finite($value))) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    private static function nullableBool(array $row, string $key): ?bool
    {
        $value = $row[$key] ?? null;
        if ($value !== null && ! is_bool($value)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }
}
