<?php

namespace App\Services\BenjiPays;

use App\Support\BenjiPaysConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Merchant API client. No retries, redirects, accounting expansion or charges.
 *
 * Reads: gateways, invoice balance (stage 1), the auto-processing forecast
 * for one invoice (card revwQxh4), and one customer's transactions and saved
 * payment methods (card 6abec4f9; GET only). The one write is
 * createAppliedPaymentLink() (stage 2, #2065): minting a tokenized pay-now
 * link moves no money — the client pays on the vendor's page, if at all.
 */
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
        $data = $this->get('/v2/invoices/'.$this->invoicePathSegment($accountingInvoiceId));
        if (! is_array($data) || array_is_list($data)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $data;
    }

    /**
     * Mint an applied (invoice-tied) payment link: POST /v2/payment-links/applied/{invoiceId}.
     *
     * Source: developer.benjipays.com/reference/post_v2-payment-links-applied-invoiceid
     * (read 2026-09-17). The body is ALWAYS `{"allowSavedPaymentMethods": false}`:
     * the vendor's own security note says `true` may show the invoice customer's
     * stored cards to whoever holds the link, and a portal login is not proof the
     * holder may use them. `Idempotency-Key` (1–255 alphanumeric) is fresh per
     * mint; the vendor caches the first response per key for 24 h and answers a
     * same-key/different-body replay with 409, so a reused key would surface as
     * a false `http_error`. The 200 body is a bare `{url, expiresAt}` object —
     * not the `{data: …}` envelope the reads unwrap — validated in
     * AppliedPaymentLink::fromResponse(). No money moves by minting.
     */
    public function createAppliedPaymentLink(string $accountingInvoiceId): AppliedPaymentLink
    {
        $path = '/v2/payment-links/applied/'.$this->invoicePathSegment($accountingInvoiceId);
        $response = $this->send('POST', $path, ['allowSavedPaymentMethods' => false], [
            'Idempotency-Key' => Str::random(32),
        ]);

        return AppliedPaymentLink::fromResponse($response->json());
    }

    /**
     * READ-ONLY auto-processing forecast for ONE accounting (QBO) invoice id:
     * GET /v2/autoprocessing-forecast?startDate=YYYY-MM-DD&invoiceId=<id>.
     *
     * Source: developer.benjipays.com/reference/get_v2-autoprocessing-forecast
     * (OpenAPI 3.1, operation updatedAt 2026-07-13, read 2026-09-30). Needs
     * the key scope `organizations:autoprocessing:read`; a missing scope is a
     * 403 (as is a missing owner mapping permission or a lapsed trial), which
     * send() turns into the status-only `forbidden` exception like every other
     * call. `startDate` is the run date the forecast is evaluated against
     * (required); with `invoiceId` the vendor forecasts that invoice only and
     * ignores pagination. The vendor forecasts OPEN invoices only, so an
     * invoice that is paid, voided or unknown to it comes back as an empty
     * `data` list, reported as not-in-forecast, never as skipped.
     *
     * Nothing is sent but the two query parameters: no body, no write, no
     * settings change. The response is validated and redacted in
     * AutoprocessingForecast::fromResponse().
     */
    public function autoprocessingForecast(string $accountingInvoiceId, string $runDate): AutoprocessingForecast
    {
        $this->invoicePathSegment($accountingInvoiceId); // same id guard; throws invalid_id
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $runDate)
            || ! checkdate((int) substr($runDate, 5, 2), (int) substr($runDate, 8, 2), (int) substr($runDate, 0, 4))) {
            throw new BenjiPaysException('invalid_date');
        }

        $query = http_build_query(['startDate' => $runDate, 'invoiceId' => $accountingInvoiceId], '', '&', PHP_QUERY_RFC3986);
        $response = $this->send('GET', '/v2/autoprocessing-forecast?'.$query);

        return AutoprocessingForecast::fromResponse($response->body(), $accountingInvoiceId);
    }

    /**
     * READ-ONLY transactions for ONE accounting (QBO) customer, optionally one
     * invoice: GET /v2/transactions (scope organizations:transactions:read).
     *
     * Source: developer.benjipays.com/reference/get_v2-transactions (OpenAPI
     * 3.1, read 2026-10-01). customerId / invoiceId are accounting ids;
     * limit 1-1000 (callers cap far lower); newest first. Returns the raw
     * envelope for BenjiPaysReadProjection to validate and redact.
     *
     * @return array{data: list<mixed>, pagination: array<string, mixed>}
     */
    public function transactions(string $accountingCustomerId, ?string $accountingInvoiceId, int $limit): array
    {
        $query = ['customerId' => $accountingCustomerId];
        if ($accountingInvoiceId !== null) {
            $this->invoicePathSegment($accountingInvoiceId);
            $query['invoiceId'] = $accountingInvoiceId;
        }

        return $this->listRead('/v2/transactions', $accountingCustomerId, $query + [
            'sort' => 'transactionDate', 'order' => 'desc', 'limit' => $limit, 'offset' => 0,
        ]);
    }

    /**
     * READ-ONLY saved payment methods for ONE accounting (QBO) customer:
     * GET /v2/payment-methods?customerId= (scope organizations:payment-methods:read).
     *
     * Source: developer.benjipays.com/reference/get_v2-payment-methods (OpenAPI
     * 3.1, read 2026-10-01). Returns the raw envelope for
     * BenjiPaysReadProjection to validate and redact.
     *
     * @return array{data: list<mixed>, pagination: array<string, mixed>}
     */
    public function paymentMethods(string $accountingCustomerId, int $limit): array
    {
        return $this->listRead('/v2/payment-methods', $accountingCustomerId, [
            'customerId' => $accountingCustomerId, 'limit' => $limit, 'offset' => 0,
        ]);
    }

    /**
     * One GET of a documented list envelope {data: [...], pagination: {...}}.
     * Anything else is `invalid_response` (STANDARDS C-56: fail closed).
     *
     * @return array{data: list<mixed>, pagination: array<string, mixed>}
     */
    private function listRead(string $path, string $accountingCustomerId, array $query): array
    {
        $this->invoicePathSegment($accountingCustomerId); // same id guard; throws invalid_id
        if (! is_int($query['limit']) || $query['limit'] < 1 || $query['limit'] > 1000) {
            throw new \InvalidArgumentException('limit must be 1-1000 (the vendor bound).');
        }
        $response = $this->send('GET', $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $shape = json_decode($response->body());
        $body = json_decode($response->body(), true);
        if (! is_object($shape) || ! property_exists($shape, 'data') || ! is_array($shape->data)
            || ! property_exists($shape, 'pagination') || ! is_object($shape->pagination)
            || ! is_array($body) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])
            || ! is_array($body['pagination'] ?? null)) {
            throw new BenjiPaysException('invalid_response');
        }

        return ['data' => $body['data'], 'pagination' => $body['pagination']];
    }

    /** Same id guard for every invoice-addressed path; one rawurlencoded segment. */
    private function invoicePathSegment(string $accountingInvoiceId): string
    {
        if (trim($accountingInvoiceId) === '' || strlen($accountingInvoiceId) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $accountingInvoiceId)
            || in_array($accountingInvoiceId, ['.', '..'], true)) {
            throw new BenjiPaysException('invalid_id');
        }

        return rawurlencode($accountingInvoiceId);
    }

    /**
     * One bounded request. Returns only a 200 response; every other status is a
     * status-only BenjiPaysException with no vendor body, header or previous chain.
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): Response
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
            $pending = Http::baseUrl('https://api.benjipays.com')
                ->acceptJson()->asJson()
                ->withHeaders($headers + [
                    'x-api-key' => $key,
                    'X-Request-ID' => (string) Str::uuid(),
                    'X-Correlation-ID' => (string) Str::uuid(),
                ])
                ->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false]);
            $response = $method === 'POST' ? $pending->post($path, $body ?? []) : $pending->get($path);
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

        return $response;
    }

    private function get(string $path): mixed
    {
        $response = $this->send('GET', $path);

        $shape = json_decode($response->body());
        $body = $response->json();
        if (! is_object($shape) || ! property_exists($shape, 'data')
            || ($path === '/v2/gateways' ? ! is_array($shape->data) : ! is_object($shape->data))
            || ! is_array($body) || ! array_key_exists('data', $body)) {
            throw new BenjiPaysException('invalid_response');
        }

        return $body['data'];
    }
}
