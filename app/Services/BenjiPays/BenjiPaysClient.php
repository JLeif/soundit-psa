<?php

namespace App\Services\BenjiPays;

use App\Support\BenjiPaysConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Read-only merchant API. No retries, redirects, accounting expansion or payment operations. */
class BenjiPaysClient
{
    public function gateways(): array
    {
        $data = $this->get('/v2/gateways');
        if (! is_array($data) || ! array_is_list($data)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $data;
    }

    public function invoice(string $accountingInvoiceId): array
    {
        if (trim($accountingInvoiceId) === '' || strlen($accountingInvoiceId) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $accountingInvoiceId)
            || in_array($accountingInvoiceId, ['.', '..'], true)) {
            throw new BenjiPaysException('invalid_id');
        }

        $data = $this->get('/v2/invoices/'.rawurlencode($accountingInvoiceId));
        if (! is_array($data) || array_is_list($data)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $data;
    }

    private function get(string $path): mixed
    {
        // Read only through the existing encrypted accessor. Never include the key in errors.
        try {
            $key = BenjiPaysConfig::get('api_key');
        } catch (\Throwable) {
            throw new BenjiPaysException('configuration');
        }
        if ($key === null || trim($key) === '' || strlen($key) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new BenjiPaysException('configuration');
        }

        try {
            $response = Http::baseUrl('https://api.benjipays.com')
                ->acceptJson()->asJson()
                ->withHeaders([
                    'x-api-key' => $key,
                    'X-Request-ID' => (string) Str::uuid(),
                    'X-Correlation-ID' => (string) Str::uuid(),
                ])
                ->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get($path);
        } catch (\Throwable) {
            // Transport exceptions can include request headers. Deliberately no previous chain.
            throw new BenjiPaysException('transport');
        }

        if ($response->status() !== 200) {
            // The HTTP status is authoritative, not an untrusted RFC7807 status/detail/body.
            // Parse the envelope but retain no vendor-controlled strings or headers.
            $response->json();
            throw new BenjiPaysException(match ($response->status()) {
                401 => 'unauthorized',
                403 => 'forbidden',
                default => 'http_error',
            }, $response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('data', $body)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $body['data'];
    }
}
