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

        Http::assertNothingSent();
    }

    public function test_nonempty_projection_does_not_emit_empty_projection_warning(): void
    {
        Http::preventStrayRequests();
        Log::spy();

        $result = app(CippToolContract::class)->shape('cipp_list_mailbox_permissions', [
            ['User' => null, 'Permissions' => 'FullAccess'],
        ], [], null);

        $this->assertSame([['permissions' => 'FullAccess']], $result);
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }
}
