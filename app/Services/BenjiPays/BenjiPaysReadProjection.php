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
     * paymentsToMake[].invoiceId/invoiceNumber/amount, at most MAX_APPLIED of
     * them; applied_to_count is the vendor's full paymentsToMake count and
     * applied_to_truncated is true when entries were left out, so a long list
     * is never silently cut). DROPPED: customerName,
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
            $appliedTotal = 0;
            $toMake = $row['paymentsToMake'] ?? null;
            if ($toMake !== null) {
                if (! is_array($toMake) || ! array_is_list($toMake)) {
                    throw new BenjiPaysException('invalid_response');
                }
                $appliedTotal = count($toMake);
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
                'applied_to_count' => $appliedTotal,
                'applied_to_truncated' => $appliedTotal > self::MAX_APPLIED,
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

    /** The documented `type` enum of GET /v2/emails (merchant-facing types only). */
    public const EMAIL_TYPES = ['invoice', 'receipt', 'cardrequest', 'emailreminder', 'generalemail', 'logininvite',
        'installment', 'security', 'nightlyresults', 'benjiinvoice', 'other', 'invoicepaid', 'emailverify'];

    /** The documented `status` enum of GET /v2/emails. */
    public const EMAIL_STATUSES = ['sent', 'queued', 'error'];

    private const MAX_RECIPIENTS = 10;

    /**
     * GET /v2/emails item (EmailSummary; required: id, type, status, subject,
     * from, to, cc, bcc, sentDate, opened, lastOpened, bounced, delivered,
     * customerId, invoiceIds, sentBy, reminderRule, hasAttachments). KEPT, as
     * ruled by Jeeves 2026-10-01: sentDate, type (documented enum only),
     * status (documented enum only) and each `to` recipient MASKED to the first
     *
     * character of the local part + `***@` + the full domain, at most
     * MAX_RECIPIENTS of them (recipient_count is the full count and
     * recipients_truncated says when some were left out). DROPPED: id,
     * subject, from, cc, bcc, sentBy, reminderRule, invoiceIds, the
     * opened/lastOpened/bounced/delivered flags, hasAttachments.
     *
     * Every row must carry customerId equal to the customer the read was
     * filtered to; a row for another or no customer fails the whole read. A
     * recipient that is not a plain address, or a type/status outside the
     * documented enums, fails the whole read too (never guessed).
     *
     * @param  array{data: list<mixed>, pagination: array<string, mixed>}  $envelope
     * @return array{rows: list<array<string, mixed>>, has_more: bool, total: int|float|null}
     */
    public static function emails(array $envelope, string $customerId): array
    {
        $rows = [];
        foreach ($envelope['data'] as $row) {
            if (! is_array($row) || array_is_list($row) || ! is_string($row['id'] ?? null)
                || ($row['customerId'] ?? null) !== $customerId
                || ! is_array($row['to'] ?? null) || ! array_is_list($row['to'])
                || ! array_key_exists('type', $row) || ! array_key_exists('status', $row) || ! array_key_exists('sentDate', $row)
                || ($row['type'] !== null && ! in_array($row['type'], self::EMAIL_TYPES, true))
                || ($row['status'] !== null && ! in_array($row['status'], self::EMAIL_STATUSES, true))) {
                throw new BenjiPaysException('invalid_response');
            }
            $recipients = [];
            foreach (array_slice($row['to'], 0, self::MAX_RECIPIENTS) as $address) {
                $recipients[] = self::maskEmail($address);
            }
            $rows[] = [
                'date' => self::isoDate($row, 'sentDate'),
                'type' => $row['type'],
                'status' => $row['status'],
                'recipients' => $recipients,
                'recipient_count' => count($row['to']),
                'recipients_truncated' => count($row['to']) > self::MAX_RECIPIENTS,
            ];
        }

        return ['rows' => $rows] + self::page($envelope['pagination']);
    }

    /**
     * `j***@example.com`: the first character of the local part, `***`, and
     * the full domain (Jeeves's ruling, card 6abec4f9, 2026-10-01). Anything
     * that is not one plain ASCII address (a display name, two `@`, an empty
     * local part, a domain without a dot) fails closed.
     */
    public static function maskEmail(mixed $address): string
    {
        if (! is_string($address) || strlen($address) > 254
            || ! preg_match('/^([A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-])[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]{0,63}@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)$/D', $address, $m)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $m[1].'***@'.$m[2];
    }

    /** Every key GET /v2/settings documents under autoProcessing and its skips (additionalProperties: false). */
    private const AUTO_PROCESSING_KEYS = ['enabled', 'runHour', 'delayDays', 'startDate', 'processCreditMemos', 'useParentProfiles', 'skips'];

    private const SKIP_KEYS = ['noTermsDisabled', 'skipDueDateNotMet', 'memoSkip', 'skipSurcharge', 'autoProcessAmountSkip', 'invoicePrefixSkip'];

    /**
     * GET /v2/settings `data` (OrganizationSettingsResponse; required blocks
     * accountingSystem, autoProcessing, customerPortal, email, security). Only
     * the non-secret configuration that explains autopay, skip and surcharge
     * behaviour is KEPT: every autoProcessing flag, number and skip rule; the
     * accountingSystem auto-enable flags; the customerPortal payment and
     * autopay flags; the two receipt flags of `email`. The free text of a
     * rule is never copied out: memoSkip.text becomes `text_set` plus the
     * caller-supplied comparison, and invoicePrefixSkip.prefixes becomes
     * `prefix_count`. DROPPED: every address list (invoice/receipt cc/bcc),
     * portal url, customDomain, name, theme and pre-authorization agreement
     * texts, showInvoicesAfterDate, the security block and useSmtp.
     *
     * FAIL CLOSED: a missing block, a kept key missing or of another type, or
     * ANY key in autoProcessing or its skips that the documentation does not
     * list throws `invalid_response`. An undocumented skip rule would make the
     * reported skip list incomplete, which would read as "nothing else skips".
     *
     * @param  ?string  $psaMemo  the PSA's configured skip memo, already folded and trimmed; null when none
     * @return array<string, mixed>
     */
    public static function settings(array $data, ?string $psaMemo): array
    {
        foreach (['accountingSystem', 'autoProcessing', 'customerPortal', 'email', 'security'] as $block) {
            if (! is_array($data[$block] ?? null) || array_is_list($data[$block])) {
                throw new BenjiPaysException('invalid_response');
            }
        }
        $ap = self::exactKeys($data['autoProcessing'], self::AUTO_PROCESSING_KEYS);
        $skips = self::exactKeys($ap['skips'], self::SKIP_KEYS);
        $memo = $skips['memoSkip'] === null ? null : self::exactKeys($skips['memoSkip'], ['text', 'action']);
        // Compared, never copied out, so a multi-line wording is accepted here
        // (the PSA's own wording may span lines); bounded and UTF-8 only.
        $memoText = $memo['text'] ?? null;
        if ($memoText !== null && (! is_string($memoText) || strlen($memoText) > 5000 || ! mb_check_encoding($memoText, 'UTF-8'))) {
            throw new BenjiPaysException('invalid_response');
        }
        $prefix = self::exactKeys($skips['invoicePrefixSkip'], ['enabled', 'prefixes', 'action']);
        if (! is_array($prefix['prefixes']) || ! array_is_list($prefix['prefixes'])) {
            throw new BenjiPaysException('invalid_response');
        }
        $portal = $data['customerPortal'];
        $acct = $data['accountingSystem'];
        $email = $data['email'];

        return [
            'auto_processing' => [
                'enabled' => self::bool($ap, 'enabled'),
                'run_hour' => self::number($ap, 'runHour'),
                'delay_days' => self::number($ap, 'delayDays'),
                'start_date' => self::isoDate($ap, 'startDate'),
                'process_credit_memos' => self::bool($ap, 'processCreditMemos'),
                'use_parent_profiles' => self::bool($ap, 'useParentProfiles'),
            ],
            'skips' => [
                'no_terms_disabled' => self::bool($skips, 'noTermsDisabled'),
                'skip_due_date_not_met' => self::bool($skips, 'skipDueDateNotMet'),
                'memo_skip' => $memo === null ? null : [
                    'text_set' => $memoText !== null && trim($memoText) !== '',
                    'text_equals_psa_skip_memo' => $psaMemo === null || $memoText === null ? null : trim($memoText) === $psaMemo,
                    'action' => self::word($memo, 'action'),
                ],
                'skip_surcharge' => self::range($skips['skipSurcharge']),
                'amount_skip' => self::range($skips['autoProcessAmountSkip']),
                'invoice_prefix_skip' => [
                    'enabled' => self::bool($prefix, 'enabled'),
                    'prefix_count' => count($prefix['prefixes']),
                    'action' => self::word($prefix, 'action'),
                ],
            ],
            'accounting_system' => [
                'surcharge_auto_enable' => self::bool($acct, 'surchargeAutoEnable'),
                'auto_enable_new_customers' => self::bool($acct, 'autoEnableNewCustomers'),
                'email_auto_enable' => self::bool($acct, 'emailAutoEnable'),
            ],
            'customer_portal' => [
                'enabled' => self::bool($portal, 'enabled'),
                'allow_portal_change_autopay' => self::bool($portal, 'allowPortalChangeAutoPay'),
                'auto_enable_profiles' => self::bool($portal, 'autoEnableProfiles'),
                'force_save_cards' => self::bool($portal, 'forceSaveCards'),
                'disable_save_cards' => self::bool($portal, 'disableSaveCards'),
                'disable_partial_payments' => self::bool($portal, 'disablePartialPayments'),
            ],
            'email' => [
                'send_receipts' => self::bool($email, 'sendReceipts'),
                'autopay_send_receipts_invoice_emails' => self::bool($email, 'autoPaySendReceiptsInvoiceEmails'),
            ],
        ];
    }

    /** An object holding exactly the documented keys, no more and no fewer. */
    private static function exactKeys(mixed $value, array $keys): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new BenjiPaysException('invalid_response');
        }
        $have = array_keys($value);
        sort($have);
        sort($keys);
        if ($have !== $keys) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
    }

    /** @return array{enabled: bool, min: int|float|null, max: int|float|null} */
    private static function range(mixed $value): array
    {
        $value = self::exactKeys($value, ['enabled', 'min', 'max']);

        return ['enabled' => self::bool($value, 'enabled'), 'min' => self::nullableNumber($value, 'min'), 'max' => self::nullableNumber($value, 'max')];
    }

    private static function bool(array $row, string $key): bool
    {
        if (! is_bool($row[$key] ?? null)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $row[$key];
    }

    private static function number(array $row, string $key): int|float
    {
        $value = self::nullableNumber($row, $key);
        if ($value === null) {
            throw new BenjiPaysException('invalid_response');
        }

        return $value;
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

    /**
     * The TRAILING four visible digits of an already-masked number; never the
     * masked string. A mask that does not end in four digits gives null (fail
     * closed, #4739): digits anywhere else in a mask may be BIN or routing
     * digits, and must never be reported as the last four.
     */
    private static function last4(?string $masked): ?string
    {
        if ($masked === null || ! preg_match('/(\d{4})$/D', $masked, $m)) {
            return null;
        }

        return $m[1];
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
