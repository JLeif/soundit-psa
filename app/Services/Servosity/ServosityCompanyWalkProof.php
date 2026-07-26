<?php

namespace App\Services\Servosity;

/**
 * Completeness proof for one FULL companies/summary-ng/ page walk
 * (psa-ou9pe fix-forward, review finding 2 on the psa-z30dv footprint).
 *
 * A finished walk is not yet a COMPLETE walk: the documented null cursor
 * proves only that the server stopped paginating, not that every company the
 * envelope declared was actually delivered. Both full-walk consumers read
 * completeness as a truth claim — ServosityClient::getCompanies() feeds
 * deactivateMissingClients(), where a company missing from a "complete" list
 * has its licenses zeroed and suspended with a fresh stamp, and
 * ServosityReadOnlyToolset::fetchAllSummaryRows() mints company_not_found
 * from absence — so each of these must DRIFT before any consumer can act,
 * never read as "that was the whole list":
 *
 *  - an EARLY NULL that leaves declared companies undelivered
 *    ({"count":2,"next":null,"results":[one row]});
 *  - a declared count that CHANGES between pages of one walk (the list
 *    mutated mid-walk, or the server answered inconsistently — either way
 *    the walk is not a consistent snapshot; the remedy is the next sync);
 *  - a DUPLICATE company id (the duplicate stands exactly where an unseen
 *    company should be, so a row-count reconciliation alone would pass while
 *    absence can no longer be told from omission).
 *
 * ONE instance accompanies one walk, and BOTH walks share this class so the
 * proof cannot fork (the same shared-seam posture as
 * ServosityShapes::provenNextUrl()). absorbPage() runs on every envelope
 * AFTER ServosityShapes::assertDrfEnvelope() (count already proven integer,
 * results a list); assertAccountedComplete() runs ONLY at the proven null
 * cursor. The DRF `count` is the documented declared total for the whole
 * list (official OpenAPI, responses.200 envelope for both list endpoints) —
 * identical on every page of one consistent walk, which is what makes the
 * reconciliation below meaningful.
 *
 * Messages name the seam and the violated expectation only — never vendor
 * values (psa-z30dv.6/.14: ids and counts are vendor data and stay out of
 * drift logs).
 */
final class ServosityCompanyWalkProof
{
    private ?int $declaredCount = null;

    /** @var array<int, true> */
    private array $seenCompanyIds = [];

    public function __construct(private readonly string $endpoint) {}

    /**
     * Absorb one envelope-proven page: the declared count must not change
     * between pages, every row must be an object carrying the documented
     * integer id, and no id may repeat across the walk.
     */
    public function absorbPage(\stdClass $response): void
    {
        if ($this->declaredCount === null) {
            $this->declaredCount = $response->count;
        } elseif ($response->count !== $this->declaredCount) {
            throw new ServosityShapeDriftException(
                "Servosity {$this->endpoint} declared a different total count on a later page of the same walk — "
                .'an inconsistent declaration can never prove the walk delivered the whole list, so no completeness, '
                .'company-absence, or deactivation decision can be made from it.'
            );
        }

        foreach ($response->results as $row) {
            if (! $row instanceof \stdClass || ! is_int($row->id ?? null)) {
                throw new ServosityShapeDriftException(
                    "Servosity {$this->endpoint} returned a row that is not an object with an integer id (documented CompanySummaryNg shape)."
                );
            }
            if (isset($this->seenCompanyIds[$row->id])) {
                throw new ServosityShapeDriftException(
                    "Servosity {$this->endpoint} returned the same company id on two rows of one walk — a duplicate "
                    .'stands in the place of a company the walk never delivered, so absence can no longer be told from '
                    .'omission and no completeness claim can be made.'
                );
            }
            $this->seenCompanyIds[$row->id] = true;
        }
    }

    /**
     * The completeness proof itself, consulted ONLY at the documented null
     * cursor: the walk is complete exactly when the unique companies
     * accumulated equal the declared total. Anything else — an early null
     * with undelivered companies, or more unique rows than declared — is
     * drift for the whole walk.
     */
    public function assertAccountedComplete(): void
    {
        if ($this->declaredCount === null || count($this->seenCompanyIds) !== $this->declaredCount) {
            throw new ServosityShapeDriftException(
                "Servosity {$this->endpoint} pagination ended without delivering the declared number of unique "
                .'companies — an unaccounted walk cannot prove list completeness, so no company-absence or '
                .'deactivation decision can be made from it.'
            );
        }
    }
}
