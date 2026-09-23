# Unusable transcription enum: code rollback prerequisite

Prepared, not authorization to execute. Production data changes require explicit approval.
Before rolling application code back to a version without `TranscriptionStatus::Unusable`:

1. Stop transcription producers/workers and prevent new transcription requests for the
   entire data-revert AND code-rollback window. Do not merely count while writers run.
2. In one database session start a transaction and record
   `SELECT COUNT(*) FROM phone_calls WHERE transcription_status = 'unusable';` as N.
3. Execute the single UPDATE in `rollback-unusable-transcripts.sql`.
4. Immediately read `SELECT ROW_COUNT();` (MariaDB) and require exactly N; then require
   the unusable count is zero. If either differs, ROLLBACK and investigate. Otherwise COMMIT.
5. Roll back code, verify it can hydrate the call rows, then resume producers.

This compatibility downgrade deliberately loses the enum's attention state. It preserves
raw transcript, timestamps, error/listen marker and every other column; it neither
retranscribes nor backfills historical completed rows. Old code can again hydrate every
status. Do not clear the warning or launch intake jobs as part of this revert.

`UnusableTranscriptRollbackTest` executes this exact SQL file against mixed fixture rows,
checks affected count, preservation of all other columns, zero remaining unusable values,
and idempotence (second execution affects zero). This is a local test, not a production
receipt. Count/check/transaction and producer quiescence are operational prerequisites.
