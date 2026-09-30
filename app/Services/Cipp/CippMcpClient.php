<?php

namespace App\Services\Cipp;

use App\Support\SafeUrlInspector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CippMcpClient
{
    private const TOKEN_CACHE_KEY = 'cipp_mcp_oauth_token';

    private const SIGN_IN_FAILED_CACHE_KEY = 'cipp_mcp_sign_in_failed';

    /**
     * #4393: after a failed token request, further sign-ins are not attempted for
     * this many seconds; each read in that window fails over at once instead of
     * POSTing a token request bound to fail. Short and fixed, so a fixed
     * credential or a restored Entra is picked up within a minute.
     */
    public const SIGN_IN_FAILURE_TTL = 60;

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?callable $resolver = null,
        private readonly ?CippMcpConnector $connector = null,
    ) {
        $this->resolver = $resolver ?? 'gethostbynamel';
    }

    /**
     * Call one official CIPP ExecMCP tool through JSON-RPC over HTTP.
     *
     * @return array<int|string, mixed>
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $execMcpUrl = $this->execMcpUrl();

        $response = Http::timeout(60)
            ->accept('text/event-stream')
            ->withOptions($this->safeRequestOptions($execMcpUrl))
            ->withToken($this->getToken())
            ->withQueryParameters(['tools' => $toolName])
            ->post($execMcpUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $toolName,
                    'arguments' => $arguments,
                ],
            ]);

        if ($response->status() === 401) {
            throw new CippMcpAuthException("CIPP MCP {$toolName} was refused: HTTP 401 ".mb_substr($response->body(), 0, 500));
        }

        if ($response->failed()) {
            throw new CippClientException("CIPP MCP {$toolName} failed: HTTP {$response->status()} ".mb_substr($response->body(), 0, 500));
        }

        return $this->decodeJsonRpcPayload((string) $response->body());
    }

    /**
     * List the official CIPP MCP tools currently advertised by ExecMCP.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $execMcpUrl = $this->execMcpUrl();

        $response = Http::timeout(60)
            ->acceptJson()
            ->withOptions($this->safeRequestOptions($execMcpUrl))
            ->withToken($this->getToken())
            ->post($execMcpUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => new \stdClass,
            ]);

        if ($response->failed()) {
            throw new CippClientException('CIPP MCP tools/list failed: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        $payload = $this->decodeJsonRpcPayload((string) $response->body());
        $tools = $payload['tools'] ?? [];

        return is_array($tools) ? array_values(array_filter($tools, 'is_array')) : [];
    }

    /**
     * An access token for ExecMCP.
     *
     * With a delegated connector (CIPP v11+; see CippMcpConnector) it is minted from
     * the stored refresh token. Without one this is the pre-v11 app-only
     * client_credentials sign-in, unchanged. Either way every failure is a
     * CippMcpAuthException, which the curated reads fail over to REST on, and a
     * failed token request is remembered for SIGN_IN_FAILURE_TTL seconds (#4393).
     */
    private function getToken(): string
    {
        if ($this->connectorIsConnected()) {
            return $this->getDelegatedToken();
        }

        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new CippMcpAuthException('CIPP MCP client credentials are not configured');
        }

        $cacheKey = $this->tokenCacheKey($tenantId, $clientId);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $failedKey = $this->signInFailedKey($tenantId, $clientId, 'app');
        $this->refuseIfRecentlyFailed($failedKey);

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$clientId}/.default",
                ])
                ->throw();
        } catch (RequestException $e) {
            $this->rememberSignInFailure($failedKey);
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippMcpAuthException("CIPP MCP OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        } catch (\Throwable $e) {
            $this->rememberSignInFailure($failedKey);
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippMcpAuthException("CIPP MCP OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            $this->rememberSignInFailure($failedKey);
            throw new CippMcpAuthException('CIPP MCP OAuth response missing access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function tokenCacheKey(string $tenantId, string $clientId): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId);
    }

    private function connectorIsConnected(): bool
    {
        if ($this->connector === null) {
            return false;
        }

        try {
            return $this->connector->isConnected();
        } catch (\Throwable $e) {
            // Settings unreadable: behave as before the connector existed.
            Log::warning('[CippMcpClient] Could not read the CIPP MCP connector state', ['exception' => class_basename($e)]);

            return false;
        }
    }

    /**
     * Delegated sign-in: refresh_token grant for
     * `<backend host>/user_impersonation offline_access`. A rotated refresh token is
     * persisted; a failure is reported to the connector (one alert per episode)
     * and surfaces as CippMcpAuthException carrying the vendor error CODE only.
     */
    private function getDelegatedToken(): string
    {
        /** @var CippMcpConnector $connector */
        $connector = $this->connector;
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');

        if ($tenantId === '' || $clientId === '') {
            throw new CippMcpAuthException('CIPP MCP connector is present but the tenant id or MCP client id is not configured');
        }

        $generation = $connector->generation();
        $cacheKey = self::TOKEN_CACHE_KEY.':delegated:'.sha1($tenantId.'|'.$clientId.'|'.$generation);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $failedKey = $this->signInFailedKey($tenantId, $clientId, 'delegated|'.$generation);
        $this->refuseIfRecentlyFailed($failedKey);

        try {
            $refreshToken = $connector->refreshToken();
            $scope = CippMcpConnector::scope();
        } catch (\Throwable $e) {
            $code = $e instanceof CippMcpAuthException ? 'backend_host_not_configured' : 'stored_token_unreadable';
            $this->rememberSignInFailure($failedKey);
            $connector->recordRefreshFailure($code);
            throw new CippMcpAuthException('CIPP MCP connector refresh could not start: '.$code);
        }

        $fields = ['client_id' => $clientId];
        $clientSecret = (string) ($this->config['client_secret'] ?? '');
        if ($clientSecret !== '') {
            $fields['client_secret'] = $clientSecret;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(CippMcpConnector::tokenUrl($tenantId), $fields + [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $refreshToken,
                'scope' => $scope,
            ]);
            $failure = $response->failed() ? CippMcpConnector::errorCode($response) : null;
        } catch (\Throwable $e) {
            $response = null;
            $failure = 'unreachable ('.class_basename($e).')';
        }

        $token = $response?->json('access_token');
        if ($failure === null && (! is_string($token) || $token === '')) {
            $failure = 'no_access_token';
        }

        if ($failure !== null) {
            $this->rememberSignInFailure($failedKey);
            $connector->recordRefreshFailure($failure);
            Log::error('[CippMcpClient] CIPP MCP connector refresh failed', ['error_code' => $failure]);
            throw new CippMcpAuthException('CIPP MCP connector refresh failed: '.$failure);
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $connector->storeRotatedRefreshToken($response->json('refresh_token'));
        $connector->recordAccessExpiry($expiresIn);
        $connector->recordRefreshSuccess();
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function signInFailedKey(string $tenantId, string $clientId, string $mode): string
    {
        return self::SIGN_IN_FAILED_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId.'|'.$mode);
    }

    private function refuseIfRecentlyFailed(string $failedKey): void
    {
        if ($this->cache->has($failedKey)) {
            throw new CippMcpAuthException('CIPP MCP sign-in failed within the last '.self::SIGN_IN_FAILURE_TTL.'s; not retrying yet');
        }
    }

    private function rememberSignInFailure(string $failedKey): void
    {
        $this->cache->put($failedKey, true, self::SIGN_IN_FAILURE_TTL);
    }

    private function execMcpUrl(): string
    {
        $apiUrl = (string) ($this->config['api_url'] ?? '');
        if ($apiUrl === '') {
            throw new CippClientException('CIPP API URL is not configured');
        }

        return rtrim($apiUrl, '/').'/api/ExecMCP';
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRequestOptions(string $url): array
    {
        $rejection = SafeUrlInspector::reject($url, $this->resolver);
        if ($rejection !== null) {
            throw new CippClientException(str_replace('Tactical API URL', 'CIPP API URL', $rejection));
        }

        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        $resolver = $this->resolver;
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            throw new CippClientException("CIPP API host '{$host}' did not resolve (refused for safety).");
        }

        foreach ($ips as $ip) {
            if (! SafeUrlInspector::ipIsSafe($ip)) {
                throw new CippClientException("CIPP API host '{$host}' resolved to a private or reserved address ({$ip}); refused.");
            }
        }

        $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $ips)]];

        return $options;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJsonRpcPayload(string $body): array
    {
        $payload = $this->firstSseDataPayload($body) ?? $body;
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new CippClientException('CIPP MCP response was not valid JSON-RPC');
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            throw new CippClientException('CIPP MCP JSON-RPC error: '.$message);
        }

        $result = $decoded['result'] ?? $decoded;
        if (! is_array($result)) {
            return ['value' => $result];
        }

        return $this->unwrapMcpResult($result);
    }

    private function firstSseDataPayload(string $body): ?string
    {
        $events = preg_split("/\R\R/", trim($body)) ?: [];

        foreach ($events as $event) {
            $data = [];
            foreach (preg_split("/\R/", $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(mb_substr($line, 5));
                }
            }

            if ($data === []) {
                continue;
            }

            $payload = implode("\n", $data);
            if ($payload !== '[DONE]') {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unwrapMcpResult(array $result): array
    {
        $text = null;

        if (isset($result['content']) && is_array($result['content'])) {
            $text = '';
            foreach ($result['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        // CIPP's ExecMCP reports tool failures as HTTP 200 + isError:true with
        // the message as text content. Surface them as exceptions — otherwise
        // the message decodes into pseudo-rows that read as a false-empty
        // result downstream (psa-3twu).
        if (($result['isError'] ?? false) === true) {
            throw new CippClientException('CIPP MCP tool error: '.mb_substr($text !== null && $text !== '' ? $text : 'no error detail provided', 0, 500));
        }

        if ($text !== null) {
            if ($text === '') {
                // Empty tool result: PowerShell's ConvertTo-Json emits nothing
                // for an empty array, so "no rows" arrives as empty text — not
                // a reason to fall back to the envelope, which would fabricate
                // one junk row out of {content, isError}.
                return [];
            }

            $decodedText = json_decode($text, true);
            if (is_array($decodedText)) {
                return $this->unwrapCippEnvelope($decodedText);
            }

            return ['text' => $text];
        }

        return $this->unwrapCippEnvelope($result);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unwrapCippEnvelope(array $data): array
    {
        // "Still loading" is not "nothing found" — see CippQueueGuard for the
        // vendor shapes and why this must throw rather than unwrap to [].
        CippQueueGuard::assertNotQueueBacked($data);

        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }
}
