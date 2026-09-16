<?php

namespace Tests\Feature\Integrations;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboarding;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\ControlD\ControlDWriteRejectedException;
use App\Services\ControlD\ControlDWriteUncertainException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlDOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 17)->startOfDay());
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        foreach (['enabled' => '1', 'tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7', 'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
    }

    private function client(): Client
    {
        $client = Client::factory()->create(['controld_org_id' => 'testorg001']);
        Asset::factory()->count(3)->create(['client_id' => $client->id]);
        Asset::factory()->create(); // Other clients must not affect max.

        return $client;
    }

    private function row(array $overrides = []): array
    {
        // Existing fixture is sourced from the producer note, not from the writer.
        return [...json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true), 'max' => 5, 'ts_exp' => now()->getTimestamp() + 7 * 86400, ...$overrides];
    }

    private function response(mixed $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function preflight(): array
    {
        return [$this->response(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]), $this->response(['profiles' => [['PK' => 'testprofile01']]])];
    }

    private function provisioning(array $responses): ControlDProvisioning
    {
        // Guzzle is independently isolated: Http::fake cannot intercept this client.
        $fallback = new Response(400, [], '{"success":false}');
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 12, $fallback)]));
        $stack->push(Middleware::history($this->history));

        return new ControlDProvisioning(new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]));
    }

    private function writer(array $responses): ControlDOnboarding
    {
        return new ControlDOnboarding($this->provisioning($responses));
    }

    private function refusal(callable $call, string $needle): ControlDClientException
    {
        try {
            $call();
            $this->fail('Expected refusal.');
        } catch (ControlDClientException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            $this->assertNull($e->getPrevious());
            foreach (['synthetic-key', $this->row()['code'], '987654321'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $e);
            }

            return $e;
        }
    }

    public function test_wire_readback_encrypted_storage_and_secret_free_audit_without_result(): void
    {
        $client = $this->client();
        $row = $this->row(['deactivation_pin' => 987654321, 'name_prefix' => 'TEST-']);
        $writer = $this->writer([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])]);
        Log::spy();
        $this->assertNull($writer->create($client->id, 'desktop-windows', '987654321', 'TEST-'));
        $this->assertSame(['GET', 'GET', 'POST', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        $body = json_decode((string) $this->history[2]['request']->getBody(), true);
        $this->assertSame(5, $body['max']);
        $this->assertSame(now()->getTimestamp() + 7 * 86400, $body['ts_exp']);
        $this->assertSame(0, $body['stats']);
        $this->assertSame('testprofile01', $body['profile_id']);
        foreach ($this->history as $h) {
            $this->assertSame('testorg001', $h['request']->getHeaderLine('X-Force-Org-Id'));
        }
        $client->refresh();
        $this->assertSame($row['code'], $client->controld_provisioning_code);
        $this->assertSame('987654321', $client->controld_deactivation_pin);
        $stored = DB::table('clients')->find($client->id);
        $this->assertNotSame($row['code'], $stored->controld_provisioning_code);
        $this->assertNotSame('987654321', $stored->controld_deactivation_pin);
        $this->assertArrayNotHasKey('controld_provisioning_code', $client->toArray());
        Log::shouldHaveReceived('info')->once()->with('[ControlDOnboarding] Code stored', ['client_id' => $client->id]);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'already has stored');
        $this->assertCount(4, $this->history);
    }

    public static function blankSettings(): array
    {
        return array_map(fn ($v) => [$v], ['tactical_client_field_id', 'default_profile_id', 'code_expiry_days', 'code_device_limit_headroom', 'code_analytics_level', 'code_intercept_mode']);
    }

    #[DataProvider('blankSettings')]
    public function test_blank_setting_refuses_before_any_request(string $key): void
    {
        $client = $this->client();
        Setting::setValue('controld_'.$key, '');
        $this->refusal(fn () => $this->writer([])->create($client->id, 'desktop-windows'), 'controld_'.$key);
        $this->assertCount(0, $this->history);
        $this->assertNull($client->fresh()->controld_provisioning_code);
    }

    public function test_unmapped_deleted_existing_raw_secret_and_disabled_refuse_without_requests(): void
    {
        $client = $this->client();
        $client->update(['controld_org_id' => null]);
        $writer = $this->writer([]);
        $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'controld_org_id');
        $client->update(['controld_org_id' => 'testorg001']);
        foreach (['controld_provisioning_code', 'controld_deactivation_pin'] as $column) {
            DB::table('clients')->where('id', $client->id)->update([$column => 'undecryptable']);
            $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'already has stored');
            DB::table('clients')->where('id', $client->id)->update([$column => null]);
        }
        Setting::setValue('controld_enabled', '0');
        $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'disabled');
        $client->delete();
        $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'deleted');
        $this->assertCount(0, $this->history);
    }

    public function test_arithmetic_bounds_refuse_before_any_request_and_zero_headroom_is_valid(): void
    {
        $client = $this->client();
        foreach ([['code_device_limit_headroom', (string) PHP_INT_MAX], ['code_device_limit_headroom', '9998'], ['code_expiry_days', (string) PHP_INT_MAX]] as [$key, $value]) {
            Setting::setValue('controld_'.$key, $value);
            $this->refusal(fn () => $this->writer([])->create($client->id, 'desktop-windows'), 'controld_'.$key);
            Setting::setValue('controld_'.$key, $key === 'code_expiry_days' ? '7' : '2');
        }
        $this->assertCount(0, $this->history);
        Setting::setValue('controld_code_device_limit_headroom', '0');
        $row = $this->row(['max' => 3]);
        $this->writer([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])])->create($client->id, 'desktop-windows');
        $this->assertSame($row['code'], $client->fresh()->controld_provisioning_code);
        $this->assertNull($client->fresh()->controld_deactivation_pin);
    }

    public function test_zero_asset_and_zero_headroom_refuses_not_unlimited(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'testorg001']);
        Setting::setValue('controld_code_device_limit_headroom', '0');
        $this->refusal(fn () => $this->writer([])->create($client->id, 'desktop-windows'), '1..10000');
        $this->assertCount(0, $this->history);
    }

    public function test_analytics_one_and_two_map_to_integers_and_require_region_readback(): void
    {
        foreach ([1, 2] as $stats) {
            $client = $this->client();
            Setting::setValue('controld_code_analytics_level', (string) $stats);
            $row = $this->row(['stats' => $stats]);
            $before = count($this->history);
            $this->writer([...$this->preflight(), $this->response(['organization' => ['PK' => 'testorg001', 'stats_endpoint' => 'synthetic-region']]), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])])->create($client->id, 'desktop-windows');
            $body = json_decode((string) $this->history[$before + 3]['request']->getBody(), true);
            $this->assertSame($stats, $body['stats']);
            $this->assertSame($row['code'], $client->fresh()->controld_provisioning_code);
            $client->update(['controld_org_id' => null]);
        }
    }

    public function test_post_readback_failure_is_typed_with_pk_phase_and_does_not_persist(): void
    {
        $client = $this->client();
        $row = $this->row();
        $writer = $this->writer([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [[...$row, 'max' => 999]]])]);
        $e = $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'uncertain');
        $this->assertInstanceOf(ControlDWriteUncertainException::class, $e);
        $this->assertSame('testorg001', $e->orgPk);
        $this->assertSame($row['PK'], $e->provisionPk);
        $this->assertSame('read-back', $e->phase);
        $this->assertNull($client->fresh()->controld_provisioning_code);
        $this->assertCount(4, $this->history);
    }

    public function test_post_http_4xx_is_definite_rejection_but_5xx_timeout_and_bad_json_are_uncertain(): void
    {
        $row = $this->row();
        $fields = array_intersect_key($row, array_flip(['icon', 'profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode']));
        foreach ([new Response(400, [], 'synthetic-key'), new Response(500, [], 'synthetic-key'), new ConnectException('synthetic-key', new Request('POST', 'https://example.test')), new Response(200, [], '{broken'), $this->response(['provision' => []])] as $index => $response) {
            $service = $this->provisioning([...$this->preflight(), $response]);
            $e = $this->refusal(fn () => $service->create('testorg001', $fields), $index === 0 ? 'rejected' : 'uncertain');
            $this->assertInstanceOf($index === 0 ? ControlDWriteRejectedException::class : ControlDWriteUncertainException::class, $e);
            if ($index > 0) {
                $this->assertSame('testorg001', $e->orgPk);
                $this->assertNull($e->provisionPk);
            }
        }
        $this->assertCount(15, $this->history);
    }

    public function test_readback_http_4xx_is_uncertain_not_a_definite_nonwrite(): void
    {
        $client = $this->client();
        $row = $this->row();
        $writer = $this->writer([...$this->preflight(), $this->response(['provision' => $row]), new Response(404)]);
        $e = $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'uncertain');
        $this->assertInstanceOf(ControlDWriteUncertainException::class, $e);
        $this->assertSame('read-back', $e->phase);
        $this->assertSame($row['PK'], $e->provisionPk);
        $this->assertNull($client->fresh()->controld_provisioning_code);
    }

    public function test_local_save_veto_after_success_is_uncertain_with_no_partial_local_secrets(): void
    {
        $client = $this->client();
        $row = $this->row();
        Client::saving(fn (Client $saving) => $saving->id === $client->id ? false : null);
        try {
            $writer = $this->writer([...$this->preflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])]);
            $e = $this->refusal(fn () => $writer->create($client->id, 'desktop-windows'), 'uncertain');
            $this->assertInstanceOf(ControlDWriteUncertainException::class, $e);
            $this->assertSame('local-persistence', $e->phase);
            $this->assertSame($row['PK'], $e->provisionPk);
            $this->assertNull($client->fresh()->controld_provisioning_code);
            $this->assertNull($client->fresh()->controld_deactivation_pin);
            $this->assertCount(4, $this->history);
        } finally {
            Client::flushEventListeners();
        }
    }

    public function test_migration_down_refuses_populated_even_soft_deleted_rows(): void
    {
        $client = $this->client();
        $client->controld_provisioning_code = $this->row()['code'];
        $client->save();
        $client->delete();
        $migration = require database_path('migrations/2026_09_16_100000_add_controld_onboarding_secrets_to_clients.php');
        try {
            $migration->down();
            $this->fail('Populated down must refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop populated', $e->getMessage());
        }
        $this->assertSame($this->row()['code'], Client::withTrashed()->findOrFail($client->id)->controld_provisioning_code);
    }

    public static function badAnalytics(): array
    {
        return array_map(fn ($v) => [$v], ['00', '01', '+1', '1.0', '3', '-1', 'FULL', '١', '1 2']);
    }

    #[DataProvider('badAnalytics')]
    public function test_analytics_conversion_refuses_noncanonical_digits_without_requests(string $value): void
    {
        $client = $this->client();
        Setting::setValue('controld_code_analytics_level', $value);
        $this->refusal(fn () => $this->writer([])->create($client->id, 'desktop-windows'), 'controld_code_analytics_level');
        $this->assertCount(0, $this->history);
    }
}
