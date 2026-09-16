<?php

namespace Tests\Feature\Integrations;

use App\Models\Setting;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDProvisioning;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlDProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
    }

    private function row(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fields(): array
    {
        return array_intersect_key($this->row(), array_flip(['icon', 'profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode']));
    }

    private function response(mixed $body, bool $success = true): Response
    {
        return new Response(200, [], json_encode(['success' => $success, 'body' => $body], JSON_THROW_ON_ERROR));
    }

    private function preflight(): array
    {
        return [
            $this->response(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->response(['profiles' => [['PK' => 'testprofile01']]]),
        ];
    }

    private function client(array $responses): ControlDClient
    {
        // Extra calls get a controlled vendor refusal, never a live request or a
        // missing-mock error masquerading as a behavioral mutation kill.
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 10, $this->response((object) [], false))]));
        $stack->push(Middleware::history($this->history));

        return new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);
    }

    private function service(array $responses): ControlDProvisioning
    {
        return new ControlDProvisioning($this->client($responses));
    }

    private function refuses(callable $operation, string $contains = ''): void
    {
        try {
            $operation();
            $this->fail('Expected a provisioning refusal.');
        } catch (ControlDClientException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString($this->row()['code'], $e->getMessage());
        }
    }

    public function test_create_without_pin_reads_back_under_same_org_and_returns_only_verified_secrets(): void
    {
        $row = $this->row();
        $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])]);
        $this->assertSame(['PK' => $row['PK'], 'code' => $row['code'], 'deactivation_pin' => null], $service->create('testorg001', $this->fields()));
        $this->assertCount(4, $this->history);
        $this->assertSame(['GET', 'GET', 'POST', 'GET'], array_map(fn ($r) => $r['request']->getMethod(), $this->history));
        foreach ($this->history as $request) {
            $this->assertSame('testorg001', $request['request']->getHeaderLine('X-Force-Org-Id'));
            $this->assertSame('Bearer synthetic-key', $request['request']->getHeaderLine('Authorization'));
            $this->assertSame('api.controld.com', $request['request']->getUri()->getHost());
        }
        $this->assertSame($this->fields(), json_decode((string) $this->history[2]['request']->getBody(), true));
    }

    public function test_numeric_pin_prefix_analytics_and_expiry_are_sent_and_verified(): void
    {
        $fields = [...$this->fields(), 'name_prefix' => 'TEST-', 'stats' => 2, 'ts_exp' => time() + 86400, 'intercept_mode' => 'intercept-dns'];
        $row = [...$this->row(), ...$fields, 'deactivation_pin' => 9999999999];
        $service = $this->service([...$this->preflight(), $this->response(['organization' => ['PK' => 'testorg001', 'stats_endpoint' => 'synthetic-region']]), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])]);
        $this->assertSame('9999999999', $service->create('testorg001', $fields, '9999999999')['deactivation_pin']);
        $wire = json_decode((string) $this->history[3]['request']->getBody(), true);
        $this->assertSame(9999999999, $wire['deactivation_pin']);
        $this->assertSame($fields, array_diff_key($wire, ['deactivation_pin' => true]));
    }

    public static function badPins(): array
    {
        return array_map(fn ($pin) => [$pin], ['0', '007', '', 'abc', '12345678901', '-1', '+1', '1.0', ' 1', "1\n", '١']);
    }

    #[DataProvider('badPins')]
    public function test_invalid_local_pin_refuses_before_any_vendor_call(string $pin): void
    {
        $service = $this->service([]);
        $this->refuses(fn () => $service->create('testorg001', $this->fields(), $pin), 'PIN must');
        $this->assertCount(0, $this->history);
    }

    public static function badFields(): array
    {
        return [['max', 0], ['max', 10001], ['max', '12'], ['max', PHP_INT_MAX], ['ts_exp', -1], ['ts_exp', '0'], ['stats', 3], ['stats', '1'], ['intercept_mode', 'unknown'], ['icon', ''], ['profile_id', '../x'], ['name_prefix', []], ['deactivation_pin', 1]];
    }

    #[DataProvider('badFields')]
    public function test_invalid_fields_refuse_before_any_vendor_call(string $key, mixed $value): void
    {
        $service = $this->service([]);
        $this->refuses(fn () => $service->create('testorg001', [...$this->fields(), $key => $value]));
        $this->assertCount(0, $this->history);
    }

    public static function mismatches(): array
    {
        return [['profile_id', 'other'], ['max', 13], ['ts_exp', 1], ['stats', 1], ['intercept_mode', 'intercept-dns'], ['icon', 'desktop-linux'], ['org', 'otherorg'], ['PK', 'otherpk'], ['max', '12'], ['status', 0], ['expired', 1], ['code', ''], ['name_prefix', 'unexpected'], ['deactivation_pin', 123]];
    }

    #[DataProvider('mismatches')]
    public function test_create_readback_mismatch_never_returns_success(string $key, mixed $value): void
    {
        $row = $this->row();
        $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [[...$row, $key => $value]]])]);
        $this->refuses(fn () => $service->create('testorg001', $this->fields()));
        $this->assertCount(4, $this->history); // no retry, cleanup or fan-out
    }

    public static function pinReadbacks(): array
    {
        return [[null], [124], ['123'], [0], [true], [[]]];
    }

    #[DataProvider('pinReadbacks')]
    public function test_requested_pin_missing_wrong_or_wrong_type_refuses(mixed $pin): void
    {
        $row = $this->row();
        $read = $pin === null ? $row : [...$row, 'deactivation_pin' => $pin];
        $service = $this->service([...$this->preflight(), $this->response(['provision' => [...$row, 'deactivation_pin' => 123]]), $this->response(['provisions' => [$read]])]);
        $this->refuses(fn () => $service->create('testorg001', $this->fields(), '123'), 'uncertain');
        $this->assertCount(4, $this->history);
    }

    public function test_invalidate_requires_status_minus_one_then_delete_is_scoped(): void
    {
        $service = $this->service([$this->response((object) []), $this->response(['provisions' => [[...$this->row(), 'status' => -1]]]), $this->response((object) [])]);
        $service->invalidate('testorg001', 'fixture001');
        $service->delete('testorg001', 'fixture001');
        $this->assertSame(['PUT', 'GET', 'DELETE'], array_map(fn ($r) => $r['request']->getMethod(), $this->history));
        $this->assertSame('/provision/fixture001/invalidate', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/provision/fixture001', $this->history[2]['request']->getUri()->getPath());
        foreach ($this->history as $r) {
            $this->assertSame('testorg001', $r['request']->getHeaderLine('X-Force-Org-Id'));
            $this->assertSame('', (string) $r['request']->getBody());
        }
    }

    public function test_disabled_and_unconfigured_refuse_without_calls(): void
    {
        $service = $this->service([]);
        Setting::setValue('controld_enabled', '0');
        $this->refuses(fn () => $service->create('testorg001', $this->fields()), 'disabled');
        $this->refuses(fn () => $service->invalidate('testorg001', 'fixture001'), 'disabled');
        $this->refuses(fn () => $service->delete('testorg001', 'fixture001'), 'disabled');
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', '');
        $this->refuses(fn () => $service->create('testorg001', $this->fields()), 'unconfigured');
        $this->assertCount(0, $this->history);
    }

    public function test_missing_required_field_and_unsafe_identifiers_refuse_before_transport(): void
    {
        $service = $this->service([]);
        $fields = $this->fields();
        unset($fields['max']);
        $this->refuses(fn () => $service->create('testorg001', $fields), 'missing');
        foreach (['', "org\r\nX: evil", '../parent', 'https://other.example'] as $bad) {
            $this->refuses(fn () => $service->create($bad, $this->fields()), 'identifier');
            $this->refuses(fn () => $service->invalidate('testorg001', $bad), 'identifier');
            $this->refuses(fn () => $service->delete($bad, 'fixture001'), 'identifier');
        }
        $this->assertCount(0, $this->history);
    }

    public function test_inventory_preflight_refuses_unavailable_icon_profile_or_region_without_write(): void
    {
        $scenarios = [
            [$this->response(['types' => []])],
            [$this->response(['types' => ['os' => ['icons' => ['desktop-linux' => []]]]])],
            [$this->preflight()[0], $this->response(['profiles' => []])],
            [$this->preflight()[0], $this->response(['profiles' => (object) []])],
            [$this->preflight()[0], $this->response(['profiles' => [['PK' => 'other']]])],
            [$this->preflight()[0], $this->response(['profiles' => [['PK' => 'testprofile01'], ['PK' => 'testprofile01']]])],
            [...$this->preflight(), $this->response(['organization' => ['PK' => 'testorg001']])],
            [...$this->preflight(), $this->response(['organization' => ['PK' => 'parent', 'stats_endpoint' => 'region']])],
        ];
        foreach ($scenarios as $responses) {
            $service = $this->service($responses);
            $this->refuses(fn () => $service->create('testorg001', [...$this->fields(), 'stats' => 1]));
        }
        foreach ($this->history as $request) {
            $this->assertSame('GET', $request['request']->getMethod());
        }
    }

    public function test_missing_duplicate_malformed_and_foreign_readback_rows_refuse(): void
    {
        $row = $this->row();
        foreach ([[], (object) [], [$row, $row], [null], [[...$row, 'org' => 'other']], [$row, ['PK' => 'foreign', 'org' => 'other']]] as $rows) {
            $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => $rows])]);
            $this->refuses(fn () => $service->create('testorg001', $this->fields()));
        }
    }

    public function test_every_required_readback_field_is_required(): void
    {
        $row = $this->row();
        foreach (['PK', 'org', 'profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode', 'icon', 'code', 'status', 'expired'] as $key) {
            $read = $row;
            unset($read[$key]);
            $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$read]])]);
            $this->refuses(fn () => $service->create('testorg001', $this->fields()));
        }
    }

    public function test_requested_prefix_missing_or_different_refuses_and_empty_prefix_is_omitted(): void
    {
        $row = $this->row();
        foreach ([$row, [...$row, 'name_prefix' => 'other']] as $read) {
            $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$read]])]);
            $this->refuses(fn () => $service->create('testorg001', [...$this->fields(), 'name_prefix' => 'TEST-']), 'uncertain');
        }
        $this->history = [];
        $service = $this->service([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [[...$row, 'name_prefix' => '']]])]);
        $this->assertSame($row['code'], $service->create('testorg001', [...$this->fields(), 'name_prefix' => ''])['code']);
        $this->assertArrayNotHasKey('name_prefix', json_decode((string) $this->history[2]['request']->getBody(), true));
    }

    public function test_bad_create_response_refuses_without_retry(): void
    {
        foreach ([[], ['provision' => []], ['provision' => ['PK' => '../wrong']], ['provision' => ['code' => 'secret']]] as $body) {
            $this->history = [];
            $service = $this->service([...$this->preflight(), $this->response($body)]);
            $this->refuses(fn () => $service->create('testorg001', $this->fields()));
            $this->assertCount(3, $this->history);
        }
    }

    public function test_strict_transport_refuses_errors_json_drift_and_redirects_without_secret_leaks(): void
    {
        foreach ([
            new Response(200, [], 'not-json'),
            new Response(200, [], '[]'),
            new Response(200, [], 'true'),
            new Response(200, [], '{"success":1,"body":{}}'),
            $this->response(['message' => 'sensitive'], false),
            new Response(302, ['Location' => 'https://other.example'], 'sensitive'),
            new Response(500, [], 'sensitive synthetic-key'),
            new \GuzzleHttp\Exception\ConnectException('sensitive synthetic-key', new \GuzzleHttp\Psr7\Request('POST', 'https://api.controld.com/provision')),
        ] as $response) {
            $this->history = [];
            $client = $this->client([$response]);
            $this->refuses(fn () => $client->postForOrg('provision', 'testorg001', $this->fields()));
            $this->assertCount(1, $this->history);
        }
    }

    public function test_scoped_put_sends_only_supplied_fields_and_transport_refuses_unsafe_paths(): void
    {
        $client = $this->client([$this->response(['provision' => $this->row()])]);
        $client->putForOrg('provision/fixture001', 'testorg001', ['max' => 10]);
        $this->assertSame(['max' => 10], json_decode((string) $this->history[0]['request']->getBody(), true));
        $this->assertSame('PUT', $this->history[0]['request']->getMethod());
        foreach (['https://other.example/provision', '//other.example', 'provision/../profiles', 'provision?org=parent'] as $endpoint) {
            $this->refuses(fn () => $client->postForOrg($endpoint, 'testorg001', []), 'invalid');
        }
        $this->assertCount(1, $this->history);
    }

    public function test_invalidation_acknowledgment_without_effect_refuses(): void
    {
        $service = $this->service([$this->response((object) []), $this->response(['provisions' => [$this->row()]])]);
        $this->refuses(fn () => $service->invalidate('testorg001', 'fixture001'), 'not confirmed');
    }
}
