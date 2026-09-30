<?php

namespace App\Services\Mcp;

use App\Models\Invoice;
use App\Services\BenjiPays\BenjiPaysClient;
use App\Services\BenjiPays\BenjiPaysException;
use App\Support\BenjiPaysConfig;

/**
 * benjipays_autopay_forecast — READ-ONLY proof of whether BenjiPays will
 * auto-charge one QBO invoice on a run date, and why (card revwQxh4, Jeeves's
 * development ruling 2026-09-30 12:34 PT).
 *
 * One GET through BenjiPaysClient::autoprocessingForecast() (which uses the
 * existing send(), x-api-key from BenjiPaysConfig and its status-only
 * exceptions). No POST, nothing that changes BenjiPays settings. The answer is
 * the redacted AutoprocessingForecast: no customer, amount, currency or
 * payment-method fields. Errors carry the HTTP status at most, never a vendor
 * body.
 */
final class BenjiPaysForecastTool
{
    public const NAME = 'benjipays_autopay_forecast';

    public const SCOPE_MESSAGE = 'BenjiPays refused the forecast read (HTTP 403): the BenjiPays API key lacks the organizations:autoprocessing:read scope. Ask Charlie to add that scope to the key; minting or changing a key is his act. (BenjiPays also answers 403 when the key owner lacks the companyAdmin mapping or billing has lapsed.)';

    public function __construct(private readonly BenjiPaysClient $client) {}

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'READ-ONLY. Asks BenjiPays whether it will auto-charge ONE QuickBooks invoice on a run date, and why (its Auto Processing Forecast). Give exactly one of qbo_invoice_id (the QuickBooks invoice Id) or doc_number (the QuickBooks DocNumber / PSA invoice number, resolved to its QuickBooks Id through the PSA invoice). run_date is YYYY-MM-DD (UTC), default today (UTC). Returns status: "scheduled" (will be charged), "skipped" (will not be charged; reasons and the memo_skip/amount_skip/due_date_met flags say why, a payment-term skip shows only in reasons) or "not_in_forecast" (BenjiPays does not list it: it forecasts open invoices only, so paid, voided or unknown invoices land here, which is NOT a skip). Never returns customer, amount or payment-method details. A 403 means the BenjiPays key lacks organizations:autoprocessing:read. Requires an explicit token grant.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'qbo_invoice_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'doc_number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'run_date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                ],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(array $input): array
    {
        $qboId = $input['qbo_invoice_id'] ?? null;
        $docNumber = $input['doc_number'] ?? null;
        if (($qboId === null) === ($docNumber === null)) {
            return ['error' => 'Give exactly one of qbo_invoice_id or doc_number.'];
        }
        if (($qboId !== null && (! is_string($qboId) || trim($qboId) === ''))
            || ($docNumber !== null && (! is_string($docNumber) || trim($docNumber) === ''))) {
            return ['error' => 'qbo_invoice_id / doc_number must be a non-empty string.'];
        }
        $runDate = $input['run_date'] ?? now('UTC')->format('Y-m-d');
        if (! is_string($runDate)) {
            return ['error' => 'run_date must be a YYYY-MM-DD string.'];
        }

        if ($docNumber !== null) {
            $matches = Invoice::query()
                ->whereNotNull('qbo_invoice_id')->where('qbo_invoice_id', '!=', '')
                ->where(fn ($q) => $q->where('qbo_doc_number', trim($docNumber))->orWhere('invoice_number', trim($docNumber)))
                ->limit(2)->get(['id', 'qbo_invoice_id', 'qbo_doc_number', 'invoice_number']);
            if ($matches->count() !== 1) {
                return ['error' => $matches->isEmpty()
                    ? 'No PSA invoice with that DocNumber has a QuickBooks id. Pass qbo_invoice_id instead if the invoice exists only in QuickBooks.'
                    : 'More than one PSA invoice matches that DocNumber; pass qbo_invoice_id instead.'];
            }
            $qboId = (string) $matches->first()->qbo_invoice_id;
        }
        $qboId = trim((string) $qboId);

        if (! BenjiPaysConfig::isConfigured()) {
            return ['error' => 'BenjiPays is not configured (no API key stored), so no forecast can be read.'];
        }

        try {
            $forecast = $this->client->autoprocessingForecast($qboId, $runDate);
        } catch (BenjiPaysException $e) {
            return ['error' => match ($e->reason) {
                'forbidden' => self::SCOPE_MESSAGE,
                'http_error' => 'BenjiPays forecast read failed (HTTP '.($e->httpStatus ?? 'unknown').').',
                default => $e->getMessage().($e->httpStatus !== null ? ' (HTTP '.$e->httpStatus.')' : ''),
            }];
        }

        return [
            'qbo_invoice_id' => $qboId,
            'doc_number' => $forecast->found ? $forecast->invoiceNumber : $docNumber,
            'run_date' => $runDate,
            'status' => ! $forecast->found ? 'not_in_forecast' : ($forecast->willBeCharged ? 'scheduled' : 'skipped'),
            'will_be_charged' => $forecast->willBeCharged,
            'memo_skip' => $forecast->memoSkip,
            'amount_skip' => $forecast->amountSkip,
            'due_date' => $forecast->dueDate,
            'due_date_met' => $forecast->dueDateMet,
            'reasons' => $forecast->reasons,
            'source' => 'BenjiPays GET /v2/autoprocessing-forecast',
            'read_at' => now('UTC')->toIso8601String(),
        ];
    }
}
