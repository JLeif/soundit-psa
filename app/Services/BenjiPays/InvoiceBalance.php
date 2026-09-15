<?php

namespace App\Services\BenjiPays;

/** Only the balance projection; no customer data or raw vendor payload is retained. */
final readonly class InvoiceBalance
{
    public function __construct(
        public ?int $balanceCents,
        public ?string $status,
        public ?string $currency,
        public ?string $reason = null,
    ) {}

    public static function fromInvoice(array $data): self
    {
        $balance = $data['balance'] ?? null;
        $status = $data['status'] ?? null;
        $currency = $data['currency'] ?? null;
        if (! in_array($status, ['open', 'paid', 'overdue'], true)
            || ($currency !== null && (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)))) {
            return new self(null, null, null, 'invalid_response');
        }
        if (! array_key_exists('balance', $data)) {
            return new self(null, $status, $currency, 'invalid_balance');
        }
        if ($balance === null) {
            return new self(null, $status, $currency, 'balance_unavailable');
        }
        // JSON numbers only. Convert to minor units through a fixed two-digit decimal
        // rendering, never a (string) cast: that cast formats with the `precision` ini
        // directive, so the same valid balance would convert here and be refused on a
        // host configured differently. sprintf('%.2F') is locale- and ini-independent;
        // a value that does not survive the round-trip carries more precision than cents
        // and is refused rather than silently rounded. Magnitude is bounded as before.
        if ((! is_int($balance) && ! is_float($balance)) || ! is_finite((float) $balance)) {
            return new self(null, $status, $currency, 'invalid_balance');
        }
        $decimal = sprintf('%.2F', $balance);
        if ((float) $decimal !== (float) $balance
            || ! preg_match('/^(-?)(\d{1,12})\.(\d{2})$/D', $decimal, $parts)) {
            return new self(null, $status, $currency, 'invalid_balance');
        }
        $cents = ((int) $parts[2] * 100 + (int) $parts[3]) * ($parts[1] === '-' ? -1 : 1);

        return new self($cents, $status, $currency);
    }
}
