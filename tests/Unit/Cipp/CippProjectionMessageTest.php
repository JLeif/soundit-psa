<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippToolContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CippProjectionMessageTest extends TestCase
{
    public static function emptyRows(): array
    {
        // Synthetic contract counterexamples, not fixtures of a vendor response.
        return [
            'resolved null keys' => [[['User' => null, 'Permissions' => null]]],
            'absent keys' => [[['Unexpected' => 'synthetic']]],
            'mixed rows' => [[['User' => null, 'Permissions' => null], ['Unexpected' => 'synthetic']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyRows')]
    public function test_empty_projection_does_not_assert_its_cause(array $rows): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $result = app(CippToolContract::class)->shape('cipp_list_mailbox_permissions', $rows, [], null);

        $this->assertSame(array_fill(0, count($rows), []), $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === '[CippTools] Every row projected empty'
                && $context === [
                    'tool' => 'cipp_list_mailbox_permissions',
                    'row_count' => count($rows),
                    'first_row_keys' => array_keys($rows[0]),
                ]
        );

        foreach (['emergency', 'alert', 'critical', 'error', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        Http::assertNothingSent();
    }

    public static function nonemptyRows(): array
    {
        return [
            'nonempty row' => [
                [['User' => null, 'Permissions' => 'FullAccess']],
                [['permissions' => 'FullAccess']],
            ],
            'empty and nonempty rows' => [
                [['User' => null, 'Permissions' => null], ['User' => null, 'Permissions' => 'FullAccess']],
                [[], ['permissions' => 'FullAccess']],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonemptyRows')]
    public function test_nonempty_projection_emits_nothing_at_any_level(array $rows, array $expected): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $result = app(CippToolContract::class)->shape('cipp_list_mailbox_permissions', $rows, [], null);

        $this->assertSame($expected, $result);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        Http::assertNothingSent();
    }

    /**
     * The drift warning must not name a cause this function cannot establish.
     *
     * FIVE of the six callers of projectRows() filter rows BEFORE calling it
     * (shapeEvents, shapeMessageTrace, shapeMailQuarantine, shapeMailboxRules,
     * shapeTenantMailboxRules), so the rows it inspects are a SUBSET of the
     * upstream response. A field carried only by rows the caller dropped is
     * absent from every row here while DEFAULT_FIELDS and FIELD_ALIASES are
     * both correct -- so the constants cannot be blamed from inside this
     * function.
     *
     * This control drives that exact case through the real filtering caller:
     * two message-trace rows, only one carrying FromIP/ToIP, filtered by
     * sender to the row that does not. The constants are untouched and
     * correct, and the warning still fires.
     */
    public function test_the_drift_warning_does_not_blame_the_constants_for_a_filtered_subset(): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $rows = [
            [
                'MessageTraceId' => 'keep-me',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 'keep@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'carries the IP fields',
                'Status' => 'Delivered',
                'FromIP' => '203.0.113.1',
                'ToIP' => '203.0.113.2',
            ],
            [
                'MessageTraceId' => 'survives-the-filter',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 'other@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'no IP fields',
                'Status' => 'Delivered',
            ],
        ];

        app(CippToolContract::class)->shape(
            'cipp_list_message_trace',
            $rows,
            ['sender' => 'other@example.test'],
            null,
        );

        // Precondition: the warning really did fire on this submission, so the
        // assertions below cannot pass because nothing was logged at all.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['tool'] === 'cipp_list_message_trace'
                && in_array('FromIP', $context['missing_fields'], true)
        );

        // The claim under test: the message states what was observed and does
        // not name a cause. A fix that only reworded the sentence while
        // keeping the diagnosis would fail here.
        Log::shouldNotHaveReceived('warning', [
            \Mockery::pattern('/DEFAULT_FIELDS|FIELD_ALIASES|out of sync/'),
            \Mockery::any(),
        ]);

        Http::assertNothingSent();
    }

    /**
     * The positive control for the one above: a field genuinely absent from an
     * UNFILTERED response must still be reported, with the same keys.
     *
     * Without this, deleting the warning outright would satisfy the assertion
     * above -- the two cannot tell "reported without a false cause" from
     * "no longer reported" apart on their own.
     */
    public function test_a_genuinely_absent_field_is_still_reported_on_an_unfiltered_call(): void
    {
        Http::preventStrayRequests();
        Log::spy();

        app(CippToolContract::class)->shape(
            'cipp_list_message_trace',
            [[
                'MessageTraceId' => 'only-row',
                'Received' => '2026-09-01T00:00:00Z',
                'SenderAddress' => 's@example.test',
                'RecipientAddress' => 'r@example.test',
                'Subject' => 'no IP fields',
                'Status' => 'Delivered',
            ]],
            [],
            null,
        );

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['tool'] === 'cipp_list_message_trace'
                && in_array('FromIP', $context['missing_fields'], true)
                && in_array('ToIP', $context['missing_fields'], true)
                && $context['row_count'] === 1
                && in_array('MessageTraceId', $context['first_row_keys'], true)
        );

        Http::assertNothingSent();
    }

    /**
     * Round 1 contract:1, verified by execution: resolveKey() is an exact-case
     * array_key_exists over FIELD_ALIASES[$field] ?? [$field], so a row that
     * DOES carry the field under an unaliased casing is still reported. The
     * message must therefore say "never resolved", never "absent" - the latter
     * is a false claim about the data on precisely the alias-drift path this
     * guard exists to surface.
     */
    public function test_a_field_present_under_an_unaliased_casing_is_not_called_absent(): void
    {
        Log::spy();
        Http::fake();

        // EVERY row carries the field, under a casing FIELD_ALIASES does not map.
        $rows = [
            ['AccountEnabled' => true, 'JobTitle' => 'Tech', 'displayName' => 'A', 'userPrincipalName' => 'a@example.test'],
            ['AccountEnabled' => false, 'JobTitle' => 'Eng', 'displayName' => 'B', 'userPrincipalName' => 'b@example.test'],
        ];

        app(CippToolContract::class)->shape('cipp_list_users', $rows, [], null);

        // Precondition: the warning fired and named the unresolved field, so
        // the assertion below cannot pass because nothing was logged.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['tool'] === 'cipp_list_users'
                && in_array('accountEnabled', $context['missing_fields'], true)
                && in_array('AccountEnabled', $context['first_row_keys'], true)
        );

        // The claim under test: the row carries the data, so the message must
        // not assert the field is absent from it.
        Log::shouldNotHaveReceived('warning', [
            \Mockery::pattern('/absent from every row/'),
            \Mockery::any(),
        ]);

        Http::assertNothingSent();
    }
}
