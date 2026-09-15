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
        // JSON numbers only. Convert the shortest decimal representation to minor units,
        // not a binary-float comparison/multiplication. Refuse excess precision/overflow.
        if ((! is_int($balance) && ! is_float($balance)) || ! is_finite((float) $balance)) {
            return new self(null, $status, $currency, 'invalid_balance');
        }
        $decimal = (string) $balance;
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', $decimal, $parts)) {
            return new self(null, $status, $currency, 'invalid_balance');
        }
        $cents = ((int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0')) * ($parts[1] === '-' ? -1 : 1);

        return new self($cents, $status, $currency);
    }
}
