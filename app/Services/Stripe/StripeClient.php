<?php

namespace App\Services\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class StripeClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.stripe.com',
            'timeout' => 30,
        ]);
    }

    // ── Core HTTP ──

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * POST, optionally under a Stripe idempotency key
     * (https://docs.stripe.com/api/idempotent_requests — the `Idempotency-Key`
     * header). A keyed request is safely replayable: Stripe returns the
     * original result instead of creating a second object, so request() may
     * retry an ambiguous transport failure. Unkeyed POSTs keep the old
     * behavior exactly — no replay (a blind retry of a create that actually
     * succeeded upstream would mint a duplicate).
     */
    public function post(string $endpoint, array $data = [], ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $endpoint, ['form_params' => $data], $idempotencyKey);
    }

    private function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    // ── Health Check ──

    public function isHealthy(): bool
    {
        try {
            $this->get('/v1/balance');

            return true;
        } catch (StripeClientException) {
            return false;
        }
    }

    // ── Customers ──

    public function listCustomers(int $limit = 100, ?string $startingAfter = null): array
    {
        $params = ['limit' => $limit];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        return $this->get('/v1/customers', $params);
    }

    /**
     * Fetch all customers with automatic pagination.
     */
    public function getAllCustomers(): array
    {
        $all = [];
        $startingAfter = null;

        for ($page = 0; $page < 100; $page++) {
            $response = $this->listCustomers(100, $startingAfter);
            $customers = $response['data'] ?? [];

            if (empty($customers)) {
                break;
            }

            $all = array_merge($all, $customers);

            if (! ($response['has_more'] ?? false)) {
                break;
            }

            $startingAfter = end($customers)['id'] ?? null;
            if (! $startingAfter) {
                break;
            }
        }

        return $all;
    }

    public function getCustomer(string $id): array
    {
        return $this->get("/v1/customers/{$id}");
    }

    // ── Invoices ──

    public function createInvoice(array $data, ?string $idempotencyKey = null): array
    {
        return $this->post('/v1/invoices', $data, $idempotencyKey);
    }

    public function createInvoiceItem(array $data): array
    {
        return $this->post('/v1/invoiceitems', $data);
    }

    /**
     * Delete a DRAFT invoice (https://docs.stripe.com/api/invoices/delete —
     * only drafts are deletable; finalized invoices must be voided instead).
     * A successful deletion responds with `"deleted": true` for the id.
     */
    public function deleteInvoice(string $id): array
    {
        return $this->delete("/v1/invoices/{$id}");
    }

    public function finalizeInvoice(string $id): array
    {
        return $this->post("/v1/invoices/{$id}/finalize");
    }

    public function voidInvoice(string $id): array
    {
        return $this->post("/v1/invoices/{$id}/void");
    }

    public function getInvoice(string $id): array
    {
        return $this->get("/v1/invoices/{$id}");
    }

    public function sendInvoice(string $id): array
    {
        return $this->post("/v1/invoices/{$id}/send");
    }

    public function listInvoices(int $limit = 100, ?string $startingAfter = null, array $extraParams = []): array
    {
        $params = array_merge(['limit' => $limit], $extraParams);

        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        return $this->get('/v1/invoices', $params);
    }

    /**
     * Fetch all line items for an invoice (handles >10 line pagination).
     */
    public function getAllInvoiceLines(string $invoiceId): array
    {
        $all = [];
        $startingAfter = null;

        for ($page = 0; $page < 50; $page++) {
            $params = ['limit' => 100];
            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $response = $this->get("/v1/invoices/{$invoiceId}/lines", $params);
            $lines = $response['data'] ?? [];

            if (empty($lines)) {
                break;
            }

            $all = array_merge($all, $lines);

            if (! ($response['has_more'] ?? false)) {
                break;
            }

            $startingAfter = end($lines)['id'] ?? null;
            if (! $startingAfter) {
                break;
            }
        }

        return $all;
    }

    // ── Products & Prices ──

    public function listProducts(int $limit = 100): array
    {
        return $this->get('/v1/products', ['limit' => $limit, 'active' => 'true']);
    }

    public function createProduct(array $data): array
    {
        return $this->post('/v1/products', $data);
    }

    public function updateProduct(string $id, array $data): array
    {
        return $this->post("/v1/products/{$id}", $data);
    }

    public function createPrice(array $data): array
    {
        return $this->post('/v1/prices', $data);
    }

    // ── Internal ──

    private function request(string $method, string $endpoint, array $options = [], ?string $idempotencyKey = null): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.($this->config['secret_key'] ?? ''),
            'Stripe-Version' => '2024-12-18.acacia',
        ];
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = $this->http->request($method, $endpoint, $options);
            } catch (GuzzleException $e) {
                $code = $e->getCode();

                // Rate limited — wait and retry
                if ($code === 429 && $attempts < $maxAttempts) {
                    $retryAfter = 1;
                    if (method_exists($e, 'getResponse') && $e->getResponse()) {
                        $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 1);
                    }
                    Log::info("[StripeClient] Rate limited, retrying in {$retryAfter}s");
                    sleep($retryAfter);

                    continue;
                }

                // An AMBIGUOUS failure — no response at all (code 0: the
                // request may or may not have reached Stripe) or a 5xx (Stripe
                // may have processed it before erroring) — is replayed ONLY
                // when this request carries an idempotency key, which makes
                // the replay return the original result instead of minting a
                // second object (https://docs.stripe.com/api/idempotent_requests).
                // Unkeyed requests keep the old fail-fast behavior: a blind
                // replay of a possibly-succeeded create would be a duplicate.
                if ($idempotencyKey !== null && $attempts < $maxAttempts
                    && ($code === 0 || ($code >= 500 && $code < 600))) {
                    Log::warning("[StripeClient] Ambiguous {$method} {$endpoint} failure (code {$code}); replaying under idempotency key");
                    sleep(1);

                    continue;
                }

                Log::error("[StripeClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new StripeClientException("Stripe API error: {$e->getMessage()}", $code, $e);
            }

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            // A well-formed Stripe response is a JSON object. An empty body is a
            // legitimate no-content result, but a JSON SCALAR (true/1/"ok") or
            // malformed body must NOT be coerced to [] — that reads downstream as
            // "no data" and, worse, a scalar would hit this method's `array` return
            // type as a TypeError that bypasses every StripeClientException catch,
            // leaving a locally-voided invoice with no recorded sync error
            // (psa-bl36l R2C). Convert every non-object shape to a StripeClientException
            // so callers record and surface it.
            if (is_array($decoded)) {
                return $decoded;
            }
            if ($body === '') {
                return [];
            }

            throw new StripeClientException('Stripe returned a non-object response body: '.substr($body, 0, 120));
        }

        throw new StripeClientException('Stripe request failed after max retries');
    }
}
