<?php

namespace App\Services\Huntress;

use Carbon\CarbonImmutable;

/** Pure promotion predicate. Its inputs must be immutable ingest + verified event evidence. */
class HuntressLinkPolicy
{
    /** Capture candidates only from the authenticated CW ingest, never editable ticket text. */
    public function candidates(string $description): array
    {
        preg_match_all('#https://(?:[\w-]+\.)*huntress\.io/org/(\d+)/(infection_reports|incident_reports|escalations)/(\d+)(?![\w-])#', $description, $matches, PREG_SET_ORDER);
        $candidates = [];
        foreach ($matches as $m) {
            $org = filter_var($m[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $id = filter_var($m[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($org === false || $id === false) {
                return ['reason' => 'link_conflict', 'candidate' => null];
            }
            $type = $m[2] === 'escalations' ? 'escalation' : 'incident_report';
            $candidates[$type.':'.$id.':'.$org] = ['record_type' => $type, 'record_id' => $id, 'organization_id' => $org];
        }
        if (count($candidates) !== 1) {
            return ['reason' => count($candidates) > 1 ? 'link_conflict' : 'no_candidate', 'candidate' => null];
        }

        return ['reason' => null, 'candidate' => array_values($candidates)[0]];
    }

    /** Return a refusal reason, or null when all correlation predicates pass. */
    public function refusal(array $candidate, array $event, ?int $expectedAccount, ?int $alertOrg, ?int $ticketOrg, string $cwReceivedAt, ?int $candidateAgent = null): ?string
    {
        if ($candidate['record_type'] !== $event['record_type'] || $candidate['record_id'] !== $event['record_id']) {
            return 'unvalidated_candidate';
        }
        if (! $expectedAccount || $event['account_id'] !== $expectedAccount || ! $alertOrg || $alertOrg !== $ticketOrg
            || $candidate['organization_id'] !== $alertOrg || ! in_array($alertOrg, $event['organization_ids'], true)) {
            return 'scope_or_identity_mismatch';
        }
        if ($candidateAgent !== null && $event['agent_id'] !== null && $candidateAgent !== $event['agent_id']) {
            return 'scope_or_identity_mismatch';
        }
        $delta = CarbonImmutable::parse($cwReceivedAt, 'UTC')->diffInSeconds(CarbonImmutable::parse($event['correlation_at'], 'UTC'), false);
        if ($delta < -config('huntress.link_anchor_before_seconds') || $delta > config('huntress.link_anchor_after_seconds')) {
            return 'outside_link_window';
        }

        return null;
    }
}
