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
        // r5 contract:8: Http::assertNothingSent() only inspects $recorded, and Factory populates
        // that solely when fake()/record() has set the recording flag. record() turns those
        // assertions into real ones; preventStrayRequests() stays, so an unfaked call is still an
        // error rather than a silently recorded one.
        //
        // r6 context:2 corrected the claim that USED to sit here. I had written that without
        // record() "every assertNothingSent() in this file asserted an always-empty array". That is
        // true only of the tests whose request never reaches a fake: the ones that call
        // fakeCompanies() first have recording switched on by fake() itself, so their assertions
        // were already live. record() is what covers the REMAINDER -- the paths that are refused
        // before any HTTP call. Overstating the blast radius of my own fix is the same error class
        // as understating it.
        //
        // r6 diff:9: this line is the reason those assertions can fail at all, and nothing pinned
        // it, so deleting it would silently hollow them out rather than break a test.
        // test_setup_records_http_so_assert_nothing_sent_can_actually_fail is that pin.
        Http::record();
        Setting::setEncrypted('autoelevate_api_key', 'synthetic-only-key');
    }

    private function fakeCompanies(array $companies): void
    {
        Http::fake([self::BASE.'/api/v1/companies*' => Http::response(self::envelope($companies), 200)]);
    }

    /**
     * r6 diff:9. setUp()'s Http::record() is what makes every Http::assertNothingSent() in this
     * file capable of failing, and nothing pinned it: deleting that one line would leave all five
     * of them asserting an always-empty array, green and meaningless.
     *
     * This asserts the MECHANISM rather than the source text -- a test that greps for
     * "Http::record" would pass against a file where the call had been moved somewhere it never
     * runs.
     *
     * MY FIRST VERSION OF THIS TEST WAS ITSELF VACUOUS, and the red check is what caught it. It
     * called Http::fake() and then asserted recording was on -- but fake() SETS the recording flag
     * itself, so it passed happily with setUp()'s record() deleted. It measured fake(), not the
     * line it claimed to pin. The fix is to touch no fake at all: read the Factory's recording
     * flag directly, which only setUp()'s record() can have set.
     */
    public function test_setup_records_http_so_assert_nothing_sent_can_actually_fail(): void
    {
        $factory = Http::getFacadeRoot();
        $recording = (new \ReflectionProperty($factory, 'recording'))->getValue($factory);

        $this->assertTrue($recording, 'setUp() must leave HTTP recording ON; without it Http::assertNothingSent() inspects an always-empty array on every refusal path and cannot fail');
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
        $this->post(route('settings.autoelevate-companies.auto-match'))->assertForbidden();
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

    /**
     * #2108: the vendor returning zero companies must never become "unmap everything".
     *
     * Clear-then-apply reads the new state off the rendered form, so an empty post and a
     * deliberate unmap-all are the same bytes. The screen used to keep its Save button on the
     * empty branch, so one click nulled every mapping and flashed success. That empty screen is
     * reached when the vendor genuinely lists no companies -- NOT on a degraded read or a bad
     * key, both of which redirect away before the view renders. The Blade now withholds Save;
     * this test covers the server-side refusal, which still answers a direct or replayed POST.
     */
    public function test_save_with_no_companies_submitted_keeps_every_mapping(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => []])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings')
            ->assertSessionMissing('success');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id, 'mapping survives an empty submission');
        $this->assertSame(self::COMPANY_B, $b->fresh()->autoelevate_company_id, 'mapping survives an empty submission');
        Http::assertNothingSent();
    }

    /** A wholly absent `mappings` key is the same refusal as an empty array, not a silent wipe. */
    public function test_save_with_a_missing_mappings_key_keeps_every_mapping(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), [])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
    }

    /**
     * r6 contract:4. update() carries `if (! is_array($mappings)) { $mappings = []; }` with a
     * comment asserting it is the sole guard against a PRESENT null wiping every mapping -- and
     * nothing in this file exercised that path. Every other guard test posts [], a scalar, or a
     * file, all of which the malformed-payload branch catches first. r4 diff:8 had already argued
     * this line was dead and removable, so an untested line the code calls load-bearing is exactly
     * the line someone deletes next round.
     *
     * postJson is required: a form POST cannot express a present null (it arrives as the string
     * "" or not at all), which is why input('mappings', []) returns null here and [] elsewhere.
     *
     * I first asserted 422 here and the test told me otherwise: the controller does not validate,
     * it coerces to [] and takes the `$mappings === []` refusal, which redirects back (302) with
     * an error on the `mappings` key. Pinned to what executes, not to what I expected.
     */
    public function test_save_with_a_present_null_mappings_keeps_every_mapping(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->postJson(route('settings.autoelevate-companies.update'), ['mappings' => null])
            ->assertStatus(302)
            ->assertSessionHasErrors('mappings');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id, 'a present null must not reach the clear-then-apply write');
        $this->assertSame(self::COMPANY_B, $b->fresh()->autoelevate_company_id);
    }

    /** A non-array `mappings` (scalar) must also refuse rather than coerce to "clear all". */
    public function test_save_with_a_scalar_mappings_value_keeps_every_mapping(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => 'all'])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);

        // r3 diff:6: malformed input must not be reported as an empty form. Without this the
        // two causes were indistinguishable to the admin and to this test.
        $this->assertStringContainsString(
            'unexpected format',
            (string) session('errors')->first('mappings'),
            'a non-array payload must be diagnosed as malformed, not as "nothing submitted"'
        );
        $this->assertStringNotContainsString('No AutoElevate companies were submitted', (string) session('errors')->first('mappings'));
    }

    /**
     * r5 diff:1: an ERRORED upload named `mappings` (too large, partial, no tmp dir) has an empty
     * path, so hasFile() is false and input() is null -- the previous guard missed it and told the
     * admin the form was empty. It must be reported as malformed, like any other file part.
     */
    public function test_save_with_an_errored_file_upload_named_mappings_is_reported_as_malformed(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $errored = new \Illuminate\Http\UploadedFile('', 'mappings.csv', 'text/csv', UPLOAD_ERR_INI_SIZE, true);
        $this->assertFalse($errored->isValid(), 'fixture must be an errored upload, or this test proves nothing');

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => $errored])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings');

        $this->assertSame(
            'The AutoElevate mapping form was submitted in an unexpected format, so nothing was changed. Existing mappings were kept. Reload the Map companies screen and try again.',
            session('errors')->get('mappings')[0],
            'an errored upload must be diagnosed as malformed, not as an empty submission'
        );
        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
    }

    /**
     * r4 context:8. index() and autoMatch() redirect away when AutoElevate is unconfigured, but
     * update() did not, leaving the destructive clear-then-apply write reachable by a direct or
     * replayed POST on an installation whose key had been removed -- a state from which the screen
     * itself cannot be opened. The write path is now gated like the read paths.
     */
    public function test_update_is_refused_when_autoelevate_is_not_configured(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        Setting::setEncrypted('autoelevate_api_key', '');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->post(route('settings.autoelevate-companies.update'), [
            'mappings' => [self::COMPANY_B => (string) $a->id],
        ])->assertRedirect(route('settings.integrations'))
            // r5 context:2: pinning only the redirect let withErrors() be dropped with the suite
            // green, bouncing the admin to Integrations with no explanation. Pin the message too.
            ->assertSessionHasErrors('mappings');

        // The mapping must be untouched: an unconfigured install must not rewrite mappings.
        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
    }

    /**
     * r4 diff:9. The malformed-payload guard originally keyed on $request->has('mappings'), which
     * reads all() INCLUDING uploaded files, while $request->input('mappings', []) excludes them.
     * A multipart POST carrying a FILE named `mappings` made has() true and input() return the []
     * default, so genuinely malformed input was reported as "nothing was submitted". Keying on the
     * VALUE closes that gap. Nothing is cleared on either path.
     */
    public function test_save_with_a_file_named_mappings_is_reported_as_malformed(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), [
                'mappings' => \Illuminate\Http\UploadedFile::fake()->create('mappings.csv', 1),
            ])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
        $this->assertStringContainsString(
            'unexpected format',
            (string) session('errors')->first('mappings'),
            'a file payload must be diagnosed as malformed, not as "nothing submitted"'
        );
    }

    /**
     * The guard must not block ordinary unmapping: a form that DID list a company can still
     * clear it. Refusing only the no-keys case keeps one-at-a-time removal working.
     */
    public function test_unmapping_a_listed_company_still_works(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->post(route('settings.autoelevate-companies.update'), ['mappings' => [
            self::COMPANY_A => '',
            self::COMPANY_B => $b->id,
        ]])->assertSessionHas('success', 'Saved 1 AutoElevate company mapping(s).');

        $this->assertNull($a->fresh()->autoelevate_company_id, 'a listed company can still be unmapped');
        $this->assertSame(self::COMPANY_B, $b->fresh()->autoelevate_company_id);
    }

    /** The empty screen must not render a Save button that can only destroy or fail. */
    public function test_index_with_zero_companies_withholds_save_and_warns_about_held_mappings(): void
    {
        $this->fakeCompanies([]);
        Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.index'))
            ->assertOk()
            ->assertSee('AutoElevate returned zero companies for this key.')
            ->assertSee('1 client(s) still hold a mapping')
            ->assertDontSee('Save Mappings');
    }

    /**
     * r7 contract:7. Every other test of the zero-company screen creates at least one mapped
     * client, so all of them exercise the TRUE branch of `@if($mappedClients->isNotEmpty())`.
     * The false branch -- nothing listed AND nothing at risk -- had no control at all, so a
     * change that made the alarming "client(s) still hold a mapping" line render
     * unconditionally would have passed the whole suite.
     *
     * This pins the reassuring case: the empty-list notice still appears, Save is still
     * withheld (no companies to submit), and the screen does NOT claim anything is at risk.
     */
    public function test_zero_companies_and_zero_mapped_clients_warns_about_nothing_at_risk(): void
    {
        $this->fakeCompanies([]);
        $this->assertSame(0, Client::whereNotNull('autoelevate_company_id')->count(), 'fixture must have no mapped clients, or this test proves nothing');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.index'))
            ->assertOk()
            ->assertSee('AutoElevate returned zero companies for this key.')
            ->assertDontSee('still hold a mapping')
            ->assertDontSee('Save Mappings');
    }

    /**
     * contract:8 from the r2 held review, verified at source before being believed.
     *
     * MEASURED. My first two descriptions of this mechanism were WRONG and the r3 review caught
     * the second one; this docblock states what the code actually executes.
     *
     * Two vendor companies whose names normalize to the same key ("Acme Manufacturing" and
     * "ACME  manufacturing" both normalize to "acmemanufacturing") compete for the single
     * unmapped client in that bucket. companies() sorts by name (strcasecmp), so "ACME
     * manufacturing" is visited FIRST, claims the client, and unset()s the bucket. The second
     * company then finds an EMPTY bucket: count($candidates) === 0, which is neither the >1
     * ambiguous branch nor the ===1 match branch.
     *
     * So exactly ONE write happens and there is NO overwrite -- the loser is simply dropped.
     * The operator is told "Auto-matched 1 company(ies) by name." and NOTHING is reported as
     * ambiguous, even though this is precisely the ambiguity the class docblock promises never
     * to guess at. Which of the two colliding companies wins is decided by name sort order,
     * not by anything the operator can see or control.
     *
     * (For the record: I first predicted the second company fell through uncounted -- right
     * outcome, wrong reason; then that it overwrote the first -- wrong. Only instrumenting the
     * loop and checking the usort in companies() settled it.)
     *
     * This test PINS the current behaviour rather than asserting a fix: autoMatch() is outside
     * this leg's scope (the empty-submission guard), so the correction belongs to the parent
     * card as a product decision, not slipped into a residual leg.
     */
    public function test_automatch_silently_skips_a_second_company_sharing_a_normalized_name(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Manufacturing', 'autoelevate_company_id' => null]);
        $this->fakeCompanies([
            self::company(self::COMPANY_A, 'Acme Manufacturing'),
            self::company(self::COMPANY_B, 'ACME  manufacturing'),
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->post(route('settings.autoelevate-companies.auto-match'));

        // Name sort order decided the winner; the loser was dropped, never overwritten.
        $this->assertSame(strtolower(self::COMPANY_B), $client->fresh()->autoelevate_company_id);
        $this->assertSame(0, Client::where('autoelevate_company_id', strtolower(self::COMPANY_A))->count());

        // The operator is told ONE company matched -- a plausible, unalarming message -- with
        // no indication that two companies collided on one client or that A's mapping was lost.
        $response->assertRedirect(route('settings.autoelevate-companies.index'));
        $this->assertStringContainsString('Auto-matched 1 company(ies) by name.', (string) session('success'));
        $this->assertStringNotContainsString('left unmapped', (string) session('success'));
    }

    /**
     * Pins the INSTALL.md caveat that an UNLISTED company's mapping is destroyed by the next
     * save. The clear-then-apply transaction nulls every mapping before re-applying only the
     * submitted ones, so absence from the form is not protection. Documented behaviour that
     * no test pins is exactly how a later "harmless" refactor silently changes it.
     */
    public function test_saving_clears_a_mapping_whose_company_the_vendor_no_longer_lists(): void
    {
        $listed = Client::factory()->create(['autoelevate_company_id' => null]);
        $unlisted = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_B]);
        // r3 diff:7: no fakeCompanies() here. update() derives state solely from the submitted
        // form and never reads the vendor, so a fake would be inert scenery implying a vendor
        // dependency that does not exist. What this proves is exactly: a company key absent
        // from the POST body has its mapping cleared -- which is the unlisted-company case,
        // because an unlisted company has no row on the form to submit.
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->post(route('settings.autoelevate-companies.update'), [
            'mappings' => [self::COMPANY_A => (string) $listed->id],
        ]);

        $this->assertSame(strtolower(self::COMPANY_A), $listed->fresh()->autoelevate_company_id);
        $this->assertNull(
            $unlisted->fresh()->autoelevate_company_id,
            'An unlisted company mapping must be cleared by the next save - INSTALL.md documents this.'
        );
    }

    /**
     * contract:7 from the r1 held review: the warning says "client(s)", but the collection it
     * counted was keyBy(company id), which collapses two clients sharing one company into one
     * entry. The screen then under-reports what an empty-screen save would put at risk.
     * Two clients, one company id => the warning must say 2.
     */
    public function test_empty_state_warning_counts_clients_not_distinct_companies(): void
    {
        $this->fakeCompanies([]);
        Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.index'))
            ->assertOk()
            ->assertSee('2 client(s) still hold a mapping')
            ->assertDontSee('Save Mappings');
    }

    /** Positive control: with companies listed, Save is present. */
    public function test_index_with_companies_renders_save(): void
    {
        $this->fakeCompanies([self::company(self::COMPANY_A, 'Acme Manufacturing')]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.index'))->assertOk()->assertSee('Save Mappings');
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

        $this->post(route('settings.autoelevate-companies.auto-match'))
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

        $this->post(route('settings.autoelevate-companies.auto-match'))->assertSessionHas('success', 'Auto-matched 1 company(ies) by name.');
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

        $this->post(route('settings.autoelevate-companies.auto-match'))
            ->assertSessionHas('info', 'No new matches found. Companies may need manual mapping.');
        $this->assertSame(self::uuid(1), $acme->fresh()->autoelevate_company_id, 'existing mapping survives a name match on another company');
    }

    public function test_auto_match_with_nothing_to_do_reports_info(): void
    {
        $this->fakeCompanies([self::company(self::uuid(1), 'Nobody')]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->post(route('settings.autoelevate-companies.auto-match'))
            ->assertSessionHas('info', 'No new matches found. Companies may need manual mapping.');
    }

    public function test_auto_match_with_a_degraded_read_changes_nothing(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 503)]);
        $acme = Client::factory()->create(['name' => 'Acme']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->post(route('settings.autoelevate-companies.auto-match'))
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'http_503'));
        $this->assertNull($acme->fresh()->autoelevate_company_id);
    }

    public function test_save_refuses_one_client_under_two_companies_and_changes_nothing(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['name' => 'Acme Inc']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // The screen renders the same client list in every row, so this is a normal UI action:
        // it must be refused, not applied with the last write winning and the flash saying 2.
        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => [
                self::COMPANY_A => $b->id,
                self::COMPANY_B => $b->id,
            ]])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings')
            ->assertSessionMissing('success');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id, 'the clear step never ran');
        $this->assertNull($b->fresh()->autoelevate_company_id);
    }

    public function test_save_refuses_the_same_company_under_two_case_variant_keys(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $b = Client::factory()->create(['name' => 'Acme Inc']);
        $c = Client::factory()->create(['name' => 'Beta Inc']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // The uuid key is accepted in either hex case and the write path lowercases it, so these
        // two keys are one company. Applied, both UPDATEs would write the same company id and
        // two clients would resolve to one AutoElevate company (the second invisible on this
        // screen, because the index keys mapped clients by the lowercased id).
        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => [
                self::COMPANY_B => $b->id,
                strtoupper(self::COMPANY_B) => $c->id,
            ]])
            ->assertRedirect(route('settings.autoelevate-companies.index'))
            ->assertSessionHasErrors('mappings')
            ->assertSessionMissing('success');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id, 'the clear step never ran');
        $this->assertNull($b->fresh()->autoelevate_company_id);
        $this->assertNull($c->fresh()->autoelevate_company_id);
        $this->assertSame(0, Client::where('autoelevate_company_id', strtolower(self::COMPANY_B))->count());
    }

    public function test_save_refuses_a_client_id_that_does_not_exist(): void
    {
        $a = Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from(route('settings.autoelevate-companies.index'))
            ->post(route('settings.autoelevate-companies.update'), ['mappings' => [self::COMPANY_B => 999999]])
            ->assertSessionHasErrors('mappings')
            ->assertSessionMissing('success');

        $this->assertSame(self::COMPANY_A, $a->fresh()->autoelevate_company_id);
    }

    public function test_a_mapped_client_that_is_no_longer_operational_is_offered_and_survives_a_save(): void
    {
        $this->fakeCompanies([
            self::company(self::COMPANY_A, 'Delta Freight'),
            self::company(self::COMPANY_B, 'Zeta Widgets'),
        ]);
        $delta = Client::factory()->create(['name' => 'Delta Freight', 'is_active' => false, 'autoelevate_company_id' => self::COMPANY_A]);
        $zeta = Client::factory()->create(['name' => 'Zeta Widgets']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        // Without an <option> the select posts "" and clear-then-apply destroys the mapping.
        $response = $this->get(route('settings.autoelevate-companies.index'))->assertOk();
        $this->assertContains($delta->id, $response->viewData('allClients')->pluck('id')->all(), 'a mapped non-operational client must still be selectable');

        $this->post(route('settings.autoelevate-companies.update'), ['mappings' => [
            self::COMPANY_A => $delta->id,
            self::COMPANY_B => $zeta->id,
        ]])->assertSessionHas('success', 'Saved 2 AutoElevate company mapping(s).');

        $this->assertSame(self::COMPANY_A, $delta->fresh()->autoelevate_company_id, 'unrelated save must not clear it');
        $this->assertSame(self::COMPANY_B, $zeta->fresh()->autoelevate_company_id);
    }

    public function test_two_clients_sharing_a_company_id_are_both_offered_in_the_dropdown(): void
    {
        // r4 diff:2: the original comment here claimed "autoMatch() writes the vendor id verbatim"
        // -- a claim I retracted last round, because companies() lowercases every id before either
        // writer sees it. Two clients CAN still hold one company id, but the reachable route is
        // SoftDeletes (r4 context:2), not case divergence: the clear step is scoped to non-trashed
        // rows, so a trashed client keeps its id while a live one is mapped to the same company,
        // and restoring it yields two live rows sharing an id. index() keys the lookup collection
        // with strtolower(), collapsing them to one row, so the dropdown must offer BOTH or the
        // collapsed client has no <option>, posts nothing, and the next clear-then-apply save
        // destroys its mapping behind a success flash.
        //
        // r5 diff:6: this fixture USED to build the shared-id state by case divergence
        // (strtoupper vs strtolower) -- precisely the route the comment above declares
        // unproducible, since companies() lowercases every id before either writer sees it.
        // Build it by the route the rationale actually names: trash a mapped client, run the
        // clear step (which the SoftDeletes global scope skips for trashed rows), then restore.
        $this->fakeCompanies([self::company(self::COMPANY_A, 'Delta Freight')]);
        //
        // The collapsed client must ALSO be outside Client::operational(), or $allClients would
        // already carry it from the operational query and the concat would be doing no work --
        // the fixture would pass against a mutant that concats the keyed collection. Measured:
        // with both clients operational, concat($mappedClients) survives.
        $held = Client::factory()->create(['name' => 'Held Co', 'is_active' => false, 'autoelevate_company_id' => self::COMPANY_A]);
        $held->delete();
        Client::whereNotNull('autoelevate_company_id')->update(['autoelevate_company_id' => null]);
        $held->restore();
        $kept = Client::factory()->create(['name' => 'Kept Co', 'autoelevate_company_id' => self::COMPANY_A]);
        $this->assertSame(self::COMPANY_A, $held->fresh()->autoelevate_company_id, 'the clear step must skip trashed rows, or this fixture proves nothing');
        $this->assertFalse($held->fresh()->is_active, 'the collapsed client must be non-operational, or the concat is not exercised');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $offered = $this->get(route('settings.autoelevate-companies.index'))->assertOk()
            ->viewData('allClients')->pluck('id')->all();

        $this->assertContains($kept->id, $offered);
        $this->assertContains($held->id, $offered, 'a client collapsed by keyBy() must still be selectable');
    }

    public function test_empty_state_warning_counts_mapped_rows_not_the_dropdown_collection(): void
    {
        // r3 diff:2 was right that the old version of this test grepped the Blade SOURCE for one
        // spelling of an expression, rendering nothing. r4 diff:10 was then right that my
        // behavioural replacement duplicated test_empty_state_warning_counts_clients_not_distinct
        // _companies exactly. r5 diff:5 was right AGAIN: my second replacement rested on a false
        // premise -- I wrote that Client::operational() scopes $mappedClients, but index() builds
        // it with keyBy() alone (no operational() scope), so a non-operational mapped client is
        // counted either way and the test passed against the very mutant it claimed to catch.
        // Measured: mutating the count to $mappedClients->count() left this test GREEN and only
        // its sibling failed.
        //
        // What actually distinguishes the two collections is keyBy() COLLAPSING equal keys, and
        // the sibling already pins that for two clients sharing one id. The remaining
        // undiscriminated risk is the count being taken from the DROPDOWN collection ($allClients,
        // which concats operational clients) rather than the mapped rows: that over-reports by
        // counting unmapped clients. Pin the count against an operational UNMAPPED client, which
        // no other test in this file supplies.
        //
        // r6 diff:7/context:3/contract:8 said this test "exercises no loud failure and nothing
        // about under-reporting". On the NAME they were right and it is now renamed for what it
        // actually pins. On the substance they were wrong, and I checked by mutation rather than by
        // reading: over-counting the source ($mappedClientRows->count() + 1) FAILS this test
        // (1 failed, 2 assertions). It is a real control, so it stays.
        Client::factory()->create(['autoelevate_company_id' => self::COMPANY_A]);
        Client::factory()->create(['autoelevate_company_id' => null]);
        $this->fakeCompanies([]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('settings.autoelevate-companies.index'))
            ->assertOk()
            ->assertSee('1 client(s) still hold a mapping')
            ->assertDontSee('2 client(s) still hold a mapping');
    }

    public function test_integrations_settings_links_to_map_companies_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('settings.integrations'))->assertOk()->assertSee(route('settings.autoelevate-companies.index'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Tech]));
        $this->get(route('settings.integrations'))->assertOk()->assertDontSee(route('settings.autoelevate-companies.index'));
    }
}
