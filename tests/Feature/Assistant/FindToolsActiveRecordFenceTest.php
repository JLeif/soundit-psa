<?php

namespace Tests\Feature\Assistant;

use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-eu5la: the staff-MCP find_persons / find_assets read tools fenced on the
 * CLIENT's is_active (whereHas('client', active())) and NEVER on the record's own
 * is_active. That drifts in BOTH directions at once:
 *
 *   - they RETURNED deactivated records the web hides — an OFFBOARDED contact (the
 *     exact person CippContactSyncService deactivates when accountEnabled flips
 *     false) or a RETIRED asset — so an agent could route a ticket, or address an
 *     email, to a TERMINATED EMPLOYEE; and
 *   - they HID records at deactivated clients the web shows, contradicting the
 *     tools' own published "searches across ALL clients" descriptions.
 *
 * Fix mirrors the web (Person::active() in Web\PersonController, the is_active
 * default in AssetService::getAssetList) and the psa-6usr ticket-fence decision:
 * fence on the RECORD's own is_active (active-only by default) and drop the client
 * EXISTS fence — staff already see every client on the web, so this widens no
 * tenant boundary (these models carry no tenant scope). An explicit include_inactive
 * opt-in (default false) preserves the legitimate retired-asset / former-employee
 * lookup, mirroring the web's show_inactive and StaffPsaTaxonomyToolExecutor's
 * include_inactive.
 */
class FindToolsActiveRecordFenceTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive token shared by every probe record so the LIKE search targets them. */
    private const PROBE = 'zzfindprobe';

    // ── find_persons ─────────────────────────────────────────────────────────

    public function test_find_persons_excludes_offboarded_contact_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Active',
            'last_name' => self::PROBE.'active',
            'email' => 'active-'.self::PROBE.'@example.test',
            'is_active' => true,
        ]);
        $offboarded = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Gone',
            'last_name' => self::PROBE.'gone',
            'email' => 'gone-'.self::PROBE.'@example.test',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_persons', ['query' => self::PROBE, 'limit' => 25]);
        $ids = array_column($result['persons'], 'id');

        $this->assertContains($active->id, $ids, 'an active contact must be found');
        $this->assertNotContains(
            $offboarded->id,
            $ids,
            'a deactivated (offboarded) contact must NOT be returned by default — the agent must not route work to a terminated employee',
        );
    }

    public function test_find_persons_returns_active_contact_at_a_deactivated_client(): void
    {
        $inactiveClient = Client::factory()->create(['is_active' => false]);
        $person = Person::create([
            'client_id' => $inactiveClient->id,
            'person_type' => PersonType::User,
            'first_name' => 'AtInactive',
            'last_name' => self::PROBE.'atinactive',
            'email' => 'atinactive-'.self::PROBE.'@example.test',
            'is_active' => true,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_persons', ['query' => self::PROBE, 'limit' => 25]);
        $ids = array_column($result['persons'], 'id');

        $this->assertContains(
            $person->id,
            $ids,
            'a search "across ALL clients" must include an active contact at a deactivated client',
        );
    }

    public function test_find_persons_include_inactive_returns_offboarded_contact(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $offboarded = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Gone',
            'last_name' => self::PROBE.'gone',
            'email' => 'gone2-'.self::PROBE.'@example.test',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_persons', [
            'query' => self::PROBE,
            'include_inactive' => true,
            'limit' => 25,
        ]);
        $ids = array_column($result['persons'], 'id');

        $this->assertContains(
            $offboarded->id,
            $ids,
            'include_inactive=true must surface a deactivated contact for an explicit former-employee lookup',
        );
    }

    // ── find_assets ──────────────────────────────────────────────────────────

    public function test_find_assets_excludes_retired_asset_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-live',
            'is_active' => true,
        ]);
        $retired = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-retired',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_assets', ['query' => self::PROBE, 'limit' => 25]);
        $ids = array_column($result['assets'], 'id');

        $this->assertContains($active->id, $ids, 'an active asset must be found');
        $this->assertNotContains(
            $retired->id,
            $ids,
            'a retired asset must NOT be returned by default',
        );
    }

    public function test_find_assets_returns_active_asset_at_a_deactivated_client(): void
    {
        $inactiveClient = Client::factory()->create(['is_active' => false]);
        $asset = Asset::factory()->create([
            'client_id' => $inactiveClient->id,
            'hostname' => self::PROBE.'-orphan',
            'is_active' => true,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_assets', ['query' => self::PROBE, 'limit' => 25]);
        $ids = array_column($result['assets'], 'id');

        $this->assertContains(
            $asset->id,
            $ids,
            'a search "across ALL clients" must include an active asset at a deactivated client',
        );
    }

    public function test_find_assets_include_inactive_returns_retired_asset(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $retired = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-retired',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_assets', [
            'query' => self::PROBE,
            'include_inactive' => true,
            'limit' => 25,
        ]);
        $ids = array_column($result['assets'], 'id');

        $this->assertContains(
            $retired->id,
            $ids,
            'include_inactive=true must surface a retired asset for an explicit lookup',
        );
    }
}
