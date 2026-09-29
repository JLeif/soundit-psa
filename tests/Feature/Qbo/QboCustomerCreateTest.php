<?php

namespace Tests\Feature\Qbo;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboCustomerCreateException;
use App\Services\Qbo\QboSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #3737: create a QuickBooks customer from the client Integrations tab.
 *
 * QBO is driven through a real Guzzle stack (MockHandler + history), as the
 * other Qbo tests do, so the assertions read the requests QboClient actually
 * sent. "No POST was sent" is asserted from that history, never inferred.
 *
 * Fixture shapes come from Intuit's published Accounting OpenAPI (harvested
 * byte-identical as api-evangelist/intuit openapi/_original/quickbooks-accounting.yml):
 * the create answers CustomerResponse {"Customer": {...}, "time": ...}; a query
 * answers {"QueryResponse": {"Customer": [...], "startPosition", "maxResults"}};
 * an error answers {"Fault": {"Error": [{Message, Detail, code}], "type"}}.
 */
class QboCustomerCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: Request, response: mixed}> */
    private array $history = [];

    private MockHandler $mock;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('qbo_environment', 'sandbox');
        Setting::setValue('qbo_realm_id', '4620816365');
        Setting::setEncrypted('qbo_access_token', 'test-access-token');
        Setting::setValue('qbo_token_expires_at', now()->addHour()->toDateTimeString());

        $this->history = [];
        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->app->instance(QboClient::class, new QboClient(new GuzzleClient(['handler' => $stack])));

        $this->logs = [];
        Log::listen(function (MessageLogged $record): void {
            $this->logs[] = $record;
        });
    }

    // ── fixtures ──

    /** @param list<array<string, mixed>> $customers */
    private function queryPage(array $customers): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'QueryResponse' => array_filter([
                'Customer' => $customers ?: null,
                'startPosition' => 1,
                'maxResults' => count($customers),
            ], fn ($v) => $v !== null),
            'time' => '2026-01-15T10:30:00Z',
        ]));
    }

    private function createdResponse(string $id, string $displayName): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'Customer' => [
                'Id' => $id,
                'SyncToken' => '0',
                'DisplayName' => $displayName,
                'CompanyName' => $displayName,
                'Active' => true,
            ],
            'time' => '2026-01-15T10:30:00Z',
        ]));
    }

    private function faultResponse(int $status, string $code, string $message, string $detail): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode([
            'Fault' => [
                'Error' => [['Message' => $message, 'Detail' => $detail, 'code' => $code]],
                'type' => 'ValidationFault',
            ],
            'time' => '2026-01-15T10:30:00Z',
        ]));
    }

    private function makeClient(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge(['name' => 'Harbor Test Co'], $attrs));
    }

    /** @return list<Request> */
    private function posts(): array
    {
        return array_values(array_map(
            fn ($t) => $t['request'],
            array_filter($this->history, fn ($t) => $t['request']->getMethod() === 'POST'),
        ));
    }

    /** @return list<string> the `query` parameter of every GET query sent */
    private function queries(): array
    {
        $out = [];
        foreach ($this->history as $t) {
            if ($t['request']->getMethod() === 'GET') {
                parse_str($t['request']->getUri()->getQuery(), $q);
                $out[] = (string) ($q['query'] ?? '');
            }
        }

        return $out;
    }

    private function create(Client $client): array
    {
        return app(QboSyncService::class)->createQboCustomerForClient($client);
    }

    private function createExpectingFailure(Client $client): QboCustomerCreateException
    {
        try {
            $this->create($client);
        } catch (QboCustomerCreateException $e) {
            return $e;
        }

        $this->fail('Expected QboCustomerCreateException.');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ── service: happy path and payload ──

    public function test_creates_customer_with_every_held_field_and_links_it(): void
    {
        $client = $this->makeClient([
            'name' => '  Harbor Test Co ',
            'email' => 'billing@harbor.example',
            'phone' => '5550100',
            'address_line1' => '1 Dock St',
            'address_line2' => 'Suite 2',
            'city' => 'Porttown',
            'state' => 'WA',
            'postcode' => '98000',
        ]);
        $this->mock->append(
            $this->queryPage([['Id' => '7', 'DisplayName' => 'Someone Else', 'Active' => true]]),
            $this->createdResponse('58', 'Harbor Test Co'),
        );

        $created = $this->create($client);

        $this->assertSame(['Id' => '58', 'DisplayName' => 'Harbor Test Co'], $created);
        $posts = $this->posts();
        $this->assertCount(1, $posts);
        $this->assertSame('/v3/company/4620816365/customer', $posts[0]->getUri()->getPath());
        $this->assertSame([
            'DisplayName' => 'Harbor Test Co',
            'CompanyName' => 'Harbor Test Co',
            'PrimaryEmailAddr' => ['Address' => 'billing@harbor.example'],
            'PrimaryPhone' => ['FreeFormNumber' => '5550100'],
            'BillAddr' => [
                'Line1' => '1 Dock St',
                'Line2' => 'Suite 2',
                'City' => 'Porttown',
                'CountrySubDivisionCode' => 'WA',
                'PostalCode' => '98000',
            ],
        ], json_decode((string) $posts[0]->getBody(), true));

        $fresh = $client->fresh();
        $this->assertSame('58', $fresh->qbo_customer_id);
        $this->assertSame('Harbor Test Co', $fresh->qbo_display_name);
        $this->assertSame('58', $client->qbo_customer_id, 'the caller model reflects the link');
    }

    public function test_empty_psa_fields_are_omitted_from_the_payload(): void
    {
        $client = $this->makeClient([
            'email' => '',
            'phone' => null,
            'address_line1' => '   ',
            'address_line2' => null,
            'city' => 'Porttown',
            'state' => '',
            'postcode' => null,
        ]);
        $this->mock->append($this->queryPage([]), $this->createdResponse('59', 'Harbor Test Co'));

        $this->create($client);

        $this->assertSame([
            'DisplayName' => 'Harbor Test Co',
            'CompanyName' => 'Harbor Test Co',
            'BillAddr' => ['City' => 'Porttown'],
        ], json_decode((string) $this->posts()[0]->getBody(), true));
    }

    public function test_no_address_field_means_no_bill_addr(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('60', 'Harbor Test Co'));

        $this->create($client);

        $this->assertSame(
            ['DisplayName', 'CompanyName'],
            array_keys(json_decode((string) $this->posts()[0]->getBody(), true)),
        );
    }

    public function test_preflight_query_includes_inactive_customers(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('61', 'Harbor Test Co'));

        $this->create($client);

        $queries = $this->queries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('FROM Customer WHERE Active IN (true, false)', $queries[0]);
    }

    // ── service: refusals before the POST ──

    public function test_existing_same_name_customer_refuses_without_posting(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([['Id' => '42', 'DisplayName' => "  harbor   TEST\tco ", 'Active' => true]]),
            $this->createdResponse('99', 'Harbor Test Co'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::NAME_EXISTS, $e->kind);
        $this->assertSame([], $this->posts(), 'no POST may be sent when the name exists');
        $this->assertStringContainsString('Id 42', $e->getMessage());
        $this->assertStringContainsString('Use Link', $e->getMessage());
        $this->assertFalse($e->mayExistInQbo());
        $this->assertNull($client->fresh()->qbo_customer_id);
        $this->assertNull($client->fresh()->qbo_display_name);
    }

    public function test_inactive_same_name_customer_also_refuses(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([['Id' => '43', 'DisplayName' => 'Harbor Test Co', 'Active' => false]]),
            $this->createdResponse('99', 'Harbor Test Co'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::NAME_EXISTS, $e->kind);
        $this->assertSame([], $this->posts());
        $this->assertStringContainsString('Id 43, inactive', $e->getMessage());
    }

    public function test_match_on_a_later_page_still_refuses(): void
    {
        $client = $this->makeClient();
        $page1 = [];
        for ($i = 1; $i <= 1000; $i++) {
            $page1[] = ['Id' => (string) (1000 + $i), 'DisplayName' => "Other {$i}", 'Active' => true];
        }
        $this->mock->append(
            $this->queryPage($page1),
            $this->queryPage([['Id' => '3001', 'DisplayName' => 'HARBOR TEST CO', 'Active' => true]]),
            $this->createdResponse('99', 'Harbor Test Co'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::NAME_EXISTS, $e->kind);
        $this->assertSame([], $this->posts());
        $queries = $this->queries();
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('STARTPOSITION 1001', $queries[1]);
    }

    public function test_unreadable_preflight_refuses_without_posting(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"time":"2026-01-15T10:30:00Z"}'),
            $this->createdResponse('99', 'Harbor Test Co'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::PREFLIGHT_FAILED, $e->kind);
        $this->assertSame([], $this->posts());
        $this->assertNull($client->fresh()->qbo_customer_id);
    }

    public function test_already_mapped_client_sends_no_request(): void
    {
        $client = $this->makeClient(['qbo_customer_id' => '12']);
        $this->mock->append($this->queryPage([]), $this->createdResponse('99', 'Harbor Test Co'));

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::ALREADY_MAPPED, $e->kind);
        $this->assertSame([], $this->history);
        $this->assertSame('12', $client->fresh()->qbo_customer_id);
    }

    /**
     * The second of two presses: its model was loaded before the first press
     * committed, so only the re-check on the LOCKED row can see the link.
     */
    public function test_stale_model_is_refused_by_the_locked_row_recheck(): void
    {
        $client = $this->makeClient();
        $stale = Client::findOrFail($client->id);
        Client::whereKey($client->id)->update(['qbo_customer_id' => '12', 'qbo_display_name' => 'Harbor Test Co']);
        $this->mock->append($this->queryPage([]), $this->createdResponse('99', 'Harbor Test Co'));

        $this->assertNull($stale->qbo_customer_id);
        $e = $this->createExpectingFailure($stale);

        $this->assertSame(QboCustomerCreateException::ALREADY_MAPPED, $e->kind);
        $this->assertSame([], $this->history, 'no request may reach QBO');
        $this->assertSame('12', $client->fresh()->qbo_customer_id);
    }

    /**
     * SQLite compiles lockForUpdate() to '' (SQLiteGrammar::compileLock), so no
     * SQL-level assertion can see the lock in this suite. A recording grammar
     * captures what the builder ASKED for: lock=true on `clients`, inside a
     * transaction, for the link write only, after QBO has answered. QBO itself
     * is never called inside a transaction the service opened: QboClient's
     * token refresh and disconnect() write `settings`, and those writes must
     * not be rolled back with a refused create.
     */
    public function test_client_row_is_locked_only_for_the_link_write_and_qbo_is_called_outside_any_transaction(): void
    {
        $client = $this->makeClient();
        $conn = \Illuminate\Support\Facades\DB::connection();
        $levelsAtRequest = [];
        $this->mock->append(
            function () use ($conn, &$levelsAtRequest) {
                $levelsAtRequest[] = $conn->transactionLevel();

                return $this->queryPage([]);
            },
            function () use ($conn, &$levelsAtRequest) {
                $levelsAtRequest[] = $conn->transactionLevel();

                return $this->createdResponse('66', 'Harbor Test Co');
            },
        );

        $history = &$this->history;
        $grammar = new class($conn) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
        {
            /** @var list<array{table: mixed, lock: mixed, level: int, requests_sent: int}> */
            public array $locks = [];

            /** @var \Closure(): int */
            public \Closure $requestsSent;

            protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
            {
                $this->locks[] = [
                    'table' => $query->from,
                    'lock' => $value,
                    'level' => $this->connection->transactionLevel(),
                    'requests_sent' => ($this->requestsSent)(),
                ];

                return parent::compileLock($query, $value);
            }
        };
        $grammar->requestsSent = function () use (&$history): int {
            return count($history);
        };
        $conn->setQueryGrammar($grammar);

        // RefreshDatabase already holds a transaction; the lock must sit in
        // one the service opened, i.e. deeper than the level at the call.
        $baseLevel = $conn->transactionLevel();
        $this->create($client);

        $clientLocks = array_values(array_filter($grammar->locks, fn ($l) => $l['table'] === 'clients' && $l['lock'] === true));
        $this->assertCount(1, $clientLocks, 'exactly one FOR UPDATE read of the client row');
        $this->assertGreaterThan($baseLevel, $clientLocks[0]['level'], 'the lock is taken inside the service transaction');
        $this->assertSame(2, $clientLocks[0]['requests_sent'], 'the row lock is taken for the link write, after the query and the POST');
        $this->assertSame([$baseLevel, $baseLevel], $levelsAtRequest, 'no QBO request is sent inside a transaction the service opened');
        $this->assertSame('66', $client->fresh()->qbo_customer_id);
    }

    // ── service: QBO token writes and the per-client lock ──

    /**
     * QboClient refreshes the stored tokens on any request once they are near
     * expiry. That write must commit even when the create is then refused:
     * the vendor has already rotated the refresh token.
     */
    public function test_token_refresh_during_the_preflight_survives_a_refusal(): void
    {
        Setting::setValue('qbo_token_expires_at', now()->subMinute()->toDateTimeString());
        Setting::setEncrypted('qbo_refresh_token', 'old-refresh-token');
        $client = $this->makeClient();
        $this->mock->append(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ])),
            $this->queryPage([['Id' => '42', 'DisplayName' => 'Harbor Test Co', 'Active' => true]]),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::NAME_EXISTS, $e->kind);
        $this->assertCount(2, $this->history, 'one token refresh, one query');
        $this->assertSame('Bearer rotated-access-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('rotated-access-token', Setting::getEncrypted('qbo_access_token'));
        $this->assertSame('rotated-refresh-token', Setting::getEncrypted('qbo_refresh_token'));
    }

    public function test_disconnect_on_a_failed_refresh_survives_the_refusal(): void
    {
        Setting::setValue('qbo_token_expires_at', now()->subMinute()->toDateTimeString());
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('99', 'Harbor Test Co'));

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::PREFLIGHT_FAILED, $e->kind);
        $this->assertStringContainsString('Please reconnect to QuickBooks', $e->getMessage());
        $this->assertSame([], $this->history);
        $this->assertNull(Setting::getValue('qbo_realm_id'), 'the disconnect QboClient made is not rolled back');
        $this->assertNull(Setting::getEncrypted('qbo_access_token'));
        $this->assertFalse(app(QboClient::class)->isConnected());
    }

    public function test_a_press_while_another_holds_the_create_lock_sends_nothing(): void
    {
        $client = $this->makeClient();
        $held = Cache::lock('qbo-create-customer:'.$client->id, 60);
        $this->assertTrue($held->get());
        $this->mock->append($this->queryPage([]), $this->createdResponse('99', 'Harbor Test Co'));

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::NOT_STARTED, $e->kind);
        $this->assertSame([], $this->history);
        $this->assertFalse($e->mayExistInQbo());
        $this->assertStringContainsString('has not released it', $e->getMessage());
        $this->assertStringContainsString('Nothing was sent to QuickBooks by this press', $e->getMessage());
        $this->assertNull($client->fresh()->qbo_customer_id);
        $held->release();
    }

    public function test_the_create_lock_is_released_after_a_refusal(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([['Id' => '42', 'DisplayName' => 'Harbor Test Co', 'Active' => true]]));

        $this->assertSame(QboCustomerCreateException::NAME_EXISTS, $this->createExpectingFailure($client)->kind);

        $this->assertTrue(Cache::lock('qbo-create-customer:'.$client->id, 60)->get(), 'the lock is free again');
    }

    public function test_client_linked_while_the_create_runs_reports_created_not_linked(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            function () use ($client) {
                Client::whereKey($client->id)->update(['qbo_customer_id' => '12', 'qbo_display_name' => 'Harbor Test Co']);

                return $this->createdResponse('67', 'Harbor Test Co');
            },
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::CREATED_NOT_LINKED, $e->kind);
        $this->assertSame('67', $e->qboCustomerId);
        $this->assertTrue($e->mayExistInQbo());
        $this->assertStringContainsString('(Id 67) now EXISTS in QuickBooks', $e->getMessage());
        $this->assertStringContainsString('linked to QuickBooks customer Id 12 while the create was running', $e->getMessage());
        $this->assertSame('12', $client->fresh()->qbo_customer_id, 'the link made meanwhile is not overwritten');

        $unsaved = array_values(array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created, client link not saved'));
        $this->assertCount(1, $unsaved);
        $this->assertSame('warning', $unsaved[0]->level);
        $this->assertSame('67', $unsaved[0]->context['qbo_customer_id']);
        $this->assertSame('12', $unsaved[0]->context['linked_meanwhile_to']);
        $this->assertSame([], array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created and linked'));
    }

    // ── service: after the POST ──

    public function test_qbo_fault_is_surfaced_and_client_unchanged(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            $this->faultResponse(400, '6240', 'Duplicate Name Exists Error', 'The name supplied already exists. : Id=812'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::REJECTED, $e->kind);
        $this->assertCount(1, $this->posts());
        $this->assertStringContainsString('QuickBooks rejected the new customer', $e->getMessage());
        $this->assertStringContainsString('Duplicate Name Exists Error', $e->getMessage());
        $this->assertFalse($e->mayExistInQbo());
        $this->assertNull($client->fresh()->qbo_customer_id);
    }

    public function test_server_error_on_create_reports_unknown_outcome(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            new Response(503, ['Content-Type' => 'application/json'], '{}'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::OUTCOME_UNKNOWN, $e->kind);
        $this->assertTrue($e->mayExistInQbo());
        $this->assertStringContainsString('cannot tell whether a customer', $e->getMessage());
        $this->assertNull($client->fresh()->qbo_customer_id);
    }

    public function test_connection_failure_on_create_reports_unknown_outcome(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            new ConnectException('timed out', new Request('POST', 'customer')),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::OUTCOME_UNKNOWN, $e->kind);
        $this->assertTrue($e->mayExistInQbo());
    }

    public function test_response_without_an_id_reports_unknown_outcome(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            new Response(200, ['Content-Type' => 'application/json'], '{"Customer":{"DisplayName":"Harbor Test Co"}}'),
        );

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::OUTCOME_UNKNOWN, $e->kind);
        $this->assertNull($client->fresh()->qbo_customer_id);
    }

    public function test_unwrapped_create_response_is_read(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            new Response(200, ['Content-Type' => 'application/json'], '{"Id":"64","DisplayName":"Harbor Test Co"}'),
        );

        $this->assertSame('64', $this->create($client)['Id']);
        $this->assertSame('64', $client->fresh()->qbo_customer_id);
    }

    public function test_local_save_failure_after_create_says_the_customer_exists(): void
    {
        $other = $this->makeClient(['name' => 'Other Client', 'qbo_customer_id' => '58']);
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('58', 'Harbor Test Co'));

        $e = $this->createExpectingFailure($client);

        $this->assertSame(QboCustomerCreateException::CREATED_NOT_LINKED, $e->kind);
        $this->assertSame('58', $e->qboCustomerId);
        $this->assertTrue($e->mayExistInQbo());
        $this->assertCount(1, $this->posts());
        $this->assertStringContainsString('(Id 58) now EXISTS in QuickBooks', $e->getMessage());
        $this->assertStringContainsString('another PSA client is already linked to that Id', $e->getMessage());
        $this->assertStringNotContainsStringIgnoringCase('no customer was created', $e->getMessage());
        $this->assertStringNotContainsStringIgnoringCase('nothing was sent', $e->getMessage());
        $this->assertNull($client->fresh()->qbo_customer_id);
        $this->assertSame('58', $other->fresh()->qbo_customer_id);

        // G-14: one record for this event, at warning, carrying the Id; the
        // success line is not written at any level.
        $unsaved = array_values(array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created, client link not saved'));
        $this->assertCount(1, $unsaved);
        $this->assertSame('warning', $unsaved[0]->level);
        $this->assertSame('58', $unsaved[0]->context['qbo_customer_id']);
        $this->assertSame([], array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created and linked'));
    }

    public function test_success_is_logged_once_at_info(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('65', 'Harbor Test Co'));

        $this->create($client);

        $linked = array_values(array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created and linked'));
        $this->assertCount(1, $linked);
        $this->assertSame('info', $linked[0]->level);
        $this->assertSame('65', $linked[0]->context['qbo_customer_id']);
        $this->assertSame([], array_filter($this->logs, fn ($r) => $r->message === '[QBO] Customer created, client link not saved'));
    }

    // ── route ──

    public function test_admin_press_creates_and_flashes_name_and_id(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('70', 'Harbor Test Co'));

        $this->actingAs($this->admin())
            ->from(route('clients.show', $client))
            ->post(route('clients.qbo.provision', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', 'QuickBooks customer "Harbor Test Co" (Id 70) created and linked to this client.');

        $this->assertSame('70', $client->fresh()->qbo_customer_id);
    }

    public function test_route_flashes_link_advice_when_name_exists(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([['Id' => '42', 'DisplayName' => 'Harbor Test Co', 'Active' => true]]));

        $this->actingAs($this->admin())
            ->from(route('clients.show', $client))
            ->post(route('clients.qbo.provision', $client))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Id 42') && str_contains($m, 'Use Link'));

        $this->assertSame([], $this->posts());
    }

    public function test_route_flashes_the_qbo_fault_message(): void
    {
        $client = $this->makeClient();
        $this->mock->append(
            $this->queryPage([]),
            $this->faultResponse(400, '6240', 'Duplicate Name Exists Error', 'The name supplied already exists.'),
        );

        $this->actingAs($this->admin())
            ->from(route('clients.show', $client))
            ->post(route('clients.qbo.provision', $client))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Duplicate Name Exists Error'));
    }

    public function test_route_flashes_created_not_linked(): void
    {
        $this->makeClient(['name' => 'Other Client', 'qbo_customer_id' => '58']);
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('58', 'Harbor Test Co'));

        $this->actingAs($this->admin())
            ->from(route('clients.show', $client))
            ->post(route('clients.qbo.provision', $client))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '(Id 58) now EXISTS in QuickBooks'))
            ->assertSessionMissing('success');
    }

    public function test_non_admin_cannot_reach_the_route(): void
    {
        $client = $this->makeClient();
        $this->mock->append($this->queryPage([]), $this->createdResponse('71', 'Harbor Test Co'));

        foreach (['tech', 'billing', 'contractor'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->post(route('clients.qbo.provision', $client))
                ->assertForbidden();
        }

        $this->assertSame([], $this->history);
        $this->assertNull($client->fresh()->qbo_customer_id);
    }

    public function test_route_refuses_when_qbo_not_connected(): void
    {
        Setting::where('key', 'qbo_access_token')->delete();
        $client = $this->makeClient();

        $this->actingAs($this->admin())
            ->from(route('clients.show', $client))
            ->post(route('clients.qbo.provision', $client))
            ->assertSessionHas('error', 'QuickBooks Online is not connected.');

        $this->assertSame([], $this->history);
    }

    // ── Blade ──

    private function integrationsHtml(Client $client, ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->admin())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->getContent();
    }

    public function test_create_button_shown_for_unmapped_client_when_connected(): void
    {
        $client = $this->makeClient();
        $html = $this->integrationsHtml($client);

        $this->assertStringContainsString('action="'.route('clients.qbo.provision', $client).'"', $html);
        $this->assertStringContainsString('data-qbo-create', $html);
        // The confirm dialog names the client; Js::from hex-escapes the quotes
        // so the name cannot break out of the onsubmit attribute.
        $this->assertStringContainsString("confirm('Create a new QuickBooks Online customer named \\u0022Harbor Test Co\\u0022", $html);
    }

    public function test_confirm_dialog_escapes_a_hostile_client_name(): void
    {
        $client = $this->makeClient(['name' => "O'Hara \"&\" <b>Co</b>"]);
        $html = $this->integrationsHtml($client);

        $this->assertStringContainsString('named \\u0022O\\u0027Hara \\u0022\\u0026\\u0022 \\u003Cb\\u003ECo\\u003C\\/b\\u003E\\u0022', $html);
        $this->assertStringNotContainsString("named \"O'Hara", $html);
    }

    public function test_create_button_absent_when_client_is_mapped(): void
    {
        $client = $this->makeClient(['qbo_customer_id' => '12', 'qbo_display_name' => 'Harbor Test Co']);
        $html = $this->integrationsHtml($client);

        $this->assertStringNotContainsString(route('clients.qbo.provision', $client), $html);
        $this->assertStringNotContainsString('data-qbo-create', $html);
    }

    public function test_create_button_absent_when_qbo_not_connected(): void
    {
        Setting::where('key', 'qbo_realm_id')->delete();
        $client = $this->makeClient();
        $html = $this->integrationsHtml($client);

        $this->assertStringNotContainsString(route('clients.qbo.provision', $client), $html);
        $this->assertStringNotContainsString('data-qbo-create', $html);
    }

    public function test_create_button_absent_for_non_admin(): void
    {
        $client = $this->makeClient();
        $html = $this->integrationsHtml($client, User::factory()->tech()->create());

        $this->assertStringNotContainsString(route('clients.qbo.provision', $client), $html);
        // Positive control on the same page: the unmapped QBO card itself renders.
        $this->assertStringContainsString('data-vendor="qbo"', $html);
    }
}
