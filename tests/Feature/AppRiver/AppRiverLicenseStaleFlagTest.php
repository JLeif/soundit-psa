<?php

namespace Tests\Feature\AppRiver;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Card 6abc5913 (EKnz4VSM), scope item 3: AppRiver-sourced licence rows whose
 * last sync is MORE than 48h old carry a Stale badge on the Licenses page.
 * The clock is frozen to a whole second so the boundary cases are exact.
 */
class AppRiverLicenseStaleFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Whole second: synced_at is stored to the second, so a microsecond clock
        // would make an "exactly 48h" row read a fraction older than 48h.
        $this->freezeSecond();
    }

    private function license(string $vendor, ?\DateTimeInterface $syncedAt, string $sku): License
    {
        $client = Client::factory()->create();
        $type = LicenseType::create(['vendor' => $vendor, 'vendor_sku_id' => $sku, 'name' => strtoupper($sku), 'is_active' => true]);

        return License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'quantity' => 20,
            'status' => 'active',
            'synced_at' => $syncedAt,
        ]);
    }

    /** @return array<string, array{0: int, 1: bool}> minutes since sync => stale? */
    public static function boundaryCases(): array
    {
        return [
            '47h59m is fresh' => [48 * 60 - 1, false],
            'exactly 48h is not yet stale' => [48 * 60, false],
            '48h01m is stale' => [48 * 60 + 1, true],
            '12 days is stale' => [12 * 24 * 60, true],
            'just synced is fresh' => [0, false],
        ];
    }

    #[DataProvider('boundaryCases')]
    public function test_appriver_row_stale_only_past_48_hours(int $minutesAgo, bool $stale): void
    {
        $license = $this->license('appriver', now()->subMinutes($minutesAgo), 'sku-'.$minutesAgo);

        $this->assertSame($stale, $license->fresh()->sync_stale);

        $html = $this->actingAs(User::factory()->create())->get(route('licenses.index'))->assertOk()->getContent();
        $needle = 'data-stale-license="'.$license->id.'"';
        $stale
            ? $this->assertStringContainsString($needle, $html, 'the Licenses page must flag the stale row')
            : $this->assertStringNotContainsString($needle, $html);
    }

    public function test_other_vendors_and_manual_rows_are_never_flagged(): void
    {
        $cipp = $this->license('cipp', now()->subDays(12), 'cipp-sku');
        $manual = $this->license('appriver', null, 'manual-sku');

        $this->assertFalse($cipp->fresh()->sync_stale, 'the 48h rule is scoped to AppRiver-sourced rows');
        $this->assertFalse($manual->fresh()->sync_stale, 'a manual row was never synced, so it cannot be stale');

        $html = $this->actingAs(User::factory()->create())->get(route('licenses.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-stale-license=', $html);
    }

    public function test_rows_a_healthy_sync_never_re_stamps_are_not_flagged(): void
    {
        // Vendor-held rows get only vendor_status rewritten each night, and a zeroed,
        // suspended row is left alone: their synced_at ages on a working install.
        $held = $this->license('appriver', now()->subDays(12), 'held-sku');
        $held->update(['vendor_status' => 'Suspended']);
        $pending = $this->license('appriver', now()->subDays(12), 'pending-sku');
        $pending->update(['vendor_status' => 'Pending']);
        $suspended = $this->license('appriver', now()->subDays(12), 'suspended-sku');
        $suspended->update(['status' => 'suspended', 'quantity' => 0]);
        // Positive control: an active row the vendor reports Active is still flagged.
        $live = $this->license('appriver', now()->subDays(12), 'live-sku');
        $live->update(['vendor_status' => 'Active']);

        foreach ([$held, $pending, $suspended] as $license) {
            $this->assertFalse($license->fresh()->sync_stale, "licence {$license->id} is not re-stamped by a healthy sync");
        }
        $this->assertTrue($live->fresh()->sync_stale);

        $html = $this->actingAs(User::factory()->create())->get(route('licenses.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-stale-license="'.$live->id.'"', $html);
        foreach ([$held, $pending, $suspended] as $license) {
            $this->assertStringNotContainsString('data-stale-license="'.$license->id.'"', $html);
        }
    }
}
