<?php

namespace App\Services\AutoElevate;

use App\Support\AutoElevateConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Partner API client. Stage 1: a status-only connection check. Stage 2 adds
 * `getPage()`, a single validated list-page read used by AutoElevateReadService.
 * Never retains company data or vendor errors; never writes at the vendor.
 *
 * Vendor contract: AutoElevate Partner API (Beta) 1.0.0 OpenAPI
 * (https://partner-api-docs.autoelevate.com/openapi.json, verified 2026-09-17):
 * every list endpoint takes `take` (1..200, default 50) + `skip` (>= 0) and returns the
 * `{items: [...], totalCount: N}` envelope (both required). `totalCount` is the total
 * ignoring pagination and is documented to be 0 when `skip` is past the end.
 */
class AutoElevateClient
{
    public const BASE_URL = 'https://partner-api.autoelevate.com';

    /** Documented maximum page size. Larger values are capped server-side, so never ask for more. */
    public const MAX_TAKE = 200;

    public function checkConnection(): string
    {
        $key = $this->storedKey();
        if ($key === null) {
            return 'configuration';
        }

        try {
            $response = $this->pending($key)->get('/api/v1/companies', ['take' => 1]);
        } catch (\Throwable) {
            // Transport exceptions may contain credentials: no logging or chained exception.
            return 'transport';
        }

        // HTTP status alone is authoritative. Do not parse or persist any response body/header.
        return match ($response->status()) {
            200 => 'ok',
            400 => '400',
            401 => '401',
            403 => '403',
            406 => '406',
            429 => '429',
            default => 'error',
        };
    }

    /**
     * One list page, validated to the documented envelope.
     *
     * @param  array<string, int|string>  $query
     * @return array{items: list<array<string, mixed>>, totalCount: int}
     *
     * @throws AutoElevateReadException on configuration, transport, non-200 status or envelope drift
     */
    public function getPage(string $path, array $query): array
    {
        $key = $this->storedKey();
        if ($key === null) {
            throw new AutoElevateReadException('configuration');
        }

        try {
            $response = $this->pending($key)->get($path, $query);
        } catch (\Throwable) {
            // Never chain: a transport exception may quote the request, including the key.
            throw new AutoElevateReadException('transport');
        }

        if ($response->status() !== 200) {
            throw new AutoElevateReadException('http_'.$response->status());
        }

        $body = json_decode($response->body(), true);

        // Envelope proof: an object with `items` (list) and `totalCount` (non-negative int).
        // A bare array, a missing key, or a non-list `items` is drift — never "zero rows".
        if (! is_array($body) || array_is_list($body)
            || ! array_key_exists('items', $body) || ! is_array($body['items']) || ! array_is_list($body['items'])
            || ! array_key_exists('totalCount', $body) || ! is_int($body['totalCount']) || $body['totalCount'] < 0) {
            throw new AutoElevateReadException('envelope_drift');
        }

        foreach ($body['items'] as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new AutoElevateReadException('row_drift');
            }
        }

        return ['items' => $body['items'], 'totalCount' => $body['totalCount']];
    }

    private function storedKey(): ?string
    {
        try {
            $key = AutoElevateConfig::get('api_key');
        } catch (\Throwable) {
            return null;
        }
        if ($key === null || trim($key) === '' || strlen($key) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return null;
        }

        return $key;
    }

    private function pending(string $key): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()->withToken($key)
            ->withHeaders(['X-Acknowledgment' => 'i-understand-this-is-beta-and-may-change'])
            ->connectTimeout(3)->timeout(10)
            ->withOptions(['allow_redirects' => false]);
    }
}
