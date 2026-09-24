<?php

namespace App\Services\Litsrmm;

use App\Support\LitsrmmConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Read-only client for the LITSRMM API (Leif IT Solutions RMM).
 *
 * WHAT IS SPECIFIED AND WHAT IS ASSUMED
 * -------------------------------------
 * The vendor proposal (attached to card 6ab46339, 23 Sep 2026) specifies the
 * endpoint paths, the bearer-token auth, and that `/v1/clients` returns `id`
 * and `name`. It does NOT document the response envelope, the pagination
 * mechanism, or any device field below `hostname / OS / online / last seen`.
 *
 * So this class reads `id` and `name` and NOTHING ELSE, and every shape below
 * that is handled defensively rather than guessed at:
 *
 *  - The envelope is read as `data` if present, else the top-level array. That
 *    covers both conventions without asserting which one the vendor uses.
 *    Anything else (another wrapper key, an error object) is not a list and
 *    is logged, as is a non-empty list in which no row is mappable: a wrong
 *    shape must not look like a vendor with no clients.
 *  - Pagination is NOT implemented. Level's cursor scheme (`has_more` +
 *    `starting_after`) is a LEVEL convention, and copying it would encode an
 *    assumption as though it were the contract. getClients() therefore fetches
 *    one page and logs a warning if the response signals more, so an operator
 *    sees a truncated list rather than silently getting one.
 *
 * Deliberately NOT built here: device sync (needs a redacted real /v1/devices
 * response, which is Charlie's ask to make) and webhook alerts (their own PR).
 *
 * AUTH DIFFERS FROM LEVEL ON PURPOSE. LevelClient sends the key raw in the
 * Authorization header; this sends `Bearer <token>`, because the proposal says
 * "Bearer-token auth". Mirroring Level's raw form would contradict the one
 * auth statement the spec actually makes.
 */
class LitsrmmClient
{
    private ?Client $http = null;

    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * Built lazily: a self-hosted vendor has no default base_url, and Guzzle
     * would otherwise be constructed with an empty base_uri at boot for every
     * request in the application, configured or not.
     */
    private function http(): Client
    {
        if ($this->http === null) {
            $options = [
                'base_uri' => rtrim((string) ($this->config['base_url'] ?? ''), '/').'/',
                'timeout' => $this->config['request_timeout'] ?? 30,
            ];

            // A test must be able to observe what left the process rather than
            // reach a real host. Honouring config['handler'] is the same seam
            // GraphClient exposes (card 3EUxsP13): without it, a suite passing
            // a MockHandler is silently ignored and every assertion about the
            // composed request grades a live network attempt instead.
            if (isset($this->config['handler'])) {
                $options['handler'] = $this->config['handler'];
            }

            $this->http = new Client($options);
        }

        return $this->http;
    }

    /**
     * Whether the API answers with the configured credentials.
     *
     * Returns false rather than throwing so the settings screen can report a
     * failed Test Connection without a 500. Gated on isAvailable(): a disabled
     * integration makes no outbound request at all, which is the property the
     * Tactical bug in the vendor's own proposal describes losing.
     */
    public function isHealthy(): bool
    {
        if (! LitsrmmConfig::isAvailable()) {
            return false;
        }

        try {
            $this->get('v1/health');

            return true;
        } catch (LitsrmmClientException) {
            return false;
        }
    }

    /**
     * The mappable entities: what a technician picks a client from.
     *
     * Only `id` and `name` are read, because only those two are specified.
     */
    public function getClients(): array
    {
        if (! LitsrmmConfig::isAvailable()) {
            return [];
        }

        $response = $this->get('v1/clients', ['limit' => 100]);

        $clients = $response['data'] ?? $response;

        if (! is_array($clients) || ! array_is_list($clients)) {
            Log::warning('[LitsrmmClient] /v1/clients did not return a list', [
                'type' => gettype($clients),
                'keys' => is_array($clients) ? array_slice(array_keys($clients), 0, 10) : [],
            ]);

            return [];
        }

        // The proposal does not document pagination. If the vendor signals more
        // pages by any of the conventions its sibling integrations use, say so
        // out loud: a silently truncated entity list is a mapping screen that
        // omits clients without admitting it.
        $more = $response['has_more'] ?? $response['next_page'] ?? $response['next'] ?? null;
        if (! empty($more)) {
            Log::warning('[LitsrmmClient] /v1/clients signalled more results than one page returned; pagination is not implemented because the API contract does not document it', [
                'returned' => count($clients),
            ]);
        }

        $rows = array_values(array_filter(
            array_map(static fn ($c) => is_array($c) ? [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
            ] : null, $clients),
            static fn ($c) => $c !== null && $c['id'] !== '',
        ));

        if ($clients !== [] && $rows === []) {
            Log::warning('[LitsrmmClient] /v1/clients returned rows but none were mappable', [
                'received' => count($clients),
            ]);
        }

        return $rows;
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            throw new LitsrmmClientException('LITSRMM API key not configured');
        }

        // No default host: refuse rather than aim a credentialed request at
        // whatever answers on a guessed address.
        if (empty($this->config['base_url'])) {
            throw new LitsrmmClientException('LITSRMM base URL not configured');
        }

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
        ]);

        try {
            $response = $this->http()->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : 0;

            // The message is logged and re-thrown WITHOUT the request options:
            // those carry the Authorization header.
            Log::warning('[LitsrmmClient] request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
            ]);

            throw new LitsrmmClientException(
                "LITSRMM API error: {$method} {$endpoint} returned {$status}",
                $status,
                $e,
            );
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new LitsrmmClientException("LITSRMM API returned a non-JSON body for {$method} {$endpoint}");
        }

        return $decoded;
    }
}
