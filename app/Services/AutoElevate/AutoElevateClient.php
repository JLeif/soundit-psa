<?php

namespace App\Services\AutoElevate;

use App\Support\AutoElevateConfig;
use Illuminate\Support\Facades\Http;

/** Stage 1: a status-only, read-only check. Never retain company data or vendor errors. */
class AutoElevateClient
{
    public function checkConnection(): string
    {
        try {
            $key = AutoElevateConfig::get('api_key');
        } catch (\Throwable) {
            return 'configuration';
        }
        if ($key === null || trim($key) === '' || strlen($key) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return 'configuration';
        }

        try {
            $response = Http::baseUrl('https://partner-api.autoelevate.com')
                ->acceptJson()->withToken($key)
                ->withHeaders(['X-Acknowledgment' => 'i-understand-this-is-beta-and-may-change'])
                ->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get('/api/v1/companies', ['take' => 1]);
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
}
