<?php

namespace App\Services\Mcp;

use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;

/**
 * benjipays_get_customer_payment_methods — READ-ONLY saved payment methods for
 * one PSA client, card 6abec4f9.
 *
 * One GET /v2/payment-methods?customerId= through BenjiPaysClient (existing
 * send(), no retries, status-only errors). Fenced by BenjiPaysReadFence and
 * redacted by BenjiPaysReadProjection to type, brand, last four, expiry and
 * autopay: never numbers, tokens, gateway ids, notes or IP addresses.
 */
final class BenjiPaysPaymentMethodsTool
{
    public const NAME = 'benjipays_get_customer_payment_methods';

    public const MAX_LIMIT = 25;

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Lists the payment methods BenjiPays has saved for ONE PSA client: method type (card/bank), card brand, last four digits, card expiry month/year, whether autopay is enabled on it, and its autopay_priority (BenjiPays\' processing order when several exist; BenjiPays reports no separate "default" flag). Give client_id (PSA client). The client must be mapped to its QuickBooks customer, or the call is refused. limit default 25, max 25. Never returns card or account numbers, tokens, gateway ids or billing addresses. A 403 means the BenjiPays key lacks organizations:payment-methods:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'PSA client id.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                ],
                'required' => ['client_id'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(array $input, ?int $clientId, bool $clientIdSupplied): array
    {
        if (! $clientIdSupplied) {
            return ['error' => 'client_id (PSA client id) is required.'];
        }
        $target = BenjiPaysReadFence::resolve($input, $clientId, $clientIdSupplied, false);
        if (isset($target['error'])) {
            return $target;
        }
        $limit = BenjiPaysReadFence::limit($input, self::MAX_LIMIT);
        if (is_array($limit)) {
            return $limit;
        }
        if ($refusal = BenjiPaysReadFence::notConfigured()) {
            return $refusal;
        }

        try {
            $page = BenjiPaysReadProjection::paymentMethods(
                $this->client->paymentMethods($target['customer_id'], $limit),
                $target['customer_id'],
            );
        } catch (BenjiPaysException $e) {
            return BenjiPaysReadFence::error($e, BenjiPaysReadFence::scopeMessage('payment-method', 'organizations:payment-methods:read'), 'payment-method');
        }

        return [
            'client_id' => $target['client_id'],
            'count' => count($page['rows']),
            'has_more' => $page['has_more'],
            'payment_methods' => $page['rows'],
            'source' => 'BenjiPays GET /v2/payment-methods',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }
}
