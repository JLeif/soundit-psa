<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The `qbo_nonrecurring_sales_term_id` picker on the Integrations QBO card
 * (card revwQxh4): filled from QBO's live Terms list (a read-only query through
 * the existing QboClient), admin-only, and validated against a FRESH fetch of
 * that list.
 *
 * The Terms fixture follows Intuit's query envelope and Term schema
 * (QueryResponse.Term[] of {Id, Name, Active, Type, DueDays, ...}; source:
 * QuickBooks-V3-PHP-SDK src/Data/IPPTerm.php). Ids are strings in QBO JSON.
 */
class QboNonrecurringSalesTermSettingTest extends TestCase
{
    use RefreshDatabase;

    private const SETTING = QboSyncService::NONRECURRING_SALES_TERM_SETTING;

    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Cache::flush();
        Setting::setValue('qbo_realm_id', 'realm-1');
        Setting::setValue('qbo_access_token', 'synthetic-token');
    }

    /** @return array<string, mixed> */
    private static function termsResponse(): array
    {
        return ['QueryResponse' => [
            'startPosition' => 1,
            'maxResults' => 3,
            'Term' => [
                ['Name' => 'Due on receipt', 'Active' => true, 'Type' => 'STANDARD', 'DueDays' => 0, 'DiscountDays' => 0, 'domain' => 'QBO', 'sparse' => false, 'Id' => '1', 'SyncToken' => '0'],
                ['Name' => 'Due on receipt - manual pay', 'Active' => true, 'Type' => 'STANDARD', 'DueDays' => 0, 'domain' => 'QBO', 'sparse' => false, 'Id' => '7', 'SyncToken' => '0'],
                ['Name' => 'Net 30', 'Active' => true, 'Type' => 'STANDARD', 'DueDays' => 30, 'DiscountDays' => 0, 'domain' => 'QBO', 'sparse' => false, 'Id' => '3', 'SyncToken' => '0'],
            ],
        ], 'time' => '2026-09-30T12:00:00.000-07:00'];
    }

    private function mockQbo(array|\Throwable $termsResponse): void
    {
        $this->mock(QboClient::class, function (MockInterface $m) use ($termsResponse): void {
            $m->shouldReceive('query')->andReturnUsing(function (string $sql) use ($termsResponse) {
                $this->queries[] = $sql;
                if (str_contains($sql, 'FROM Term')) {
                    if ($termsResponse instanceof \Throwable) {
                        throw $termsResponse;
                    }

                    return $termsResponse;
                }

                return ['QueryResponse' => []];
            });
            $m->shouldNotReceive('post');
        });
    }

    private function realCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function actAs(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
    }

    private function save(string $termId)
    {
        return $this->withSession(['_token' => 'csrf'])
            ->post(route('settings.integrations.qbo.sales-term'), ['_token' => 'csrf', 'nonrecurring_sales_term_id' => $termId]);
    }

    // ── the dropdown renders the live terms ──

    public function test_the_dropdown_lists_the_live_qbo_terms_for_an_admin(): void
    {
        $this->mockQbo(self::termsResponse());
        $this->actAs(UserRole::Admin);
        Setting::setValue(self::SETTING, '7');

        $this->get(route('settings.integrations'))->assertOk()
            ->assertSee('action="'.route('settings.integrations.qbo.sales-term').'"', false)
            ->assertSee('Payment term for one-off invoices')
            ->assertSee('<option value="1" >', false)
            ->assertSee('<option value="7" selected>', false)
            ->assertSee('<option value="3" >', false)
            ->assertSee('Due on receipt - manual pay')
            ->assertSee('Net 30')
            ->assertSee("(None: use the customer's default terms)", false);

        // A read-only query on Term, active terms only; never a write.
        $termQueries = array_values(array_filter($this->queries, fn ($q) => str_contains($q, 'FROM Term')));
        $this->assertSame(['SELECT Id, Name FROM Term WHERE Active = true ORDERBY Name MAXRESULTS 1000'], $termQueries);
    }

    public function test_a_failed_terms_read_is_shown_not_rendered_as_an_empty_list(): void
    {
        $this->mockQbo(new QboClientException('boom', 500));
        $this->actAs(UserRole::Admin);
        Setting::setValue(self::SETTING, '7');

        $this->get(route('settings.integrations'))->assertOk()
            ->assertSee('Could not read payment terms from QuickBooks')
            ->assertSee('Saved term id: <code>7</code>', false)
            ->assertDontSee('id="qbo_nonrecurring_sales_term_id"', false);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unknownShapes(): array
    {
        return [
            'no QueryResponse' => [['Fault' => ['Error' => []]]],
            'Term is an object' => [['QueryResponse' => ['Term' => ['Id' => '1', 'Name' => 'x']]]],
            'row without Name' => [['QueryResponse' => ['Term' => [['Id' => '1']]]]],
            'row without Id' => [['QueryResponse' => ['Term' => [['Name' => 'Net 30']]]]],
        ];
    }

    #[DataProvider('unknownShapes')]
    public function test_an_unknown_terms_shape_fails_closed(array $response): void
    {
        $this->mockQbo($response);

        $this->expectException(QboClientException::class);
        app(QboSyncService::class)->listSalesTerms(refresh: true);
    }

    public function test_no_matching_terms_is_an_empty_list_not_an_error(): void
    {
        // QBO answers a query with no hits as a QueryResponse holding no entity key.
        $this->mockQbo(['QueryResponse' => [], 'time' => '2026-09-30T12:00:00.000-07:00']);

        $this->assertSame([], app(QboSyncService::class)->listSalesTerms(refresh: true));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_a_non_admin_sees_no_picker_and_no_terms_are_read(UserRole $role): void
    {
        $this->mockQbo(self::termsResponse());
        $this->actAs($role);

        $this->get(route('settings.integrations'))->assertOk()
            ->assertDontSee('action="'.route('settings.integrations.qbo.sales-term').'"', false)
            ->assertDontSee('Due on receipt - manual pay');
        $this->assertSame([], array_values(array_filter($this->queries, fn ($q) => str_contains($q, 'FROM Term'))));
    }

    // ── saving is admin-only and validated against the fetched list ──

    /** @return array<string, array{0: UserRole}> */
    public static function nonAdminRoles(): array
    {
        return ['tech' => [UserRole::Tech], 'billing' => [UserRole::Billing], 'contractor' => [UserRole::Contractor]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_a_non_admin_cannot_save_the_term(UserRole $role): void
    {
        $this->realCsrf();
        $this->mockQbo(self::termsResponse());
        $this->actAs($role);

        $this->save('7')->assertForbidden();
        $this->assertNull(Setting::getValue(self::SETTING));
    }

    public function test_an_admin_saves_a_term_from_the_live_list(): void
    {
        $this->realCsrf();
        $this->mockQbo(self::termsResponse());
        $this->actAs(UserRole::Admin);

        $this->save('7')->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Non-recurring invoices will be pushed with the QuickBooks term "Due on receipt - manual pay".');
        $this->assertSame('7', Setting::getValue(self::SETTING));
    }

    public function test_an_id_not_in_the_live_list_is_refused_and_nothing_changes(): void
    {
        $this->realCsrf();
        $this->mockQbo(self::termsResponse());
        $this->actAs(UserRole::Admin);
        Setting::setValue(self::SETTING, '3');

        $this->save('99')->assertRedirect(route('settings.integrations'))
            ->assertSessionHasErrors('nonrecurring_sales_term_id');
        $this->assertSame('3', Setting::getValue(self::SETTING));
    }

    public function test_validation_uses_a_fresh_fetch_not_the_cached_list(): void
    {
        $this->realCsrf();
        $this->actAs(UserRole::Admin);
        // A cached list still carries term 5, deleted in QBO since.
        Cache::put('qbo:terms', [['Id' => '5', 'Name' => 'Old term']], now()->addHour());
        $this->mockQbo(self::termsResponse());

        $this->save('5')->assertSessionHasErrors('nonrecurring_sales_term_id');
        $this->assertNull(Setting::getValue(self::SETTING));
        $this->assertNotEmpty(array_filter($this->queries, fn ($q) => str_contains($q, 'FROM Term')));
    }

    public function test_an_unreadable_list_refuses_the_save_and_keeps_the_stored_term(): void
    {
        $this->realCsrf();
        $this->mockQbo(new QboClientException('boom', 503));
        $this->actAs(UserRole::Admin);
        Setting::setValue(self::SETTING, '3');

        $this->save('7')->assertRedirect(route('settings.integrations'))->assertSessionHas('error');
        $this->assertSame('3', Setting::getValue(self::SETTING));
    }

    public function test_an_admin_clears_the_term_without_a_qbo_read(): void
    {
        $this->realCsrf();
        $this->mockQbo(new QboClientException('must not be called', 500));
        $this->actAs(UserRole::Admin);
        Setting::setValue(self::SETTING, '7');

        $this->save('')->assertRedirect(route('settings.integrations'))->assertSessionHas('success');
        $this->assertSame('', Setting::getValue(self::SETTING));
        $this->assertSame([], $this->queries);
    }
}
