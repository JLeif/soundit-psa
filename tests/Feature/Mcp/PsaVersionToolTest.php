<?php

namespace Tests\Feature\Mcp;

use App\Services\VersionService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * psa_version on the staff MCP surface (#3982).
 *
 * The commit is read by the REAL VersionService from a REAL git plumbing layout
 * written into a temp directory, and the call goes through POST /api/mcp/staff with
 * its middleware. No layer between the fixture and the JSON answer is faked, except
 * in the one test that says so.
 */
class PsaVersionToolTest extends TestCase
{
    use RefreshDatabase;

    private const SHA_A = '03005ea8e33f53dfe1be832d50ed6735043899c3';

    private const SHA_B = '5fd10881f5d4a43f51544c86dcbae8035c025df3';

    private ?string $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = sys_get_temp_dir().'/psa-version-mcp-'.bin2hex(random_bytes(6));
        mkdir($this->fixture.'/.git/refs/heads', 0755, true);
        app()->setBasePath($this->fixture);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            exec('rm -rf '.escapeshellarg($this->fixture));
        }
        parent::tearDown();
    }

    private function servingBranch(string $sha): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', $sha."\n");
    }

    private function mcpCall(?string $token, string $name = 'psa_version', array $arguments = []): TestResponse
    {
        // Headers are passed per request: withHeaders() persists for the rest of the
        // test, which would send the previous call's bearer token on a token-less call.
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $headers);
    }

    /** @return array<string, mixed> */
    private function answer(TestResponse $response): array
    {
        $response->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'psa_version did not answer with a JSON object: '.$text);

        return $decoded;
    }

    private function grantedToken(): string
    {
        return McpConfig::rotateStaffToken(allowedTools: ['psa_version'], label: 'version-reader');
    }

    public function test_the_commit_is_the_sha_the_version_service_reads(): void
    {
        $this->servingBranch(self::SHA_A);

        $answer = $this->answer($this->mcpCall($this->grantedToken()));

        $this->assertSame(self::SHA_A, app(VersionService::class)->current()['commit_hash']);
        $this->assertSame(self::SHA_A, $answer['commit']);
        $this->assertSame('03005ea', $answer['commit_short']);
        $this->assertSame('git-plumbing', $answer['source']);
        $this->assertNotEmpty($answer['read_at']);
        $this->assertNull($answer['error']);
        $this->assertSame(['commit', 'commit_short', 'read_at', 'source', 'error'], array_keys($answer));
    }

    public function test_a_failed_read_returns_a_null_commit_and_the_reason(): void
    {
        // No HEAD file: VersionService::current() fails and says why.
        $current = app(VersionService::class)->current();
        $this->assertSame(VersionService::UNKNOWN, $current['commit_hash']);
        $this->assertNotNull($current['error']);

        $response = $this->mcpCall($this->grantedToken());
        $answer = $this->answer($response);

        $this->assertNull($answer['commit']);
        $this->assertNull($answer['commit_short']);
        $this->assertSame($current['error'], $answer['error']);
        $this->assertTrue((bool) $response->json('result.isError'));
    }

    public function test_an_errorless_read_without_a_commit_id_is_still_a_failure(): void
    {
        // The one faked service in this file: current() never returns this shape today,
        // so the only way to exercise the tool's own sha check is to hand it one.
        $this->app->instance(VersionService::class, new class extends VersionService
        {
            public function current(): array
            {
                return ['commit_hash' => '', 'commit_short' => '', 'read_at' => '2026-09-27 00:00:00', 'source' => 'git-plumbing', 'error' => null];
            }
        });

        $answer = $this->answer($this->mcpCall($this->grantedToken()));

        $this->assertNull($answer['commit']);
        $this->assertIsString($answer['error']);
        $this->assertNotSame('', $answer['error']);
    }

    public function test_two_calls_either_side_of_a_head_change_report_different_commits(): void
    {
        $token = $this->grantedToken();

        $this->servingBranch(self::SHA_A);
        $first = $this->answer($this->mcpCall($token));

        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_B."\n");
        $second = $this->answer($this->mcpCall($token));

        $this->assertSame(self::SHA_A, $first['commit']);
        $this->assertSame(self::SHA_B, $second['commit']);
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->servingBranch(self::SHA_A);
        // A token must exist, or the middleware answers 503 (surface not configured)
        // before it ever looks for a bearer token.
        $granted = $this->grantedToken();

        foreach ([null, $granted.'x'] as $token) {
            $response = $this->mcpCall($token);
            $response->assertStatus(401);
            $this->assertSame('Unauthorized', $response->json('error.message'));
            $this->assertStringNotContainsString(self::SHA_A, (string) $response->getContent());
        }

        // Positive control: the same request with the real token is answered.
        $this->assertSame(self::SHA_A, $this->answer($this->mcpCall($granted))['commit']);
    }

    public function test_it_is_a_psa_read_that_needs_an_explicit_grant(): void
    {
        $this->servingBranch(self::SHA_A);

        $this->assertContains('psa_version', array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));

        foreach ([McpConfig::rotateStaffToken(), McpConfig::rotateStaffToken(allowedTools: ['whoami'], label: 'other')] as $token) {
            $response = $this->mcpCall($token);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'));
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            $this->assertStringNotContainsString(self::SHA_A, (string) $response->getContent());
        }
    }

    public function test_an_argument_is_refused_rather_than_ignored(): void
    {
        $this->servingBranch(self::SHA_A);

        $response = $this->mcpCall($this->grantedToken(), arguments: ['refresh' => true]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Unsupported MCP argument', (string) $response->json('result.content.0.text'));
    }
}
