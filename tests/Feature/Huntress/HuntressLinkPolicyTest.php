<?php

namespace Tests\Feature\Huntress;

use App\Services\Huntress\HuntressLinkPolicy;
use Tests\TestCase;

class HuntressLinkPolicyTest extends TestCase
{
    public function test_candidates_refuse_conflicting_type_id_or_org(): void
    {
        $policy = new HuntressLinkPolicy;
        $url = 'https://console.huntress.io/org/42/incident_reports/9182';
        $this->assertNull($policy->candidates($url.' '.$url)['reason']);
        foreach (['escalations/9182', 'incident_reports/9183'] as $path) {
            $this->assertSame('link_conflict', $policy->candidates($url.' https://console.huntress.io/org/42/'.$path)['reason']);
        }
        $this->assertSame('link_conflict', $policy->candidates($url.' '.str_replace('/42/', '/43/', $url))['reason']);
        $this->assertSame('no_candidate', $policy->candidates(str_replace('console.huntress.io', 'phish-huntress.io', $url))['reason']);
        $this->assertSame('link_conflict', $policy->candidates(str_replace('9182', '18446744073709551616', $url))['reason']);
    }

    public function test_record_scope_and_asymmetric_window_are_independent_guards(): void
    {
        $policy = new HuntressLinkPolicy;
        $candidate = ['record_type' => 'incident_report', 'record_id' => 9183, 'organization_id' => 42];
        $event = ['record_type' => 'incident_report', 'record_id' => 9183, 'account_id' => 11,
            'organization_ids' => [42], 'agent_id' => null, 'correlation_at' => '2026-09-14 12:00:00'];
        $check = fn ($e, $account = 11, $alertOrg = 42, $ticketOrg = 42) => $policy->refusal($candidate, $e, $account, $alertOrg, $ticketOrg, '2026-09-14 12:00:00');
        $this->assertNull($check($event));
        // A matching signed org and client mapping cannot launder a different
        // organization embedded in the immutable candidate locator.
        $this->assertSame('scope_or_identity_mismatch', $policy->refusal(
            array_replace($candidate, ['organization_id' => 43]), $event, 11, 42, 42, '2026-09-14 12:00:00'));
        $this->assertSame('scope_or_identity_mismatch', $check($event, 11, 42, null));
        $this->assertSame('unvalidated_candidate', $check(array_replace($event, ['record_id' => 9182])));
        foreach ([['account_id' => null], ['account_id' => 12], ['organization_ids' => []], ['organization_ids' => [43]]] as $change) {
            $this->assertSame('scope_or_identity_mismatch', $check(array_replace($event, $change)));
        }
        $this->assertSame('scope_or_identity_mismatch', $check($event, null));
        $this->assertSame('scope_or_identity_mismatch', $check($event, 11, null));
        $this->assertSame('scope_or_identity_mismatch', $check($event, 11, 42, 43));
        foreach (['11:45:00', '12:02:00'] as $time) {
            $this->assertNull($check(array_replace($event, ['correlation_at' => '2026-09-14 '.$time])));
        }
        foreach (['11:44:59', '12:02:01'] as $time) {
            $this->assertSame('outside_link_window', $check(array_replace($event, ['correlation_at' => '2026-09-14 '.$time])));
        }
        $this->assertSame('scope_or_identity_mismatch', $policy->refusal($candidate, array_replace($event, ['agent_id' => 2]), 11, 42, 42, '2026-09-14 12:00:00', 1));
        $this->assertNull($policy->refusal($candidate, $event, 11, 42, 42, '2026-09-14 12:00:00', 1));
    }
}
