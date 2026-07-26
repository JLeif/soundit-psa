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
 * psa-eu5la: the staff read surface (find_persons/find_assets AND their by-id/by-name
 * siblings get_person/get_asset) fenced on the CLIENT's is_active, never on the
 * record's own. That drifts in both directions — returning OFFBOARDED contacts and
 * DEACTIVATED assets the web hides (an agent could route a ticket or address an email
 * to a terminated employee) while HIDING active records at deactivated clients — and
 * contradicts the tools' own "across ALL clients" copy.
 *
 * The fix fences on the RECORD's own is_active via the model's scopeActive() (matching
 * Web\PersonController Person::active() and AssetService's is_active default), drops the
 * client EXISTS fence (staff already see every client on the web — no tenant boundary;
 * mirrors psa-6usr), and exposes an explicit include_inactive opt-in.
 *
 * The REVISE (2 REVISE / 1 APPROVE @ b5741f4) hardened three things this suite now pins:
 *   1. get_person/get_asset must be active-by-default on EVERY lookup path (id, email,
 *      hostname, partial name) — they were the two-call bypass around find_*'s fence.
 *   2. include_inactive must be a REAL boolean: the raw string "false" must NOT opt in
 *      (a naive (bool) cast read it as true — the inverse of the guard).
 *   3. "retired" assets = soft-deleted ($asset->delete(), AssetService::deleteAsset);
 *      include_inactive only flips is_active and must NOT surface soft-deleted rows.
 */
class FindToolsActiveRecordFenceTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive token shared by every probe record so the LIKE search targets them. */
    private const PROBE = 'zzfindprobe';

    private function person(int $clientId, string $lastName, bool $active, string $emailTag): Person
    {
        return Person::create([
            'client_id' => $clientId,
            'person_type' => PersonType::User,
            'first_name' => 'Probe',
            'last_name' => $lastName,
            'email' => $emailTag.'@example.test',
            'is_active' => $active,
        ]);
    }

    // ── find_persons ─────────────────────────────────────────────────────────

    public function test_find_persons_excludes_offboarded_contact_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = $this->person($client->id, self::PROBE.'active', true, 'active-'.self::PROBE);
        $offboarded = $this->person($client->id, self::PROBE.'gone', false, 'gone-'.self::PROBE);

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
        $person = $this->person($inactiveClient->id, self::PROBE.'atinactive', true, 'atinactive-'.self::PROBE);

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
        $offboarded = $this->person($client->id, self::PROBE.'gone', false, 'gone2-'.self::PROBE);

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

    public function test_find_persons_string_false_include_inactive_still_excludes_offboarded(): void
    {
        // A raw JSON tool call (no validator sits in front of the executor) can deliver
        // include_inactive as the STRING "false", which a naive (bool) cast reads as
        // TRUE — opting INTO inactive records, the inverse of the guard.
        $client = Client::factory()->create(['is_active' => true]);
        $offboarded = $this->person($client->id, self::PROBE.'gone', false, 'gonestr-'.self::PROBE);

        $result = (new AssistantToolExecutor)->execute('find_persons', [
            'query' => self::PROBE,
            'include_inactive' => 'false',
            'limit' => 25,
        ]);
        $ids = array_column($result['persons'], 'id');

        $this->assertNotContains(
            $offboarded->id,
            $ids,
            'include_inactive="false" (string) must be treated as false — active-only, never the inverse',
        );
    }

    public function test_include_inactive_requires_a_real_boolean_truthy_non_booleans_do_not_opt_in(): void
    {
        // psa-eu5la R2 (.4 security + .5 architecture): a SAFETY opt-in must require a
        // real boolean — filter_var coercion still let "true"/"yes"/"1"/1/"on" surface
        // offboarded records. Only literal boolean true may opt in; everything else
        // fails closed to active-only.
        $client = Client::factory()->create(['is_active' => true]);
        $offboarded = $this->person($client->id, self::PROBE.'gone', false, 'strict-'.self::PROBE);

        foreach (['true', 'yes', 'on', '1', 1] as $truthy) {
            $result = (new AssistantToolExecutor)->execute('find_persons', [
                'query' => self::PROBE,
                'include_inactive' => $truthy,
                'limit' => 25,
            ]);
            $ids = array_column($result['persons'], 'id');

            $this->assertNotContains(
                $offboarded->id,
                $ids,
                'include_inactive='.var_export($truthy, true).' (non-boolean) must NOT opt into inactive records — only a real boolean true may',
            );
        }
    }

    public function test_get_person_string_yes_does_not_opt_into_inactive(): void
    {
        // The exact security-lane repro: get_person(..., include_inactive="yes").
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = $this->person($client->id, self::PROBE.'y', false, 'gpy-'.self::PROBE);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $inactive->id, 'include_inactive' => 'yes']);

        $this->assertArrayHasKey('error', $result, 'get_person include_inactive="yes" must NOT resolve a deactivated contact');
        $this->assertArrayNotHasKey('email', $result);
    }

    // ── find_assets ──────────────────────────────────────────────────────────

    public function test_find_assets_excludes_deactivated_asset_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-live',
            'is_active' => true,
        ]);
        $deactivated = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-off',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_assets', ['query' => self::PROBE, 'limit' => 25]);
        $ids = array_column($result['assets'], 'id');

        $this->assertContains($active->id, $ids, 'an active asset must be found');
        $this->assertNotContains(
            $deactivated->id,
            $ids,
            'a deactivated (is_active=false) asset must NOT be returned by default',
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

    public function test_find_assets_include_inactive_returns_deactivated_asset(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $deactivated = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-off',
            'is_active' => false,
        ]);

        $result = (new AssistantToolExecutor)->execute('find_assets', [
            'query' => self::PROBE,
            'include_inactive' => true,
            'limit' => 25,
        ]);
        $ids = array_column($result['assets'], 'id');

        $this->assertContains(
            $deactivated->id,
            $ids,
            'include_inactive=true must surface a deactivated (is_active=false) asset',
        );
    }

    public function test_find_assets_include_inactive_still_excludes_soft_deleted_retired_asset(): void
    {
        // "Retire" has a concrete meaning here: AssetService::deleteAsset() soft-deletes
        // ($asset->delete()). include_inactive flips is_active only; SoftDeletes' global
        // scope must keep a retired (soft-deleted) row out even with include_inactive=true.
        $client = Client::factory()->create(['is_active' => true]);
        $retired = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => self::PROBE.'-retired',
            'is_active' => true,
        ]);
        $retired->delete();

        $result = (new AssistantToolExecutor)->execute('find_assets', [
            'query' => self::PROBE,
            'include_inactive' => true,
            'limit' => 25,
        ]);
        $ids = array_column($result['assets'], 'id');

        $this->assertNotContains(
            $retired->id,
            $ids,
            'a soft-deleted (retired) asset must NOT be returned even with include_inactive=true',
        );
    }

    // ── get_person (the two-call bypass the security lane proved) ─────────────

    public function test_get_person_by_id_returns_active_but_not_inactive_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = $this->person($client->id, self::PROBE.'a', true, 'gpa-'.self::PROBE);
        $inactive = $this->person($client->id, self::PROBE.'b', false, 'gpi-'.self::PROBE);
        $exec = new AssistantToolExecutor(clientId: $client->id);

        $foundActive = $exec->execute('get_person', ['person_id' => $active->id]);
        $this->assertSame($active->id, $foundActive['id'] ?? null, 'an active contact must resolve by id');

        $foundInactive = $exec->execute('get_person', ['person_id' => $inactive->id]);
        $this->assertArrayHasKey('error', $foundInactive, 'get_person by id must NOT return a deactivated contact by default');
        $this->assertArrayNotHasKey('email', $foundInactive, 'the deactivated contact’s email must not leak through the by-id path');
    }

    public function test_get_person_by_email_excludes_inactive_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = $this->person($client->id, self::PROBE.'e', false, 'gpe-'.self::PROBE);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['email' => 'gpe-'.self::PROBE.'@example.test']);

        $this->assertArrayHasKey('error', $result, 'get_person by email must NOT return a deactivated contact by default');
        $this->assertArrayNotHasKey('email', $result);
    }

    public function test_get_person_by_name_excludes_inactive_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = $this->person($client->id, self::PROBE.'n', false, 'gpn-'.self::PROBE);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['name' => self::PROBE.'n']);

        $this->assertArrayHasKey('error', $result, 'get_person by partial name must NOT return a deactivated contact by default');
        $this->assertArrayNotHasKey('email', $result);
    }

    public function test_get_person_include_inactive_returns_inactive_by_id(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = $this->person($client->id, self::PROBE.'z', false, 'gpz-'.self::PROBE);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_person', ['person_id' => $inactive->id, 'include_inactive' => true]);

        $this->assertSame($inactive->id, $result['id'] ?? null, 'include_inactive=true must resolve a deactivated contact for a deliberate lookup');
    }

    // ── get_asset (analogous inactive-by-id/hostname hole) ────────────────────

    public function test_get_asset_by_id_returns_active_but_not_inactive_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $active = Asset::factory()->create(['client_id' => $client->id, 'hostname' => self::PROBE.'-gaa', 'is_active' => true]);
        $inactive = Asset::factory()->create(['client_id' => $client->id, 'hostname' => self::PROBE.'-gai', 'is_active' => false]);
        $exec = new AssistantToolExecutor(clientId: $client->id);

        $foundActive = $exec->execute('get_asset', ['asset_id' => $active->id]);
        $this->assertSame($active->id, $foundActive['id'] ?? null, 'an active asset must resolve by id');

        $foundInactive = $exec->execute('get_asset', ['asset_id' => $inactive->id]);
        $this->assertArrayHasKey('error', $foundInactive, 'get_asset by id must NOT return a deactivated asset by default');
    }

    public function test_get_asset_by_hostname_excludes_inactive_by_default(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = Asset::factory()->create(['client_id' => $client->id, 'hostname' => self::PROBE.'-gah', 'is_active' => false]);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_asset', ['hostname' => self::PROBE.'-gah']);

        $this->assertArrayHasKey('error', $result, 'get_asset by hostname must NOT return a deactivated asset by default');
    }

    public function test_get_asset_include_inactive_returns_inactive_by_id(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = Asset::factory()->create(['client_id' => $client->id, 'hostname' => self::PROBE.'-gaz', 'is_active' => false]);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_asset', ['asset_id' => $inactive->id, 'include_inactive' => true]);

        $this->assertSame($inactive->id, $result['id'] ?? null, 'include_inactive=true must resolve a deactivated asset for a deliberate lookup');
    }

    public function test_get_asset_integer_one_does_not_opt_into_inactive(): void
    {
        // The exact architecture-lane repro: get_asset(..., include_inactive=1).
        $client = Client::factory()->create(['is_active' => true]);
        $inactive = Asset::factory()->create(['client_id' => $client->id, 'hostname' => self::PROBE.'-gi1', 'is_active' => false]);

        $result = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_asset', ['asset_id' => $inactive->id, 'include_inactive' => 1]);

        $this->assertArrayHasKey('error', $result, 'get_asset include_inactive=1 (integer) must NOT resolve a deactivated asset');
    }
}
