<?php

namespace App\Services\ControlD;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ControlDClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            // Handler injection keeps wire-level tests off the live vendor.
            'handler' => $this->config['handler'] ?? null,
            'base_uri' => 'https://api.controld.com/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make an authenticated GET request to the Control D API.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->http->request('GET', $endpoint);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Check if the Control D API is reachable with the configured credentials.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('profiles');

            return true;
        } catch (ControlDClientException) {
            return false;
        }
    }

    /**
     * Make an authenticated GET request scoped to a specific sub-organization.
     * Control D requires X-Force-Org-Id header to access sub-org resources.
     */
    public function getForOrg(string $endpoint, string $orgPk): array
    {
        try {
            $response = $this->http->request('GET', $endpoint, [
                'headers' => [
                    'X-Force-Org-Id' => $orgPk,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} (org: {$orgPk}) failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /** $body may carry a deactivation PIN; keep it out of this frame's trace arguments. */
    public function postForOrg(string $endpoint, string $orgPk, #[\SensitiveParameter] array $body): array
    {
        return $this->requestForOrg('POST', $endpoint, $orgPk, $body);
    }

    public function putForOrg(string $endpoint, string $orgPk, ?array $body = null): array
    {
        return $this->requestForOrg('PUT', $endpoint, $orgPk, $body);
    }

    public function deleteForOrg(string $endpoint, string $orgPk): array
    {
        return $this->requestForOrg('DELETE', $endpoint, $orgPk);
    }

    /**
     * Strict provisioning transport: no retries, redirects, or secret-bearing errors.
     * $body may carry a deactivation PIN. #[\SensitiveParameter] redacts only the parameter
     * it decorates, never a copy held by another frame, so every frame that takes the body
     * annotates it: otherwise the rejection/transport throws below would leave the PIN in
     * live trace arguments wherever zend.exception_ignore_args is Off.
     */
    public function requestForOrg(string $method, string $endpoint, string $orgPk, #[\SensitiveParameter] ?array $body = null): array
    {
        if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)
            || (! preg_match('/\Aprovision(?:\/[A-Za-z0-9_-]+(?:\/invalidate)?)?\z/', $endpoint)
                && ! in_array($endpoint, ['devices/types', 'profiles', 'organizations/organization'], true))
            || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $orgPk)
            || ! is_string($this->config['api_key'] ?? null) || trim($this->config['api_key']) === '') {
            throw new ControlDClientException('Control D scoped request is invalid or unconfigured.');
        }
        $options = ['headers' => ['X-Force-Org-Id' => $orgPk], 'allow_redirects' => false, 'http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException) {
            // Guzzle messages/previous exceptions may contain codes, PINs or credentials.
            throw new ControlDClientException('Control D scoped request failed; outcome may be unknown.');
        }
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            throw new ControlDWriteRejectedException('Control D scoped request was rejected (HTTP 4xx).');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ControlDClientException('Control D scoped request did not succeed.');
        }
        try {
            $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ControlDClientException('Control D response is not valid JSON.');
        }
        if (! $decoded instanceof \stdClass || ($decoded->success ?? null) !== true) {
            throw new ControlDClientException('Control D response did not confirm success.');
        }

        // Keep container identity for strict response-shape checks at the consumer.
        return ['success' => true, 'body' => $decoded->body ?? null];
    }

    /**
     * Get all devices for a sub-organization.
     */
    public function getDevices(string $orgPk): array
    {
        $response = $this->getForOrg('devices', $orgPk);

        return $response['body']['devices'] ?? [];
    }

    /**
     * Get all sub-organizations with device counts.
     * Response is wrapped as { body: { sub_organizations: [...] } } — two-level unwrapping.
     */
    public function getSubOrganizations(): array
    {
        $response = $this->get('organizations/sub_organizations');

        return $response['body']['sub_organizations'] ?? [];
    }

    /**
     * Get the parent organization data (includes stats_endpoint).
     */
    public function getOrganization(): array
    {
        $response = $this->get('organizations/organization');

        return $response['body']['organization'] ?? [];
    }

    /**
     * Get the stats endpoint (analytics subdomain) from the org API.
     * Returns e.g. "jfk-org01" or null if not available.
     */
    public function getStatsEndpoint(): ?string
    {
        $org = $this->getOrganization();

        return $org['stats_endpoint'] ?? null;
    }
}
