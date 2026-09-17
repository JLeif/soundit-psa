<?php

namespace Tests\Feature\AutoElevate;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoElevateCompanyMappingTest extends TestCase
{
    use AutoElevateFixtures;
    use RefreshDatabase;

    private const BASE = 'https://partner-api.autoelevate.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    private function fakeCompanies(array $companies): void
    {
        Http::fake([self::BASE.'/api/v1/companies*' => Http::response(self::envelope($companies), 200)]);
    }

    public function test_migration_adds_a_nullable_indexed_uuid_column_only(): void
    {
        $this->assertTrue(Schema::hasColumn('clients', 'autoelevate_company_id'));
        $indexes = collect(Schema::getIndexes('clients'))->filter(fn ($i) => $i['columns'] === ['autoelevate_company_id']);
        $this->assertCount(1, $indexes, 'one index on the column');
        $this->assertFalse($indexes->first()['unique'], 'plain index: uniqueness is enforced by clear-then-apply');
        $client = Client::factory()->create();
        $this->assertNull($client->fresh()->autoelevate_company_id, 'nullable, no backfill');
        $client->update(['autoelevate_company_id' => self::COMPANY_A]);
        $this->assertSame(self::COMPANY_A, $client->fresh()->autoelevate_company_id);
    }

    /** @return array<string, array{UserRole}> */
    public static function nonAdminRoles(): array
    {
        return collect(UserRole::cases())->reject(fn ($r) => $r === UserRole::Admin)
            ->mapWithKeys(fn ($r) => [$r->value => [$r]])->all();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_every_mapping_route_is_admin_only(UserRole $role): void
    {
        $this->fakeCompanies([self::company(self::COMPANY_A, 'Acme Manufacturing')]);
        $client = Client::factory()->create(['name' => 'Acme Manufacturing']);
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get(route('settings.autoelevate-companies.index'))->assertForbidden();
        $this->get(route('settings.autoelevate-companies.auto-match'))->assertForbidden();
        $this->post(route('settings.autoelevate-companies.update'), ['mappings' => [self::COMPANY_A => $client->id]])->assertForbidden();

        $this->assertNull($client->fresh()->autoelevate_company_id, $role->value.' must not write a mapping');
        Http::assertNothingSent();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Http::fake();
        $this->get(route('settings.autoelevate-companies.index'))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_index_lists_companies_with_current_mapping(): void
    {
        $this->fakeCompanies([
            self::company(self::COMPANY_B, 'Zeta Widgets'),
            self::company(self::COMPANY_A, 'Acme Manufacturing'),
        ]);
        $mapped = Client::factory()->create(['name' => 'Acme Client', 'autoelevate_company_id' => strtoupper(self::COMPANY_A)]);
        Client::factory()->create(['name' => 'Free Client']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->get(route('settings.autoelevate-companies.index'));

        $response->assertOk()
            ->assertSeeInOrder(['Acme Manufacturing', 'Zeta Widgets'])
            ->assertSee('name="mappings['.self::COMPANY_A.']"', false)
            ->assertSee('data-selected="'.$mapped->id.'"', false)
            ->assertSee('Free Client')
            ->assertDontSee('synthetic-only-key');
        $this->assertSame($mapped->id, $response->viewData('mappedClients')->get(self::COMPANY_A)->id, 'mapping lookup is case-insensitive on the uuid');
    }

    public function test_index_with_unconfigured_key_redirects_without_a_vendor_call(): void
    {
        Setting::setEncrypted('autoelevate_api_key', '');
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.autoelevate-companies.index'))->assertRedirect(route('settings.integrations'))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_index_with_a_degraded_read_shows_an_error_never_an_empty_list(): void
    {
        Http::fake([self::BASE.'/*' => Http::response([self::company(self::COMPANY_A, 'Acme')], 200)]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.autoelevate-companies.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'envelope_drift') && str_contains($m, 'Nothing was changed'));
    }

    public function test_save_clears_then_applies_in_one_transaction(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        $c = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // Re-point A → c, drop B, leave a third company unmapped.
        $this->post(route('settings.autoelevate-companies.update'), ['mappings' => [
            strtoupper(self::COMPANY_A) => $c->id,
            self::COMPANY_B => '',
            self::uuid(3) => null,
        ]])->assertRedirect(route('settings.autoelevate-companies.index'))->assertSessionHas('success', 'Saved 1 AutoElevate company mapping(s).');

        $this->assertNull($a->fresh()->autoelevate_company_id);
        $this->assertNull($b->fresh()->autoelevate_company_id);
        $this->assertSame(self::COMPANY_A, $c->fresh()->autoelevate_company_id, 'stored lowercase');
        Http::assertNothingSent();
    }

    public function test_save_rejects_a_non_uuid_company_key_and_changes_nothing(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => ['42' => $a->id]])
            ->assertRedirect(route('settings.autoelevate-companies.index'))->assertSessionHasErrors('mappings');
        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
    }

    public function test_auto_match_by_normalized_name_fills_only_unmapped_and_skips_ambiguous(): void
    {
        $this->fakeCompanies([
            self::company(self::uuid(1), 'Acme Manufacturing, LLC'),   // → exact one client after normalization
            self::company(self::uuid(2), 'Blue Harbor Dental'),        // ambiguous: two clients normalize alike
            self::company(self::uuid(3), 'Coastal Legal'),             // already mapped elsewhere: skipped
            self::company(self::uuid(4), 'Nobody Matches Inc'),        // unmatched
            self::company(self::uuid(5), 'Delta Freight'),             // matches a client that is NOT operational: skipped
        ]);
        $acme = Client::factory()->create(['name' => 'ACME manufacturing LLC.']);
        $blue1 = Client::factory()->create(['name' => 'Blue Harbor Dental']);
        $blue2 = Client::factory()->create(['name' => 'Blue-Harbor Dental']);
        $coastalOwner = Client::factory()->create(['name' => 'Some Other Client', 'autoelevate_company_id' => self::uuid(3)]);
        $coastalByName = Client::factory()->create(['name' => 'Coastal Legal']);
        $delta = Client::factory()->create(['name' => 'Delta Freight', 'is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.auto-match'))
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHas('success', 'Auto-matched 1 company(ies) by name. 1 company(ies) left unmapped: more than one client shares that name.');

        $this->assertSame(self::uuid(1), $acme->fresh()->autoelevate_company_id);
        $this->assertNull($blue1->fresh()->autoelevate_company_id, 'ambiguous name is never guessed');
        $this->assertNull($blue2->fresh()->autoelevate_company_id, 'ambiguous name is never guessed');
        $this->assertSame(self::uuid(3), $coastalOwner->fresh()->autoelevate_company_id, 'existing mapping untouched');
        $this->assertNull($coastalByName->fresh()->autoelevate_company_id, 'already-mapped company is skipped even with a name match');
        $this->assertNull($delta->fresh()->autoelevate_company_id, 'non-operational client is not matched');
        $this->assertSame(1, Client::where('autoelevate_company_id', self::uuid(1))->count());
    }

    public function test_auto_match_does_not_map_one_client_to_two_companies(): void
    {
        $this->fakeCompanies([
            self::company(self::uuid(1), 'Acme'),
            self::company(self::uuid(2), 'ACME'),
        ]);
        $acme = Client::factory()->create(['name' => 'Acme']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.auto-match'))->assertSessionHas('success', 'Auto-matched 1 company(ies) by name.');
        $this->assertSame(self::uuid(1), $acme->fresh()->autoelevate_company_id, 'first company in name order wins; the second finds no free client');
    }

    public function test_auto_match_never_overwrites_an_already_mapped_client(): void
    {
        // Client is mapped to company 1 (by hand); company 2 carries the client's name. The
        // company-side guard cannot catch this — company 2 is unmapped — so the client-side
        // whereNull is what keeps the manual mapping intact.
        $this->fakeCompanies([self::company(self::uuid(2), 'Acme')]);
        $acme = Client::factory()->create(['name' => 'Acme', 'autoelevate_company_id' => self::uuid(1)]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.auto-match'))
            ->assertSessionHas('info', 'No new matches found. Companies may need manual mapping.');
        $this->assertSame(self::uuid(1), $acme->fresh()->autoelevate_company_id, 'existing mapping survives a name match on another company');
    }

    public function test_auto_match_with_nothing_to_do_reports_info(): void
    {
        $this->fakeCompanies([self::company(self::uuid(1), 'Nobody')]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.autoelevate-companies.auto-match'))
            ->assertSessionHas('info', 'No new matches found. Companies may need manual mapping.');
    }

    public function test_auto_match_with_a_degraded_read_changes_nothing(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 503)]);
        $acme = Client::factory()->create(['name' => 'Acme']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.autoelevate-companies.auto-match'))
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'http_503'));
        $this->assertNull($acme->fresh()->autoelevate_company_id);
    }

    public function test_integrations_settings_links_to_map_companies_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.integrations'))->assertOk()->assertSee(route('settings.autoelevate-companies.index'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Tech]));
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee(route('settings.autoelevate-companies.index'));
    }
}
