<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\License;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshLicenseSyncService;
use App\Services\SyncResult;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5299 context:1+2 (card 6ac515f5): a Mesh customer read that answers 200
 * without usable data is a client error, not a silent skip, so the Sync Mesh
 * button does not flash success over it.
 *
 * What MeshClient::getCustomer() does with a degraded 200 at this base
 * (request() returns `json_decode($body, true) ?? []` under an `array`
 * return type):
 *
 *   - empty body, non-JSON body, JSON null      -> [] (the empty arm);
 *   - `{}` or `[]`                              -> [] (the empty arm);
 *   - an object without licenses_billed, or an
 *     error envelope sent with 200              -> a non-empty array (the field arm);
 *   - a JSON scalar ("x", 7, true)              -> TypeError from the return
 *     type, which syncLicenses()' existing catch already counts.
 *
 * Every arm is driven through a real MeshClient whose Guzzle is scripted;
 * nothing leaves the process. Synthetic data only (G-13): made-up names,
 * uuids and a per-process body marker.
 */
class MeshLicenseSyncDegradedReadTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'mesh.example.test';

    private const MESH_ID = '5c8e2a1f-7b3d-4f90-8a6e-1d2c3b4a5f6e';

    private const NAME = 'Synthetic Client Name 4d2f';

    /** Body marker; a fresh suffix per process. */
    private static string $marker = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (self::$marker === '') {
            self::$marker = 'BODYMARK-'.bin2hex(random_bytes(6));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function emptyBodies(): array
    {
        return [
            'empty body' => [''],
            'non-JSON body' => ['<html>gateway hiccup BODYMARK</html>'],
            'JSON null' => ['null'],
            'empty object' => ['{}'],
            'empty list' => ['[]'],
        ];
    }

    /** The empty arm: getCustomer() returns []. */
    #[DataProvider('emptyBodies')]
    public function test_a_customer_read_with_no_data_counts_as_an_error(string $body): void
    {
        $client = $this->mappedClient();
        $this->assertSame([], $this->scriptedClient($this->body($body))->getCustomer(self::MESH_ID), 'precondition: getCustomer() returns [] for this body');
        Log::spy();

        $result = $this->sync($this->body($body));

        $this->assertCounts($result, errors: 1, created: 0, updated: 0);
        $this->assertSame(0, License::count(), 'no license written');
        $this->assertErrorLogged($client, 'the customer read returned no data');
        $this->assertNoClientDataLogged();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unreadableFields(): array
    {
        return [
            'licenses_billed absent' => [['service_name' => 'Synthetic Service', 'active' => true]],
            'error envelope sent with 200' => [['detail' => 'BODYMARK']],
            'licenses_billed null' => [['licenses_billed' => null, 'service_name' => 'Synthetic Service']],
            'licenses_billed a word' => [['licenses_billed' => 'BODYMARK', 'service_name' => 'Synthetic Service']],
            'licenses_billed a list' => [['licenses_billed' => [3], 'service_name' => 'Synthetic Service']],
            'licenses_billed a bool' => [['licenses_billed' => true, 'service_name' => 'Synthetic Service']],
        ];
    }

    /** The field arm: a non-empty array without a numeric licenses_billed. */
    #[DataProvider('unreadableFields')]
    public function test_a_customer_read_without_a_readable_licenses_billed_counts_as_an_error(array $payload): void
    {
        $client = $this->mappedClient();
        Log::spy();

        $result = $this->sync($this->jsonBody($payload));

        $this->assertCounts($result, errors: 1, created: 0, updated: 0);
        $this->assertSame(0, License::count(), 'no license written');
        $this->assertErrorLogged($client, 'the customer read had no readable licenses_billed field');
        $this->assertNoClientDataLogged();
    }

    /** A numeric string is what a JSON-ish vendor may send; it stays readable. */
    public function test_a_numeric_string_licenses_billed_still_syncs(): void
    {
        $this->mappedClient();

        $result = $this->sync($this->jsonBody(['licenses_billed' => '4', 'service_name' => 'Synthetic Service']));

        $this->assertCounts($result, errors: 0, created: 1, updated: 0);
        $this->assertSame(4, (int) License::sole()->quantity);
    }

    /** A PRESENT 0 is the legitimate skip: no error, no license, logged by id. */
    public function test_a_present_zero_licenses_billed_is_a_skip_not_an_error(): void
    {
        $client = $this->mappedClient();
        Log::spy();

        $result = $this->sync($this->jsonBody(['licenses_billed' => 0, 'service_name' => 'Synthetic Service']));

        $this->assertCounts($result, errors: 0, created: 0, updated: 0);
        $this->assertSame(0, License::count(), 'nothing written for a 0');
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn ($m) => $m === "[MeshSync] Client {$client->getKey()}: 0 licenses billed, skipping"
        );
        Log::shouldNotHaveReceived('error');
        $this->assertNoClientDataLogged();
    }

    /** Positive control: a normal read still creates the license. */
    public function test_a_normal_read_creates_the_license(): void
    {
        $client = $this->mappedClient();
        Log::spy();

        $result = $this->sync($this->jsonBody(['licenses_billed' => 3, 'service_name' => 'Synthetic Service', 'active' => true]));

        $this->assertCounts($result, errors: 0, created: 1, updated: 0);
        $license = License::sole();
        $this->assertSame($client->id, $license->client_id);
        $this->assertSame(3, (int) $license->quantity);
        Log::shouldNotHaveReceived('error');
    }

    /**
     * The scalar arm: a JSON scalar fails getCustomer()'s array return type.
     * Already counted by syncLicenses()' catch at base; pinned so the arm
     * analysis stays true.
     */
    public function test_a_json_scalar_body_is_counted_by_the_existing_catch(): void
    {
        $client = $this->mappedClient();
        Log::spy();

        $result = $this->sync($this->body('"BODYMARK"'));

        $this->assertCounts($result, errors: 1, created: 0, updated: 0);
        $this->assertErrorLogged($client, 'an unexpected error (TypeError)');
        $this->assertNoClientDataLogged();
    }

    /** End to end: the only client answers an empty 200, so the button flashes an error. */
    public function test_the_sync_button_flashes_an_error_when_the_only_client_reads_empty(): void
    {
        $client = $this->mappedClient();
        Setting::setEncrypted('mesh_api_key', 'synthetic-key-'.bin2hex(random_bytes(4)));
        $mesh = $this->scriptedClient($this->body(''));
        $this->app->bind(MeshLicenseSyncService::class, fn () => new MeshLicenseSyncService($mesh));

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertRedirect(route('settings.integrations'));

        $this->assertNull(session('success'), 'no green flash over an empty read');
        $this->assertSame(
            'Mesh sync finished with 1 client error(s): 0 created, 0 updated. The PSA log names each failed client by id.',
            session('error'),
        );
        $this->assertStringNotContainsString(self::NAME, (string) session('error'));
        $this->assertSame(0, License::where('client_id', $client->id)->count());
    }

    // ---- helpers ----------------------------------------------------------------

    private function mappedClient(): Client
    {
        return Client::factory()->create([
            'name' => self::NAME,
            'mesh_customer_id' => self::MESH_ID,
            'is_active' => true,
        ]);
    }

    private function sync(\Closure $answer): SyncResult
    {
        return (new MeshLicenseSyncService($this->scriptedClient($answer)))->syncLicenses();
    }

    /** A 200 with this raw body; the literal BODYMARK becomes the per-process marker. */
    private function body(string $raw): \Closure
    {
        $raw = str_replace('BODYMARK', self::$marker, $raw);

        return fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $raw));
    }

    /** @param  array<string, mixed>  $payload */
    private function jsonBody(array $payload): \Closure
    {
        return $this->body((string) json_encode($payload));
    }

    /** A real MeshClient whose Guzzle is scripted (as MeshC56ReadSitesTest does). */
    private function scriptedClient(\Closure $answer): MeshClient
    {
        $client = new MeshClient(['api_key' => 'synthetic-key', 'base_url' => 'https://'.self::HOST]);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => HandlerStack::create(fn (RequestInterface $r) => $answer($r)),
            'http_errors' => true,
        ]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        return $client;
    }

    private function assertCounts(SyncResult $result, int $errors, int $created, int $updated): void
    {
        $this->assertSame(
            ['errors' => $errors, 'created' => $created, 'updated' => $updated],
            ['errors' => $result->errors, 'created' => $result->created, 'updated' => $result->updated],
        );
    }

    private function assertErrorLogged(Client $client, string $reason): void
    {
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($m) => is_string($m) && str_starts_with($m, "[MeshSync] Failed for client {$client->getKey()}: {$reason}")
        );
    }

    /** No log line at any level names the client, its Mesh id, or quotes the body. */
    private function assertNoClientDataLogged(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level, fn ($m) => is_string($m) && (
                str_contains($m, self::NAME) || str_contains($m, self::MESH_ID) || str_contains($m, self::$marker)
            ));
        }
    }
}
