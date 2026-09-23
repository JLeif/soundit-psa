<?php

namespace App\Services\Hdb;

/**
 * The end user's redaction choices for one press, read from `ticket.json`.
 *
 * FIELD SOURCE — and its honest limit, per STANDARDS C-56(1). The two keys read
 * here are `redactDiagnostic` and `redactScreenshots`, named in the household
 * vault plan `wiki/sound-psa/soundit-dev/plans/`
 * `2026-09-04-hdb-report-integration-build-guide.md` §6a, which records them
 * from a live capture of a real press on 2026-09-04 (marked `[VERIFIED keys]`
 * there). HDB is not open source and publishes no
 * OpenAPI spec, so that note is the best producer available and it is a
 * SECOND-HAND TRANSCRIPTION, not a captured payload: the key NAMES are cited,
 * the value TYPES are not independently attested, and nothing in this repo has
 * ever seen the real bytes. What follows is written so that being wrong about
 * the types costs a refusal rather than a leak.
 *
 * FAIL CLOSED ON AN UNREADABLE FLAG, and note which direction that is. An
 * absent or non-boolean flag does NOT mean "redaction was not requested" — it
 * means this client cannot tell. {@see fromTicket()} returns null for that
 * case, and the client turns null into a refusal
 * ({@see HdbReportResult::REASON_REDACTION_FLAG_UNREADABLE}): no diagnostics
 * and no screenshot are written. Defaulting the flags to false — the shape a
 * `(bool) ($ticket['redactScreenshots'] ?? false)` would produce — would
 * publish a desktop screenshot the end user asked to withhold the first time
 * the vendor renamed a key, and it would do it silently.
 *
 * WHAT IS DELIBERATELY NOT DECIDED HERE. This object says what the end user
 * asked for. WHAT "suppress diagnostic detail" means for a ticket note — which
 * report sections survive a redacted import, and how the note tells a
 * technician that something was withheld — is the import path's decision and
 * is not in this slice. What IS decided here is the one thing that cannot be
 * deferred safely: a redacted screenshot is never FETCHED at all
 * ({@see HdbReportClient}), so the image never enters this process.
 */
final readonly class HdbReportRedaction
{
    private function __construct(
        /** The end user asked that diagnostic detail be limited. */
        public bool $diagnostic,
        /** The end user asked that screenshots not be shown. Never fetched. */
        public bool $screenshots,
    ) {}

    /**
     * Read both flags out of a decoded `ticket.json`, or null when either is
     * not a readable boolean.
     *
     * STRICT on type, on purpose. JSON booleans decode to PHP booleans, so a
     * `ticket.json` that says `true` arrives as `true`; anything else — a
     * string `"true"`, `1`, null, absent — is a shape this client has not
     * measured, and guessing which way it points is exactly the guess C-56
     * forbids. Null here is a refusal, never a permissive default.
     *
     * @param  array<string, mixed>  $ticket
     */
    public static function fromTicket(array $ticket): ?self
    {
        $diagnostic = $ticket['redactDiagnostic'] ?? null;
        $screenshots = $ticket['redactScreenshots'] ?? null;

        if (! is_bool($diagnostic) || ! is_bool($screenshots)) {
            return null;
        }

        return new self($diagnostic, $screenshots);
    }

    /** May `screen.png` / `sprite.png` be requested from the portal at all? */
    public function allowsScreenshots(): bool
    {
        return ! $this->screenshots;
    }
}
