<?php

namespace Tests\Feature\AppRiver;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Setting;
use App\Services\AlertService;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use App\Services\AppRiver\AppRiverLoginMonitor;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card 6abc5913 (EKnz4VSM), scope item 1: a dropped AppRiver login raises ONE
 * Alerts Hub alert naming the fix, resolves it on reconnect, and the alert
 * bookkeeping never changes the exception the credential path throws.
 *
 * The failure is driven through the real client: a token endpoint answering with
 * AppRiver's nested dead-grant envelope (the live shape pinned by
 * AppRiverNestedOauthErrorTest), so handleRefreshFailure() -> disconnect() runs.
 */
class AppRiverLoginAlertTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD_GRANT = '{"error":"invalid_request","error_description":"{\"error\":\"invalid_grant\"}"}';

    private function seedExpiredConnection(): void
    {
        Setting::setEncrypted('appriver_client_id', 'test-client');
        Setting::setEncrypted('appriver_client_secret', 'test-secret');
        Setting::setEncrypted('appriver_access_token', 'stale-access-token');
        Setting::setEncrypted('appriver_refresh_token', 'dead-refresh-token');
        Setting::setValue('appriver_token_expires_at', now()->subMinutes(5)->toDateTimeString());
        Setting::setValue('appriver_connected_at', now()->subDays(3)->toDateTimeString());
    }

    /** @param  array<int, mixed>  $queue */
    private function client(array $queue): AppRiverClient
    {
        return new AppRiverClient([
            'base_url' => 'https://appriver.test',
            'handler' => HandlerStack::create(new MockHandler($queue)),
        ]);
    }

    private function deadGrant(): ClientException
    {
        return new ClientException('Client error', new Request('POST', 'auth/token'), new Response(400, [], self::DEAD_GRANT));
    }

    /** Drive one refresh failure through the real client; return the exception it threw. */
    private function failRefresh(): AppRiverClientException
    {
        try {
            $this->client([$this->deadGrant()])->getSubscriptions('customer-1');
        } catch (AppRiverClientException $e) {
            return $e;
        }
        $this->fail('Expected AppRiverClientException for a dead refresh token.');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Alert> */
    private function appRiverAlerts()
    {
        return Alert::where('source', AlertSource::AppRiver)->get();
    }

    public function test_refresh_failure_disconnects_and_raises_one_alert_naming_the_fix(): void
    {
        $this->seedExpiredConnection();

        $e = $this->failRefresh();

        $this->assertFalse(AppRiverClient::isConnected(), 'disconnect() must still clear the dead tokens');
        $this->assertSame('invalid_grant', $e->oauthError);
        $this->assertStringContainsString('session expired', $e->getMessage());

        $alerts = $this->appRiverAlerts();
        $this->assertCount(1, $alerts, 'a dropped login must raise exactly one Alerts Hub alert');
        $alert = $alerts->first();
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertStringContainsString('Reconnect in Settings > Integrations > AppRiver', $alert->message);
        $this->assertSame((string) $alert->id, Setting::getValue(AppRiverLoginMonitor::ALERT_ID));
    }

    public function test_repeated_failures_in_one_episode_refresh_the_same_alert(): void
    {
        $this->seedExpiredConnection();
        $this->failRefresh();

        // Later runs see connected_at with no token: the second detection route.
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->client([])->getSubscriptions('customer-1');
                $this->fail('Expected AppRiverClientException with no stored token.');
            } catch (AppRiverClientException $e) {
                $this->assertStringContainsString('access token not found', $e->getMessage());
            }
        }
        (new AppRiverLoginMonitor)->recordLoginDropped('direct');

        $alerts = $this->appRiverAlerts();
        $this->assertCount(1, $alerts, 'one episode must hold one alert, not one per run');
        $this->assertSame(4, $alerts->first()->refired_count, 'each later sighting refreshes the open alert');
    }

    public function test_connected_at_without_token_alone_raises_the_alert(): void
    {
        // No refresh ever ran in this process: the tokens are simply absent.
        Setting::setValue('appriver_connected_at', now()->subDays(12)->toDateTimeString());

        try {
            $this->client([])->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException with no stored token.');
        } catch (AppRiverClientException $e) {
            $this->assertStringContainsString('access token not found', $e->getMessage());
        }

        $this->assertCount(1, $this->appRiverAlerts());
    }

    public function test_never_connected_raises_nothing(): void
    {
        try {
            $this->client([])->getSubscriptions('customer-1');
            $this->fail('Expected AppRiverClientException with no stored token.');
        } catch (AppRiverClientException) {
        }

        $this->assertCount(0, $this->appRiverAlerts(), 'a never-connected integration is not a dropped login');
    }

    public function test_reconnect_resolves_the_alert_and_a_later_drop_opens_a_new_episode(): void
    {
        $this->seedExpiredConnection();
        $this->failRefresh();
        $first = $this->appRiverAlerts()->first();

        $this->client([new Response(200, [], json_encode([
            'access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 1800,
        ]))])->exchangeCode('auth-code');

        $this->assertTrue(AppRiverClient::isConnected());
        $this->assertSame(AlertStatus::Resolved, $first->fresh()->status, 'reconnect must resolve the alert');
        $this->assertNull(Setting::getValue(AppRiverLoginMonitor::DROPPED_AT));
        $this->assertNull(Setting::getValue(AppRiverLoginMonitor::ALERT_ID));

        Setting::setValue('appriver_token_expires_at', now()->subMinutes(5)->toDateTimeString());
        $this->failRefresh();

        $alerts = $this->appRiverAlerts();
        $this->assertCount(2, $alerts, 'a drop after a reconnect is a new episode with its own alert');
        $this->assertSame(1, $alerts->where('status', AlertStatus::Active)->count());
    }

    public function test_alert_bookkeeping_failure_never_changes_the_thrown_exception(): void
    {
        $this->seedExpiredConnection();
        $this->app->instance(AlertService::class, new class extends AlertService
        {
            public function __construct() {}

            public function upsert(AlertSource $source, string $sourceAlertId, array $data): Alert
            {
                throw new \RuntimeException('alerts table unavailable');
            }
        });

        $e = $this->failRefresh();

        $this->assertSame(AppRiverClientException::class, $e::class);
        $this->assertSame('invalid_grant', $e->oauthError);
        $this->assertFalse(AppRiverClient::isConnected());
    }
}
