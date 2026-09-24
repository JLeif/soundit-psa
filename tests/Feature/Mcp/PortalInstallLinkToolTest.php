<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\PortalInstallAudit;
use App\Services\Mcp\PortalMcpToolDefinitions;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PortalInstallLinkToolTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'portal_get_or_create_install_link';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
    }

    private function callLink(Client $client, array $extra = ['reason' => 'Synthetic setup check'], ?string $token = null, string $endpoint = '/api/mcp/staff'): TestResponse
    {
        $token ??= McpConfig::rotateStaffToken(allowedTools: [self::TOOL], label: 'synthetic-operator');

        return $this->withToken($token)->postJson($endpoint, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => self::TOOL, 'arguments' => ['client_id' => $client->id] + $extra],
        ]);
    }

    private function decoded(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_create_reuse_and_audit_without_recording_the_credential(): void
    {
        $client = Client::factory()->create(['tactical_site_id' => 123]);
        $response = $this->callLink($client);
        $response->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $first = $this->decoded($response);
        $client->refresh();
        $this->assertSame(route('portal.install.show', $client->portal_install_token), $first['url']);
        $this->assertStringStartsWith('http', $first['url']);
        $this->assertSame(['tactical'], $first['available_rmms']);
        $this->assertSame('tactical', $first['effective_rmm']);
        $this->assertSame('tactical', $first['portal_primary_rmm']);
        $this->assertFalse($first['reissued_expired']);
        $this->assertSame(now()->addDays(30)->toIso8601String(), $first['expires_at']);
        $this->travel(1)->hours();
        $second = $this->decoded($this->callLink($client));
        $this->assertSame($first, $second);
        $audits = McpAuditLog::where('tool_name', self::TOOL)->get();
        $this->assertCount(2, $audits);
        $this->assertSame('Synthetic setup check', $audits->first()->arguments['reason']);
        $this->assertNotEmpty($audits->first()->actor_label);
        $this->assertStringNotContainsString($client->portal_install_token, $audits->toJson());
        $this->assertSame(0, PortalInstallAudit::count());
        Http::assertNothingSent();
    }

    public function test_non_operational_clients_cannot_create_reissue_or_retrieve_links(): void
    {
        foreach ([
            ['stage' => \App\Enums\ClientStage::Active, 'is_active' => false],
            ['stage' => \App\Enums\ClientStage::Prospect, 'is_active' => true],
            ['stage' => \App\Enums\ClientStage::Prospect, 'is_active' => false],
        ] as $statusIndex => $status) {
            foreach (['absent', 'live', 'expired', 'unlimited'] as $state) {
                $client = Client::factory()->create($status + [
                    'tactical_site_id' => 123,
                    'portal_install_token' => $state === 'absent' ? null : 'synthetic-status-'.$statusIndex.'-'.$state,
                    'portal_install_token_expires_at' => match ($state) {
                        'live' => now()->addDay(),
                        'expired' => now()->subDay(),
                        default => null,
                    },
                ]);
                $before = $client->fresh()->getAttributes();
                $response = $this->callLink($client);
                $response->assertJsonPath('result.isError', true);
                $result = $this->decoded($response);
                $this->assertSame('Install links are unavailable for non-operational clients.', $result['error']);
                $this->assertArrayNotHasKey('url', $result);
                $this->assertSame(['tactical'], $result['available_rmms']);
                $this->assertSame($before, $client->fresh()->getAttributes());
                if ($before['portal_install_token']) {
                    $this->assertStringNotContainsString($before['portal_install_token'], $response->getContent());
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_status_is_checked_on_locked_refresh_not_the_callers_stale_model(): void
    {
        foreach (['is_active', 'stage'] as $field) {
            foreach ([false, true] as $activate) {
                $active = ['stage' => \App\Enums\ClientStage::Active, 'is_active' => true];
                $inactive = $active;
                $inactive[$field] = $field === 'stage' ? \App\Enums\ClientStage::Prospect : false;
                $client = Client::factory()->create(($activate ? $inactive : $active) + ['tactical_site_id' => 123]);
                Client::whereKey($client->id)->update($activate ? $active : $inactive);
                $before = $client->fresh()->getAttributes();
                $result = app(\App\Services\Portal\PortalInstallService::class)->getOrCreateInstallLink($client);
                if ($activate) {
                    $this->assertArrayNotHasKey('error', $result);
                    $this->assertNotEmpty($client->fresh()->portal_install_token);
                    $this->assertFalse($result['reissued_expired']);
                } else {
                    $this->assertSame('Install links are unavailable for non-operational clients.', $result['error']);
                    $this->assertSame($before, $client->fresh()->getAttributes());
                    $this->assertArrayNotHasKey('url', $result);
                }
            }
        }
    }

    public function test_public_configured_url_is_used_for_create_reuse_and_reissue(): void
    {
        config(['app.url' => 'https://public.example.test/customer/']);
        foreach (['absent', 'live', 'expired', 'unlimited'] as $state) {
            $client = Client::factory()->create([
                'stage' => \App\Enums\ClientStage::Active,
                'is_active' => true,
                'tactical_site_id' => 123,
                'portal_install_token' => $state === 'absent' ? null : 'synthetic-origin-'.$state,
                'portal_install_token_expires_at' => match ($state) {
                    'live' => now()->addDay(),
                    'expired' => now()->subDay(),
                    default => null,
                },
            ]);
            $before = $client->fresh()->getAttributes();
            $response = $this->callLink($client, endpoint: 'http://mcp.example.test/api/mcp/staff');
            $response->assertJsonPath('result.isError', false);
            $result = $this->decoded($response);
            $client->refresh();
            $this->assertSame('https://public.example.test/customer/setup/'.$client->portal_install_token, $result['url']);
            $this->assertSame($state === 'expired', $result['reissued_expired']);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            if (in_array($state, ['live', 'unlimited'], true)) {
                $this->assertSame($before, $client->getAttributes());
            } else {
                $this->assertNotEmpty($client->portal_install_token);
                $this->assertNotSame($before['portal_install_token'], $client->portal_install_token);
            }
        }
        Http::assertNothingSent();
    }

    public function test_invalid_public_root_refuses_before_issuance_or_retrieval(): void
    {
        foreach ([
            null, '', '/relative', 'ftp://public.example.test',
            'https://operator@public.example.test', 'https://public.example.test?x=1', 'https://public.example.test/#fragment',
            'https://psa .example.test', 'https://psa_foo.example.test', 'https://-psa.example.test',
            'https://psa\\evil.example.test/', 'https://public.example.test/a\\b',
            'https://public.example.test/a/./b', 'https://public.example.test/a/../b',
            'https://public.example.test/a//b', 'https://public.example.test//', 'https://public.example.test/a//',
            'https://public.example.test/%2e/b', 'https://public.example.test/%2e%2e/b',
            'https://public.example.test/a%2fb', 'https://public.example.test/a%5cb',
            'https://public.example.test/a b', 'https://public.example.test/bad%xy',
        ] as $rootIndex => $root) {
            config(['app.url' => $root]);
            foreach (['absent', 'live', 'expired', 'unlimited'] as $state) {
                $value = $state === 'absent' ? null : 'synthetic-root-'.$rootIndex.'-'.$state;
                $client = Client::factory()->create([
                    'tactical_site_id' => 123, 'portal_install_token' => $value,
                    'portal_install_token_expires_at' => match ($state) {
                        'live' => now()->addDay(), 'expired' => now()->subDay(), default => null,
                    },
                ]);
                $before = $client->fresh()->getAttributes();
                $response = $this->callLink($client, endpoint: 'https://mcp.example.test/api/mcp/staff');
                $response->assertJsonPath('result.isError', true);
                $result = $this->decoded($response);
                $this->assertSame('Configure a public HTTP(S) application URL before requesting an install link.', $result['error']);
                $this->assertArrayNotHasKey('url', $result);
                $this->assertSame($before, $client->fresh()->getAttributes());
                $this->assertArrayNotHasKey('reissued_expired', $result);
                $this->assertArrayNotHasKey('expires_at', $result);
                if ($value !== null) {
                    $this->assertStringNotContainsString($value, $response->getContent());
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_valid_root_parts_are_rebuilt_with_lowercase_scheme(): void
    {
        foreach ([
            'HTTPS://public.example.test' => 'https://public.example.test',
            'HtTp://public.example.test:8080/customer/v1/' => 'http://public.example.test:8080/customer/v1',
            'https://192.0.2.1/customer' => 'https://192.0.2.1/customer',
            'https://[2001:db8::1]:8443/customer' => 'https://[2001:db8::1]:8443/customer',
            'https://public.example.test/a%20b' => 'https://public.example.test/a%20b',
        ] as $root => $expected) {
            config(['app.url' => $root]);
            $client = Client::factory()->create(['tactical_site_id' => 123]);
            $response = $this->callLink($client, endpoint: 'https://mcp.example.test/api/mcp/staff');
            $response->assertJsonPath('result.isError', false);
            $this->assertSame($expected.'/setup/'.$client->fresh()->portal_install_token, $this->decoded($response)['url']);
        }
    }

    public function test_storage_exception_does_not_reach_response_audit_or_logs(): void
    {
        $client = Client::factory()->create(['tactical_site_id' => 123]);
        $boundValue = 'synthetic-bound-install-value';
        $service = \Mockery::mock(\App\Services\Portal\PortalInstallService::class);
        $service->shouldReceive('getOrCreateInstallLink')->once()->andThrow(
            new \Illuminate\Database\QueryException('sqlite', 'update clients set portal_install_token = ?', [$boundValue], new \PDOException('synthetic storage failure'))
        );
        $this->app->instance(\App\Services\Portal\PortalInstallService::class, $service);
        \Illuminate\Support\Facades\Log::spy();
        $response = $this->callLink($client);
        $response->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('Install-link storage failed', $response->getContent());
        $this->assertStringNotContainsString($boundValue, $response->getContent());
        $this->assertStringNotContainsString($boundValue, McpAuditLog::all()->toJson());
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            \Illuminate\Support\Facades\Log::shouldNotHaveReceived($level, function (...$args) use ($boundValue) {
                return str_contains(json_encode($args), $boundValue);
            });
        }
    }

    public function test_expired_is_reissued_but_null_expiry_is_live(): void
    {
        foreach ([now()->subSecond(), null] as $expiry) {
            $client = Client::factory()->create(['level_group_id' => 'synthetic-group', 'portal_install_token' => 'synthetic-token-'.($expiry ? 'expired' : 'live'), 'portal_install_token_expires_at' => $expiry]);
            $old = $client->portal_install_token;
            $result = $this->decoded($this->callLink($client));
            $client->refresh();
            $this->assertSame($expiry !== null, $result['reissued_expired']);
            if ($expiry) {
                $this->assertNotSame($old, $client->portal_install_token);
                $this->assertSame(now()->addDays(30)->toIso8601String(), $result['expires_at']);
            } else {
                $this->assertSame($old, $client->portal_install_token);
                $this->assertNull($result['expires_at']);
            }
        }
    }

    public function test_unmapped_and_ambiguous_refuse_with_context_without_mutation(): void
    {
        foreach ([[], ['level_group_id' => 'synthetic-group', 'tactical_site_id' => 123]] as $mapping) {
            $client = Client::factory()->create($mapping + ['portal_install_token' => 'synthetic-existing-'.count($mapping), 'portal_install_token_expires_at' => now()->addDay()]);
            $before = $client->fresh()->getAttributes();
            $response = $this->callLink($client);
            $response->assertJsonPath('result.isError', true);
            $result = $this->decoded($response);
            $this->assertSame($mapping ? ['level', 'tactical'] : [], $result['available_rmms']);
            $this->assertNull($result['effective_rmm']);
            $this->assertStringContainsString($mapping ? 'primary RMM' : 'Map this client', $result['error']);
            $this->assertArrayNotHasKey('url', $result);
            $this->assertSame($before, $client->fresh()->getAttributes());
        }
    }

    public function test_explicit_primary_allows_multiple_mappings_and_preserves_live_link(): void
    {
        $client = Client::factory()->create(['level_group_id' => 'synthetic-group', 'tactical_site_id' => 123, 'portal_primary_rmm' => 'tactical']);
        $result = $this->decoded($this->callLink($client));
        $this->assertSame('tactical', $result['effective_rmm']);
        $this->assertSame(['level', 'tactical'], $result['available_rmms']);
        $this->assertSame('tactical', $result['portal_primary_rmm']);
    }

    public function test_reason_and_explicit_grant_are_required_and_portal_never_publishes_it(): void
    {
        $client = Client::factory()->create(['tactical_site_id' => 123]);
        foreach ([[], ['reason' => '  ']] as $args) {
            $response = $this->callLink($client, $args);
            $response->assertJsonPath('result.isError', true);
            $this->assertStringContainsString('reason', $response->json('result.content.0.text'));
            $this->assertNull($client->fresh()->portal_install_token);
        }
        foreach ([null, ['create_ticket']] as $grants) {
            $token = McpConfig::rotateStaffToken(allowedTools: $grants);
            $this->callLink($client, ['reason' => 'Synthetic setup check'], $token)->assertJsonPath('result.isError', true);
            $this->assertNull($client->fresh()->portal_install_token);
        }
        $this->assertNotContains(self::TOOL, PortalMcpToolDefinitions::names());
        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL]);
        $response = $this->withToken($token)->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $tools = collect($response->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools->has(self::TOOL));
        $this->assertSame(['client_id', 'reason'], $tools[self::TOOL]['inputSchema']['required']);
    }
}
