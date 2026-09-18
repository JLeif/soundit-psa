<?php

namespace App\Services\Technician\Scheduled;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The optional `execute_at` tool parameter (scheduled execution, ruled design point 1).
 *
 * A capability with a scheduled adapter (ActionRegistry adapters: the five mailbox
 * tools and the eight Tactical tools) accepts an ISO-8601 instant WITH an explicit
 * offset, strictly in the future and at most seven days ahead — the same bounds
 * ApprovalWindow enforces — and stages the proposal for the cockpit. The ordinary
 * Approve then admits it through ScheduledAdmission with the derived window
 * [execute_at, execute_at + WINDOW_SECONDS] instead of executing now.
 *
 * Every refusal is NAMED: an unsupported tool, a malformed instant or an out-of-bounds
 * instant is a refused call, never a proposal that silently runs now.
 */
final readonly class ExecuteAt
{
    public const MAX_AHEAD_SECONDS = 604800;

    public const WINDOW_SECONDS = 3600;

    /** The canonical (advertised) tool names whose staged twin has a scheduled adapter. */
    public const CANONICAL_TOOLS = [
        'cipp_set_mailbox_forwarding', 'cipp_set_mailbox_out_of_office', 'cipp_set_mailbox_delegate',
        'cipp_set_mailbox_gal_visibility', 'cipp_convert_mailbox',
        'tactical_run_command', 'tactical_reboot_device', 'tactical_shutdown_device', 'tactical_recover_mesh',
        'tactical_set_maintenance', 'tactical_start_service', 'tactical_stop_service', 'tactical_restart_service',
    ];

    private function __construct(
        /** The instant, normalised to UTC, `Y-m-d\TH:i:sP`. */
        public string $utc,
        /** The offset the caller supplied, kept for the operator-facing "Runs at" line. */
        public string $offset,
        public DateTimeImmutable $at,
        /**
         * Whether this call takes the DIRECT lane (ruled design point 3): the calling token
         * holds `<tool>:immediate`, so it may already run this tool now without approval and
         * the deferral is admitted straight into scheduled_authorizations with no cockpit
         * proposal. False is the cockpit lane, where a human Approve admits it.
         *
         * The lane rides on THIS object rather than on a new executor argument because the
         * instant already flows through every staging path unchanged; a parallel boolean
         * parameter could drift out of step with it, and a staged proposal whose lane and
         * instant disagreed is exactly the confusion this design must not allow.
         */
        public bool $direct = false,
    ) {}

    /**
     * Mark this instant as the direct (no-cockpit) lane. Only the controller's grant gate
     * may call it, and only after allowsImmediateExecution() said yes for this exact tool.
     */
    public function withDirectAdmission(): self
    {
        return new self($this->utc, $this->offset, $this->at, true);
    }

    /** Whether the CANONICAL tool name may carry execute_at. */
    public static function supportsCanonical(string $canonical): bool
    {
        return in_array($canonical, self::CANONICAL_TOOLS, true);
    }

    /** Whether the STAGED (internal/dispatch) action type may carry execute_at. */
    public static function supportsStaged(string $stagedType): bool
    {
        return ActionRegistry::adapterAvailable($stagedType) && ActionRegistry::admissionRefusal($stagedType) === null;
    }

    /**
     * Named refusal for a tool that must not carry execute_at, keyed on the INTERNAL
     * (dispatch) name so the reason names the staged type the registry knows — the
     * same `unsupported_scheduling_type:<type>` shape ActionRegistry::admissionRefusal()
     * already emits for tactical_stage_script / install_approved_patches.
     */
    public static function refusalFor(string $dispatchName): string
    {
        return ActionRegistry::directTool($dispatchName) !== null && ActionRegistry::admissionRefusal($dispatchName) !== null
            ? (string) ActionRegistry::admissionRefusal($dispatchName)
            : 'unsupported_scheduling_type:'.$dispatchName;
    }

    /**
     * Parse and bound the caller's value against $now.
     *
     * @throws InvalidArgumentException execute_at_invalid | execute_at_not_in_future | execute_at_too_far_ahead
     */
    public static function parse(mixed $value, DateTimeInterface $now): self
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('execute_at_invalid');
        }
        $value = trim($value);
        // RFC 3339 shape only: date, 'T', time, explicit numeric offset or 'Z'. No
        // free-text parsing — "tomorrow at noon" is a refusal, not a guess.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException('execute_at_invalid');
        }
        $normalised = self::normalise($value, $m);
        $at = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $normalised);
        // Round-trip: PHP rolls an impossible wall time (Feb 30, 24:61) forward instead of
        // refusing; a value that does not print back as itself is refused here.
        if (! $at instanceof DateTimeImmutable || $at->format(DateTimeInterface::RFC3339_EXTENDED) !== $normalised) {
            throw new InvalidArgumentException('execute_at_invalid');
        }
        $nowTs = $now->getTimestamp();
        if ($at->getTimestamp() <= $nowTs) {
            throw new InvalidArgumentException('execute_at_not_in_future');
        }
        if ($at->getTimestamp() > $nowTs + self::MAX_AHEAD_SECONDS) {
            throw new InvalidArgumentException('execute_at_too_far_ahead');
        }
        $offset = $m[3] === 'Z' ? '+00:00' : $m[3];

        return new self($at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'), $offset, $at->setTimezone(new \DateTimeZone('UTC')));
    }

    /**
     * Rehydrate from the sealed provenance written at staging time (already normalised).
     * Always the COCKPIT lane: a rehydrated instant is being read by an approver's click,
     * so direct admission is never resurrected from stored provenance.
     */
    public static function fromProvenance(array $provenance): ?self
    {
        $utc = $provenance['execute_at'] ?? null;
        $offset = $provenance['execute_at_offset'] ?? '+00:00';
        if (! is_string($utc) || ! is_string($offset)) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $utc);
        if (! $at instanceof DateTimeImmutable || $at->format('Y-m-d\TH:i:sP') !== $utc) {
            return null;
        }

        return new self($utc, $offset, $at);
    }

    /** The admission window start/end as `Y-m-d H:i:s` wall times in $zone. */
    public function window(string $zone = 'UTC'): array
    {
        $tz = new \DateTimeZone($zone);
        $start = $this->at->setTimezone($tz);
        $end = $this->at->modify('+'.self::WINDOW_SECONDS.' seconds')->setTimezone($tz);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /** Operator-facing line: the instant in the caller's offset. */
    public function display(): string
    {
        return $this->at->setTimezone(new \DateTimeZone($this->offset))->format('Y-m-d H:i P');
    }

    private static function normalise(string $value, array $m): string
    {
        $seconds = $m[1] !== '' ? $m[1] : ':00';
        $fraction = ($m[2] ?? '') !== '' ? str_pad($m[2], 4, '0') : '.000';
        $offset = $m[3] === 'Z' ? '+00:00' : $m[3];

        return substr($value, 0, 16).$seconds.substr($fraction, 0, 4).$offset;
    }
}
