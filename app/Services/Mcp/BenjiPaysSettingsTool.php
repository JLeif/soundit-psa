<?php

namespace App\Services\Mcp;

use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Services\BenjiPays\BenjiPaysReadProjection;

/**
 * benjipays_get_settings — READ-ONLY organization settings that explain
 * autopay, skip and surcharge behaviour, card 6abec4f9 stage 2.
 *
 * One GET /v2/settings through BenjiPaysClient (existing send(), no retries,
 * status-only errors). The settings belong to the merchant organization, not
 * to a client, so there is NO client fence and the tool takes no arguments.
 * BenjiPaysReadProjection::settings() keeps only non-secret flags, numbers and
 * enums and fails closed on any undocumented autopay or skip key.
 */
final class BenjiPaysSettingsTool
{
    public const NAME = 'benjipays_get_settings';

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Reads the BenjiPays settings of OUR merchant organization (not of any client, so it takes no client_id and has no client fence): auto-processing on/off, run hour, delay days, start date, and every skip rule (no-terms, due date not met, memo skip, surcharge skip, amount skip, invoice-prefix skip), plus the surcharge and autopay auto-enable flags, the portal autopay/save-card/partial-payment flags and the receipt flags. Use it to check that the invoice skip memo and surcharge settings match what the PSA expects: memo_skip.text_equals_psa_skip_memo compares BenjiPays\' memo-skip text with the PSA\'s QBO_NONRECURRING_SKIP_MEMO (null when either is unset). Never returns keys, URLs, email addresses, agreement texts, the memo text itself or the skipped prefixes (only their count). A 403 means the BenjiPays key lacks organizations:settings:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(): array
    {
        if ($refusal = BenjiPaysReadFence::notConfigured()) {
            return $refusal;
        }

        try {
            $settings = BenjiPaysReadProjection::settings($this->client->settings(), self::psaSkipMemo());
        } catch (BenjiPaysException $e) {
            return BenjiPaysReadFence::error($e, BenjiPaysReadFence::scopeMessage('settings', 'organizations:settings:read'), 'settings');
        }

        return $settings + [
            'source' => 'BenjiPays GET /v2/settings',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }

    /**
     * The PSA's configured skip memo, folded exactly as QboSyncService
     * (foldEscapedNewlines: a literal `\n` becomes a line break, then trim), or
     * null when none is configured.
     */
    private static function psaSkipMemo(): ?string
    {
        $memo = trim(str_replace('\n', "\n", (string) config('billing.qbo_nonrecurring_skip_memo', '')));

        return $memo === '' ? null : $memo;
    }
}
