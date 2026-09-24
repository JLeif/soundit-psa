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
}
