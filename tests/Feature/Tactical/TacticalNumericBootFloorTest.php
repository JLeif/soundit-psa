<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalDeviceSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Numeric floor regressions (#2492): validate the instant, not the pre-cast float.
 * No list-path widening is required: these walk the existing public detail path.
 */
class TacticalNumericBootFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->assertSame(
            realpath(app_path('Services/Tactical/TacticalDeviceSyncService.php')),
            (new ReflectionClass(TacticalDeviceSyncService::class))->getFileName(),
            'the autoloader must execute the service in this checkout',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function numericInstants(): array
    {
        return [
            '2^64 float wraps below floor' => [1.8446744073709552e19, null],
            '2^64 numeric string wraps below floor' => ['18446744073709551616', null],
            'just below floor' => [999999999.9, null],
            'below floor numeric string' => ['999999999.9', null],
            'floor integer accepted' => [1000000000, '2001-09-09 01:46:40'],
            'floor fractional accepted truncated' => [1000000000.9, '2001-09-09 01:46:40'],
            'floor numeric string accepted' => ['1000000000', '2001-09-09 01:46:40'],
            'normal fractional epoch' => [1789617600.7267435, '2026-09-17 04:00:00'],
            'normal fractional numeric string' => ['1789617600.7267435', '2026-09-17 04:00:00'],
        ];
    }

    #[DataProvider('numericInstants')]
    public function test_parser_validates_the_written_instant(mixed $input, ?string $expected): void
    {
        $service = new TacticalDeviceSyncService(new TacticalClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([])),
        ])));
        $parsed = (new ReflectionMethod($service, 'parseBootTime'))->invoke($service, $input);

        $this->assertSame($expected, $parsed?->toDateTimeString());
        if ($parsed !== null) {
            $this->assertSame(0, $parsed->micro);
            $this->assertSame(0, $parsed->getOffset());
        }
    }

    #[DataProvider('numericInstants')]
    public function test_detail_sync_on_an_empty_column(mixed $input, ?string $expected): void
    {
        $asset = Asset::factory()->create(['last_boot_at' => null]);
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'NUMERIC-FLOOR',
            'hostname' => 'FLOOR-FIXTURE',
            'status' => 'online',
            'synced_at' => now()->subDay(),
        ]);
        $this->assertNull($asset->fresh()->last_boot_at, 'EMPTY is a required precondition');

        // Producer: amidaware/tacticalrmm @ 632a37a4a9759eb881ed0d76d1043e80b824d308,
        // api/tacticalrmm/agents/models.py: Agents.boot_time = FloatField(null=True),
        // agents/serializers.py: AgentSerializer.Meta excludes only id; status and
        // last_seen are ReadOnlyFields. This is a trimmed producer-shaped response
        // with synthetic values. Numeric strings/overflow are adversarial variants,
        // not claimed captures of a real vendor incident.
        $payload = json_encode([
            'status' => 'online',
            'last_seen' => '2026-09-20 11:00:00',
            'boot_time' => $input,
        ], JSON_THROW_ON_ERROR);
        $this->assertSame($input, json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['boot_time']);
        $queue = new MockHandler([new Response(200, [], $payload)]);
        $service = new TacticalDeviceSyncService(new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create($queue),
            'allow_redirects' => false,
        ])));

        $result = $service->syncDeviceDetail($asset->refresh());

        $this->assertTrue($result->ok);
        $this->assertSame(0, $queue->count(), 'the HTTP fixture must be consumed');
        $this->assertSame('2026-09-20 11:00:00', $ta->fresh()->last_seen_at->toDateTimeString());
        $this->assertSame($expected, $asset->fresh()->last_boot_at?->toDateTimeString());
    }
}
