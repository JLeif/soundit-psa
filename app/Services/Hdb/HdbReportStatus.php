<?php

namespace App\Services\Hdb;

/**
 * The whole vocabulary one HelpDesk Buttons press-report fetch may report.
 *
 * Closed on purpose, for the same reason {@see HdbAuthStatus} is: everything
 * behind `gatekeeper_auth.php` is vendor-controlled bytes, and a technician-
 * facing surface may not carry them. Only one of these symbols, plus the
 * closed reason list on {@see HdbReportResult}, leaves the client.
 *
 * DEGRADED is the case this enum exists for. docs/ARCHITECTURE.md § "Vendor
 * response shapes" rule 3 forbids a degraded read from returning a clean empty
 * result: a `report.json` that arrived without a section this integration was
 * built to read is a BUG, not "that machine had no event log". So the payload
 * is still handed back — dropping it would be its own data loss — carried by a
 * status that is not Fetched and a reason that NAMES the missing sections.
 * A caller that treats Degraded as Fetched is writing a silent false all-clear
 * onto a ticket.
 */
enum HdbReportStatus: string
{
    /** Everything asked for arrived and was readable. */
    case Fetched = 'fetched';

    /**
     * The fetch succeeded but what came back is not whole — see the class
     * docblock. The payload is present; the reason names what is missing.
     */
    case Degraded = 'degraded';

    /**
     * `ticket.json` says `uploadComplete: false`. The press is still uploading,
     * so `report.json` was NOT fetched and nothing may be imported yet. This is
     * the one outcome that is expected to succeed on a later attempt.
     */
    case Incomplete = 'incomplete';

    /**
     * {@see HdbReportFetchAuthorizer} refused. No request was issued: the gate
     * runs before the session, not after it.
     */
    case Refused = 'refused';

    /** The portal session could not be established — see the auth reason. */
    case Unauthenticated = 'unauthenticated';

    /** Transport, redirect policy or request budget. Nothing was learned. */
    case Unreachable = 'unreachable';

    /**
     * The portal answered, and what it answered is not a shape this client can
     * read. Never treated as "no data" — fail closed, and name it.
     */
    case Malformed = 'malformed';
}
