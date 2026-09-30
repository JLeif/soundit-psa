<?php

namespace App\Services\BenjiPays;

/**
 * One invoice's auto-processing forecast, REDACTED to what proves whether it
 * will be auto-charged and why (card revwQxh4).
 *
 * Shape source: developer.benjipays.com/reference/get_v2-autoprocessing-forecast
 * (OpenAPI 3.1, read 2026-09-30). Response `ListAutoprocessingForecastResponse`
 * = {data: AutoprocessingForecastItem[], pagination: {...}}, both required,
 * additionalProperties false. Every item field is REQUIRED by the schema:
 *   id (string|null, accounting invoice id), invoiceNumber (string|null),
 *   customerId, customerName, currency, dueDate (string|null, YYYY-MM-DD),
 *   amountToCharge (number|null), willBeCharged, dueDateMet,
 *   autoProcessingEnabled, hasEnabledProfile, hasInstallments, startDateMet,
 *   memoSkip, amountSkip (bool), installmentDateMet (bool|null),
 *   reasons (string[], "human-readable reasons explaining the forecast outcome").
 *
 * KEPT: id, invoiceNumber, dueDate, willBeCharged, dueDateMet, memoSkip,
 * amountSkip, reasons. DROPPED, never copied out: customerId, customerName,
 * currency, amountToCharge, hasEnabledProfile (whether the customer has a
 * saved payment method), autoProcessingEnabled, installment fields and the
 * whole pagination block. The schema has no payment-term flag: a term skip is
 * reported only in `reasons`, so the reasons are the proof and are kept,
 * bounded (count, length, control characters) and with any 12+ digit run
 * masked, since they are vendor prose this code cannot vouch for.
 *
 * FAILS CLOSED (STANDARDS C-56): a body that is not the documented envelope,
 * an item missing a field this class reads or carrying the wrong type, more
 * than one item, or an item for a different invoice id all throw
 * `invalid_response`. An empty `data` list is the one clean negative, and it
 * means "not in the forecast" (the vendor forecasts open invoices only), never
 * "skipped".
 */
final readonly class AutoprocessingForecast
{
    private const MAX_REASONS = 20;

    private const MAX_REASON_LENGTH = 300;

    /** @param list<string> $reasons */
    private function __construct(
        public bool $found,
        public ?string $invoiceId,
        public ?string $invoiceNumber,
        public ?string $dueDate,
        public ?bool $willBeCharged,
        public ?bool $dueDateMet,
        public ?bool $memoSkip,
        public ?bool $amountSkip,
        public array $reasons,
    ) {}

    public static function fromResponse(string $body, string $requestedInvoiceId): self
    {
        $shape = json_decode($body);
        $decoded = json_decode($body, true);
        if (! is_object($shape) || ! property_exists($shape, 'data') || ! is_array($shape->data)
            || ! property_exists($shape, 'pagination') || ! is_object($shape->pagination)
            || ! is_array($decoded) || ! is_array($decoded['data'] ?? null) || ! array_is_list($decoded['data'])) {
            throw new BenjiPaysException('invalid_response');
        }

        $items = $decoded['data'];
        if ($items === []) {
            return new self(false, null, null, null, null, null, null, null, []);
        }
        if (count($items) !== 1 || ! is_array($items[0]) || array_is_list($items[0])) {
            throw new BenjiPaysException('invalid_response');
        }
        $item = $items[0];

        foreach (['id', 'invoiceNumber', 'dueDate'] as $key) {
            if (! array_key_exists($key, $item) || ! (is_string($item[$key]) || $item[$key] === null)) {
                throw new BenjiPaysException('invalid_response');
            }
        }
        foreach (['willBeCharged', 'dueDateMet', 'memoSkip', 'amountSkip'] as $key) {
            if (! array_key_exists($key, $item) || ! is_bool($item[$key])) {
                throw new BenjiPaysException('invalid_response');
            }
        }
        if (! array_key_exists('reasons', $item) || ! is_array($item['reasons']) || ! array_is_list($item['reasons'])) {
            throw new BenjiPaysException('invalid_response');
        }
        // The item must be the invoice we asked about; a forecast for another
        // invoice answering this question would be a false proof.
        if ($item['id'] !== $requestedInvoiceId) {
            throw new BenjiPaysException('invalid_response');
        }
        if ($item['dueDate'] !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item['dueDate'])) {
            throw new BenjiPaysException('invalid_response');
        }

        $reasons = [];
        foreach ($item['reasons'] as $reason) {
            if (! is_string($reason)) {
                throw new BenjiPaysException('invalid_response');
            }
            if (count($reasons) < self::MAX_REASONS) {
                $reasons[] = self::boundedReason($reason);
            }
        }

        return new self(
            true,
            $item['id'],
            is_string($item['invoiceNumber']) ? self::boundedReason($item['invoiceNumber']) : null,
            $item['dueDate'],
            $item['willBeCharged'],
            $item['dueDateMet'],
            $item['memoSkip'],
            $item['amountSkip'],
            $reasons,
        );
    }

    /** Vendor prose, bounded: control characters to spaces, 12+ digit runs masked, length capped. */
    private static function boundedReason(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new BenjiPaysException('invalid_response');
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        $text = (string) preg_replace('/\d(?:[ -]?\d){11,}/u', '[redacted number]', $text);

        return mb_strlen($text) > self::MAX_REASON_LENGTH
            ? mb_substr($text, 0, self::MAX_REASON_LENGTH).'…'
            : $text;
    }
}
