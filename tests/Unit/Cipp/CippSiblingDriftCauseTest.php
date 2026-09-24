<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippToolContract;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #3408, sweep site 2: shapeAuditLogs() and shapeOauthApps() still named "shape has
 * drifted" as the cause of an all-empty projection, two methods away from the
 * warnOnShapeDrift work that removed exactly that claim (#3394, #3413).
 *
 * G-14 gives a named cause a delete-or-rename remedy, and deletion comes first. The
 * projector cannot tell drift from a genuine no-value, so the cause is deleted from the
 * message and the message states only what was observed.
 *
 * THE FALSE PATH, which is why this is not cosmetic. projectAuditRow() (:502) runs
 * array_filter(..., fn ($v) => $v !== null) over the fields it reads. An audit row whose
 * keys are ALL PRESENT but hold null projects to [], so array_filter($projected) === []
 * fires and the operator was told the CIPP audit-log shape had drifted. The keys were
 * exactly where the contract expects them. The same projectRows() path now calls that case
 * "a genuine no-value, not an unresolved key" — the two messages contradicted each other.
 *
 * Log::listen + assertSame rather than Log::spy(): under a spy every kill is reported as a
 * Mockery error, and a kill that names the violated expectation is discriminating but
 * awkward to read. Jeeves ruled on this (2026-09-24, #3440): the listener pattern is the
 * route to an assertion kill. Measured here — mutating either message back to its old
 * wording fails with a string comparison, not an error.
 */
class CippSiblingDriftCauseTest extends TestCase
{
    /** @return list<array{string, mixed}> */
    private function capture(callable $body): array
    {
        $records = [];
        Log::listen(function (MessageLogged $r) use (&$records): void {
            $records[] = [$r->level, $r->message, $r->context];
        });

        $body();

        return $records;
    }

    /**
     * A row whose keys are PRESENT and hold null is the reachable false-cause path: the
     * projector drops them because they are null, not because the shape moved.
     */
    public function test_all_empty_audit_projection_does_not_name_drift_as_the_cause(): void
    {
        Http::preventStrayRequests();

        $rows = [['LogId' => null, 'Title' => null, 'Data' => null]];

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shapeAuditLogs($rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'audit row projected empty')));

        $this->assertCount(1, $warnings, 'the all-empty audit projection must warn exactly once');

        // Pinned by strict equality on the whole record, so no cause can re-enter through
        // the message OR a context value. A key-only pin would let one back in.
        $this->assertSame(
            [
                'warning',
                '[CippTools] Every audit row projected empty',
                [
                    'tool' => 'cipp_list_audit_logs',
                    'row_count' => 1,
                    'first_row_keys' => ['LogId', 'Title', 'Data'],
                ],
            ],
            $warnings[0]
        );

        // The specific false claim, named so a reader sees what is forbidden and why.
        $this->assertStringNotContainsString('drifted', $warnings[0][1]);
        $this->assertStringNotContainsString('drift', $warnings[0][1]);

        Http::assertNothingSent();
    }

    public function test_all_empty_oauth_projection_does_not_name_drift_as_the_cause(): void
    {
        Http::preventStrayRequests();

        $rows = [['displayName' => null, 'appId' => null]];

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shapeOauthApps($rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'OAuth app row projected empty')));

        $this->assertCount(1, $warnings, 'the all-empty OAuth projection must warn exactly once');

        $this->assertSame(
            [
                'warning',
                '[CippTools] Every OAuth app row projected empty',
                [
                    'tool' => 'cipp_list_oauth_apps',
                    'row_count' => 1,
                    'first_row_keys' => ['displayName', 'appId'],
                ],
            ],
            $warnings[0]
        );

        $this->assertStringNotContainsString('drifted', $warnings[0][1]);
        $this->assertStringNotContainsString('drift', $warnings[0][1]);

        Http::assertNothingSent();
    }

    /**
     * #3408, site 3: the conditional-access guard at :1591 kept "shape drift", and I
     * spared it on the ground that it tests key PRESENCE against the raw row. Jeeves
     * disagreed and named the path; it reproduces exactly.
     *
     * CippMcpClient::unwrapMcpResult returns ['text' => $text] when ExecMCP replies
     * isError:false with text that is not JSON. That return sits ABOVE the
     * unwrapCippEnvelope call, so CippQueueGuard::assertNotQueueBacked never runs on it.
     * normalizeRows() sees a non-list array with no Results/value key and wraps it as
     * [$rows] — ONE row keyed ['text']. $rows !== [] holds, no targeting key is present,
     * and the operator was told CIPP's schema had drifted. It had not: the payload was
     * never a policy list. The REST path wraps any keyless JSON object the same way.
     *
     * This is the exact row shape that transport produces, driven through the real
     * shape() dispatch rather than a hand-made approximation of it.
     */
    public function test_a_text_only_relay_row_does_not_name_drift_as_the_cause(): void
    {
        Http::preventStrayRequests();

        $rows = ['text' => 'Tenant not found or not onboarded'];

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shape('cipp_list_conditional_access_policies', $rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'ListConditionalAccessPolicies')));

        $this->assertCount(1, $warnings, 'a text-only row must warn exactly once');

        $this->assertSame(
            [
                'warning',
                '[CippTools] No ListConditionalAccessPolicies row carries any flattened targeting/control field — CA posture would be invisible',
                [
                    'tool' => 'cipp_list_conditional_access_policies',
                    'row_count' => 1,
                    'first_row_keys' => ['text'],
                ],
            ],
            $warnings[0]
        );

        $this->assertStringNotContainsString('drift', $warnings[0][1]);

        Http::assertNothingSent();
    }

    /**
     * Round 2's must-fix votes, and the negative control the first attempt lacked.
     *
     * The identity check began as array_key_exists('id', $row) and was defeated on a
     * path inside this module. CippMcpClient sends 'id' => 1; decodeJsonRpcPayload()
     * does `$decoded['result'] ?? $decoded`, so a reply whose result is null or absent
     * yields the whole envelope, which normalizeRows() wraps as ONE row keyed
     * ['jsonrpc','id','result']. Driving that row through shape() took the drift arm.
     * MEASURED before the predicate was changed, not argued.
     *
     * Identity is now two or more CA_POLICY_SCALAR_FIELDS. These rows carry exactly one
     * apiece, so each must take the observation-only wording.
     *
     * @dataProvider nonPolicyRowsCarryingAnId
     */
    public function test_a_non_policy_row_carrying_an_id_does_not_name_drift(array $rows, array $expectedKeys): void
    {
        Http::preventStrayRequests();

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shape('cipp_list_conditional_access_policies', $rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'ListConditionalAccessPolicies')));

        $this->assertCount(1, $warnings, 'a non-policy row must warn exactly once');

        $this->assertSame(
            [
                'warning',
                '[CippTools] No ListConditionalAccessPolicies row carries any flattened targeting/control field — CA posture would be invisible',
                [
                    'tool' => 'cipp_list_conditional_access_policies',
                    'row_count' => 1,
                    'first_row_keys' => $expectedKeys,
                ],
            ],
            $warnings[0]
        );

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
     */
    public static function nonPolicyRowsCarryingAnId(): array
    {
        return [
            // Exactly what decodeJsonRpcPayload() yields when result is null or absent.
            'the client\'s own JSON-RPC envelope' => [
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => null],
                ['jsonrpc', 'id', 'result'],
            ],
            // Every Graph entity carries a top-level id.
            'a misrouted single Graph object' => [
                ['id' => 'abc-123', 'defaultDomainName' => 'contoso.onmicrosoft.com'],
                ['id', 'defaultDomainName'],
            ],
        ];
    }

    /**
     * The other half of the ruling, and the reason this is a narrowing rather than a
     * deletion: where the rows DO carry the policy identity and still lack every
     * targeting/control key, the schema really has moved and the guard still says so.
     *
     * Without this, a fix could satisfy the test above by deleting the word everywhere,
     * and nothing would notice the guard had stopped naming a cause it can establish.
     */
    public function test_a_row_with_policy_identity_and_no_targeting_keys_still_names_drift(): void
    {
        Http::preventStrayRequests();

        // Two CA_POLICY_SCALAR_FIELDS: what CIPP's flattener emits for a real row, and
        // one more than either false payload above can muster.
        $rows = [['id' => '11111111-2222-3333-4444-555555555555', 'state' => 'enabled']];

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shape('cipp_list_conditional_access_policies', $rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'ListConditionalAccessPolicies')));

        $this->assertCount(1, $warnings, 'a policy row with no targeting keys must warn exactly once');

        $this->assertSame(
            [
                'warning',
                '[CippTools] No ListConditionalAccessPolicies row carries any flattened targeting/control field — shape drift, CA posture would be invisible',
                [
                    'tool' => 'cipp_list_conditional_access_policies',
                    'row_count' => 1,
                    'first_row_keys' => ['id', 'state'],
                ],
            ],
            $warnings[0]
        );

        Http::assertNothingSent();
    }
}
