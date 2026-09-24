<?php

namespace App\Services\Litsrmm;

use App\Support\LitsrmmConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
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
    /**
     * Hosts for which plain HTTP carries no network exposure.
     *
     * Matched on the parsed host, never on the raw string: a substring test
     * would accept http://localhost.attacker.example, which is a different
     * machine with a reassuring name.
     */
    private static function hostIsLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        // 127.0.0.0/8 in full, not just 127.0.0.1.
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && str_starts_with($host, '127.');
    }

    /**
     * Refuse a base URL that would send the bearer token in cleartext.
     *
     * There is deliberately NO override: an operator who needs plain HTTP
     * across a network is asking for a credential-policy decision, not for a
     * flag. A malformed URL is refused too, rather than assumed safe, because
     * an unparseable host is one nobody has checked.
     */
    public static function assertTransportIsSafe(string $baseUrl): void
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        if ($scheme === 'https') {
            return;
        }

        if ($host === '') {
            Log::warning('[LitsrmmClient] refusing a base URL with no parseable host', [
                'scheme' => $scheme,
            ]);

            throw new LitsrmmClientException('LITSRMM base URL is not a valid absolute URL');
        }

        if ($scheme === 'http' && self::hostIsLoopback($host)) {
            return;
        }

        Log::warning('[LitsrmmClient] refusing a plaintext request: the API key would cross the network in cleartext', [
            'scheme' => $scheme,
            'host' => $host,
        ]);

        throw new LitsrmmClientException(
            'LITSRMM base URL must use https (plain http is allowed only for a loopback host)'
        );
    }

    /**
     * Resolve an endpoint against the base URL the way Guzzle will, and refuse
     * anything that lands off the configured host.
     *
     * WHY THIS EXISTS SEPARATELY FROM assertTransportIsSafe() (#3337 diff:2).
     * That method judges the CONFIGURED base_url. Guzzle does not request the
     * base_url; it requests base_uri resolved against $endpoint under RFC 3986,
     * and under those rules an absolute endpoint replaces the whole authority
     * while a protocol-relative one (`//host/path`) replaces the host and
     * INHERITS the base scheme. So a guard that only reads the configured
     * string is checking a URL that is not the one the credential crosses.
     *
     * Measured on the unfixed code, all of these left the process with the
     * Authorization header attached:
     *   base https://rmm.example.com + 'http://rmm.example.com/v1/clients'
     *      -> plaintext, same host
     *   base https://rmm.example.com + '//evil.example/v1/x'
     *      -> https://evil.example/v1/x
     *   base http://127.0.0.1:8080  + '//evil.example/v1/x'
     *      -> http://evil.example/v1/x, i.e. the loopback exemption carried
     *         onto a remote host in cleartext
     *
     * The host equality check is what makes this hold for a future caller that
     * follows a vendor-supplied `next` link, which is exactly how stage 2's
     * paging will be written. Scheme safety is re-asserted on the RESOLVED URI
     * rather than inferred, because the loopback exemption is a statement about
     * a host and must not survive a change of host.
     */
    private function assertEndpointStaysOnTheConfiguredHost(string $baseUrl, string $endpoint): void
    {
        // UriResolver::resolve, not Uri::resolve: the latter was removed in
        // guzzlehttp/psr7 v2. This is the SAME resolver Guzzle's own
        // RedirectMiddleware and base_uri handling call, so the URL judged here
        // is the URL that will be requested, not a second implementation of
        // RFC 3986 that could drift from it.
        $resolved = (string) UriResolver::resolve(
            new Uri(rtrim($baseUrl, '/').'/'),
            new Uri($endpoint),
        );

        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $host = strtolower((string) parse_url($resolved, PHP_URL_HOST));

        if ($host !== $baseHost) {
            // The endpoint is logged, the credential is not, and the throw
            // happens before any Authorization header is built.
            Log::warning('[LitsrmmClient] refusing an endpoint that resolves off the configured host', [
                'configured_host' => $baseHost,
                'resolved_host' => $host,
            ]);

            throw new LitsrmmClientException(
                'LITSRMM endpoint resolves to a different host than the configured base URL'
            );
        }

        // Same host, but the endpoint may still have downgraded the scheme.
        self::assertTransportIsSafe($resolved);
    }

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

        // THE AVAILABILITY CHOKE POINT (#3298 diff:2, C-47).
        //
        // getClients() and isHealthy() each gated themselves, which held only
        // because they were the only callers. The public get() reaches this
        // method directly, so an operator's OFF switch depended on every future
        // caller remembering to ask; stage 2's device sync is that caller.
        // Gating here makes OFF=OFF a property of the transport rather than a
        // convention the next author has to know about. The two methods keep
        // their own checks because they return a value rather than throwing.
        //
        // ORDER IS DELIBERATE. This sits AFTER the two credential checks, not
        // before them. Both orders are equally safe -- neither reaches the
        // network -- but a client built with no api_key would otherwise report
        // "switched off", which is a misdiagnosis: isAvailable() is
        // isConfigured() AND isEnabled(), so missing credentials make it false
        // for a reason that has nothing to do with the operator's switch. The
        // specific refusal is the more useful one and it keeps its meaning.
        if (! LitsrmmConfig::isAvailable()) {
            throw new LitsrmmClientException('LITSRMM integration is switched off');
        }

        // Refuse plaintext BEFORE the Authorization header exists, so a refused
        // request cannot have carried the credential. The form validates the
        // same rule, but env and config bypass the form entirely, which is why
        // the real guarantee has to live here.
        //
        // Loopback is the one exception, and it is the case the vendor's
        // proposal describes: their own deployment runs the RMM and the PSA on
        // one machine, so there is no network for a bearer token to cross. Any
        // other host is remote from us whatever the vendor's topology is.
        self::assertTransportIsSafe((string) $this->config['base_url']);

        // ...and then the URI Guzzle will ACTUALLY request, which is not the
        // same string. See the method's docblock.
        $this->assertEndpointStaysOnTheConfiguredHost((string) $this->config['base_url'], $endpoint);

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
