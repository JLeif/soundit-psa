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
     * The whole matrix, every row pinned to the OBSERVATION wording.
     *
     * The word "drift" was DELETED rather than narrowed (Jeeves, #3408). Three
     * narrowings were each defeated by an input nobody had listed:
     *
     *   1. 'id' present  -> defeated by CippMcpClient's own JSON-RPC envelope.
     *      decodeJsonRpcPayload() does `$decoded['result'] ?? $decoded`, so a reply
     *      with result null or absent promotes the whole envelope, carrying the
     *      transport's id (the client sends 1).
     *   2. two CA scalars -> defeated the same way by ordinary rows, and it LOST a
     *      genuine capital-'Id' policy row.
     *   3. GUID id + a companion key -> defeated by ordinary Graph objects. A Graph
     *      user, group, application and device each carry a GUID id and a
     *      displayName, so the pairing proves "this is a Graph object", never "this
     *      is a CA policy". MEASURED end to end through shape(), not argued.
     *
     * So the guard states what it observed and names no cause. first_row_keys already
     * carries the fact: ['id','displayName','state'] reads as a policy row that lost
     * its targeting keys, ['jsonrpc','id'] plainly does not. A structured key a reader
     * can act on beats an adjective the guard cannot establish.
     *
     * @dataProvider everyRowTakesTheObservationWording
     */
    public function test_every_row_states_the_observation_and_names_no_cause(array $rows, array $expectedKeys): void
    {
        Http::preventStrayRequests();

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shape('cipp_list_conditional_access_policies', $rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'ListConditionalAccessPolicies')));

        $this->assertCount(1, $warnings, 'the guard must warn exactly once');

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
    public static function everyRowTakesTheObservationWording(): array
    {
        $guid = '11111111-2222-3333-4444-555555555555';

        return [
            // Non-JSON MCP text: CippMcpClient::unwrapMcpResult returns ['text' => …]
            // ABOVE the unwrapCippEnvelope call, so the queue guard never sees it.
            'a non-JSON MCP text result' => [
                ['text' => 'Tenant not found or not onboarded'],
                ['text'],
            ],
            // A REST error body: any keyless JSON object is wrapped as one row.
            'a REST error body' => [
                ['error' => 'Insufficient privileges', 'status' => 403],
                ['error', 'status'],
            ],
            // What decodeJsonRpcPayload() yields when result is null.
            "the client's own JSON-RPC envelope" => [
                ['jsonrpc' => '2.0', 'id' => 1, 'result' => null],
                ['jsonrpc', 'id', 'result'],
            ],
            // The same fallback when result is absent entirely.
            'the same envelope with no result key' => [
                ['jsonrpc' => '2.0', 'id' => 1],
                ['jsonrpc', 'id'],
            ],
            // The rows that defeated narrowing 3. Each is an ordinary Graph object
            // with a GUID id and a displayName, and none is a CA policy.
            'a Graph user' => [
                ['id' => $guid, 'displayName' => 'Ada Lovelace', 'userPrincipalName' => 'ada@contoso.com', 'createdDateTime' => '2026-01-01T00:00:00Z'],
                ['id', 'displayName', 'userPrincipalName', 'createdDateTime'],
            ],
            'a Graph group' => [
                ['id' => $guid, 'displayName' => 'Sales', 'createdDateTime' => '2026-01-01T00:00:00Z', 'mail' => 'sales@contoso.com'],
                ['id', 'displayName', 'createdDateTime', 'mail'],
            ],
            'a Graph application' => [
                ['id' => $guid, 'appId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'displayName' => 'Reporting', 'createdDateTime' => '2026-01-01T00:00:00Z'],
                ['id', 'appId', 'displayName', 'createdDateTime'],
            ],
            'a Graph device' => [
                ['id' => $guid, 'displayName' => 'LAPTOP-01', 'operatingSystem' => 'Windows'],
                ['id', 'displayName', 'operatingSystem'],
            ],
            // An error body whose Name is a displayName alias, with a GUID id.
            'an error body carrying a GUID id and a name' => [
                ['id' => $guid, 'Name' => 'Something', 'error' => 'Forbidden'],
                ['id', 'Name', 'error'],
            ],
            // THE DECISION THIS ROUND MAKES. A real CA policy row whose targeting keys
            // are gone is the one input where "the schema moved" is the true cause, and
            // it now takes the observation wording like everything else. The word was
            // dropped rather than narrowed because no cheap predicate separates this row
            // from the Graph objects above; first_row_keys is what tells them apart.
            'a real CA policy row with its targeting keys gone' => [
                ['id' => $guid, 'displayName' => 'Require MFA', 'state' => 'enabled'],
                ['id', 'displayName', 'state'],
            ],
        ];
    }

    /**
     * PRESENCE, NOT EMPTINESS — the sibling defect, from the other direction.
     *
     * shapeAuditLogs and shapeOauthApps filtered on value and called an all-null row
     * drift; that false cause is what this card removed. This guard must not acquire
     * it: a policy row whose targeting keys are all PRESENT and all NULL has not lost
     * its shape, so the guard stays SILENT.
     *
     * Owed since Jeeves's 06:38 ruling and missing until now. It is pinned by
     * assertSame([], …) rather than a count so the failure names what leaked.
     */
    public function test_an_all_null_targeting_row_stays_silent(): void
    {
        Http::preventStrayRequests();

        $rows = [[
            'id' => '11111111-2222-3333-4444-555555555555',
            'displayName' => 'Require MFA',
            'state' => 'enabled',
            'includeUsers' => null,
            'excludeUsers' => null,
            'includeApplications' => null,
            'builtInControls' => null,
            'clientAppTypes' => null,
        ]];

        $records = $this->capture(fn () => app(CippToolContract::class)
            ->shape('cipp_list_conditional_access_policies', $rows, [], null));

        $warnings = array_values(array_filter($records, fn (array $r): bool => $r[0] === 'warning'
            && str_contains($r[1], 'ListConditionalAccessPolicies')));

        $this->assertSame([], $warnings, 'an all-null targeting row is not a shape change');

        Http::assertNothingSent();
    }
}
