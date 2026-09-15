<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\Offboarding\OffboardingPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OffboardingTransportTest extends TestCase
{
    public function test_http_statuses_never_refresh_retry_or_follow_redirects(): void
    {
        foreach ([200, 301, 302, 307, 308, 400, 401, 403, 429, 500, 503] as $status) {
            Cache::flush();
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]),
                'cipp.example.test/api/ExecOffboardUser' => Http::response(['Results' => []], $status, ['Location' => 'https://other.example.test/replay']),
            ]);
            $client = new CippRestWriteClient([
                'api_url' => 'https://cipp.example.test', 'tenant_id' => 'synthetic-tenant',
                'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
            ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
            $input = ['client_id' => 1, 'person_id' => 1, 'ticket_id' => 1, 'staged' => true,
                'confirm_upn' => 'leaver@example.test', 'reason' => 'Synthetic', 'actions' => ['revoke_sessions']];
            $body = OffboardingPlan::serialize($input, 'example.test', 'leaver@example.test', null,
                'soundpsa-offboard:11111111-1111-4111-8111-111111111111:22222222-2222-4222-8222-222222222222');
            $result = $client->submitOffboardingOnce($body);
            $this->assertSame($status, $result['status']);
            Http::assertSentCount(2); // one token acquisition, one wizard POST
            Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecOffboardUser' && $request->data() === $body);
            Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.test'));
        }
    }

    public function test_scheduler_permission_error_is_not_empty(): void
    {
        Cache::flush();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'synthetic-token']),
            'cipp.example.test/api/ListScheduledItems*' => Http::response([], 403),
        ]);
        $client = new CippRestWriteClient(['api_url' => 'https://cipp.example.test', 'tenant_id' => 'synthetic-tenant', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret'], Cache::store(), fn (string $host): array => ['93.184.216.34']);
        $this->expectException(\App\Services\Cipp\CippClientException::class);
        $client->offboardingRead('scheduled', ['tenantFilter' => 'example.test']);
    }
}
