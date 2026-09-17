<?php

namespace App\Services\BenjiPays;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Mint-or-reuse an applied payment link for one invoice (#2065).
 *
 * The minted link is cached per invoice so a client clicking Pay Online
 * twice, or opening it from the dashboard and then the invoice page, causes
 * one vendor mint, not several. TTL is the vendor's `expiresAt` minus a
 * 60-second safety margin, capped at one hour; a link the vendor says is
 * already (or nearly) expired is not cached at all. The cache is keyed on
 * the invoice row id AND its QBO id, so re-linking an invoice to a different
 * accounting invoice can never serve a link minted for the old one.
 */
class BenjiPaysPayOnline
{
    public const CACHE_PREFIX = 'benjipays:applied-link:';

    public const MAX_TTL_SECONDS = 3600;

    public const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(private BenjiPaysClient $client) {}

    /**
     * @throws BenjiPaysException on any mint failure (never a vendor string)
     */
    public function linkFor(Invoice $invoice): AppliedPaymentLink
    {
        $qboId = (string) $invoice->qbo_invoice_id;
        if (trim($qboId) === '') {
            throw new BenjiPaysException('invalid_id');
        }

        $key = self::CACHE_PREFIX.$invoice->getKey().':'.sha1($qboId);

        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['url'], $cached['expiresAt'])
            && is_string($cached['url']) && is_string($cached['expiresAt'])) {
            try {
                $link = AppliedPaymentLink::fromResponse($cached);
                if ($link->expiresAt->isFuture()) {
                    return $link;
                }
            } catch (BenjiPaysException) {
                // A malformed cache row is discarded, never served.
            }
        }

        $link = $this->client->createAppliedPaymentLink($qboId);

        $ttl = min(
            self::MAX_TTL_SECONDS,
            $link->expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp() - self::EXPIRY_MARGIN_SECONDS,
        );
        if ($ttl > 0) {
            Cache::put($key, ['url' => $link->url, 'expiresAt' => $link->expiresAt->toIso8601ZuluString()], $ttl);
        }

        return $link;
    }
}
