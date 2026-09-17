<?php

namespace App\Services\BenjiPays;

use Carbon\CarbonImmutable;

/**
 * One minted applied (invoice-tied) payment link: the vendor's tokenized
 * pay-now URL and its expiry. Nothing else from the response is retained.
 *
 * Shape source: developer.benjipays.com/reference/post_v2-payment-links-applied-invoiceid
 * (OpenAPI `CreateAppliedPaymentLinkResponse`, read 2026-09-17): a BARE object
 * `{url: string, expiresAt: string}`, both required, `additionalProperties: false`.
 * This is NOT the `{data: …}` envelope the stage 1 reads unwrap.
 */
final readonly class AppliedPaymentLink
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * Validate the vendor object into the DTO, or throw `invalid_response`.
     *
     * `url` must be https on a benjipays.com host (the vendor's portal pay
     * page): a 302 to anything else would send a client — and a link that
     * carries their invoice — to whatever host the response named.
     * `expiresAt` must parse as an ISO-8601 timestamp.
     */
    public static function fromResponse(mixed $data): self
    {
        if (! is_array($data) || array_is_list($data)
            || ! isset($data['url'], $data['expiresAt'])
            || ! is_string($data['url']) || ! is_string($data['expiresAt'])) {
            throw new BenjiPaysException('invalid_response');
        }

        $url = $data['url'];
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new BenjiPaysException('invalid_response');
        }
        $parts = parse_url($url);
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        if (! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || ($host !== 'benjipays.com' && ! str_ends_with($host, '.benjipays.com'))) {
            throw new BenjiPaysException('invalid_response');
        }

        // Strict ISO-8601 (RFC 3339 profile: date, time, zone), no free-form parsing.
        if (strlen($data['expiresAt']) > 64
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/D', $data['expiresAt'])) {
            throw new BenjiPaysException('invalid_response');
        }
        try {
            $expiresAt = CarbonImmutable::parse($data['expiresAt']);
        } catch (\Throwable) {
            throw new BenjiPaysException('invalid_response');
        }

        return new self($url, $expiresAt);
    }
}
