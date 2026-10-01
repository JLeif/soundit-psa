<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Mcp\BenjiPaysSentEmailsTool;
use App\Services\Mcp\BenjiPaysSettingsTool;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only BenjiPays MCP tools of card 6abec4f9 stage 2:
 * benjipays_list_sent_emails and benjipays_get_settings.
 *
 * Every call goes through POST /api/mcp/staff with its middleware, the REAL
 * BenjiPaysClient, fence and projection; only the vendor's HTTP answer is
 * faked (Http::preventStrayRequests, no live call). Fixtures carry EVERY
 * property of the documented schema (developer.benjipays.com/reference:
 * get_v2-emails EmailSummary, get_v2-settings OrganizationSettingsResponse;
 * read 2026-10-02), with the sensitive ones set to SENTINEL values so each
 * redaction test proves none of them leaves the tool. Names, addresses and
 * domains are synthetic (example.com / example.net / example.org).
 */
class BenjiPaysEmailsSettingsToolsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-bp-read-key-never-real';

    private const CUST = 'QBO-CUST-77';

    private const EMAIL_SENTINELS = [
        'SENTINEL-EMAIL-ID', 'SENTINEL-SUBJECT', 'sentinel-from@example.org', 'sentinel-cc@example.org',
        'sentinel-bcc@example.org', 'SENTINEL-SENT-BY', 'SENTINEL-REMINDER-RULE', 'SENTINEL-INV-77',
        'jane.sentinel@example.com', 'ops-sentinel@example.net', 'ane.sentinel', 'subject', 'sentBy', 'reminderRule',
    ];

    private const SETTINGS_SENTINELS = [
        'https://sentinel-portal.example.org', 'sentinel-domain.example.org', 'SENTINEL-PORTAL-NAME', '#5e17eb',
        'SENTINEL-AGREEMENT', 'sentinel-cc@example.org', 'sentinel-bcc@example.org', 'sentinel-rcc@example.org',
        'sentinel-rbcc@example.org', 'SENTINEL-PREFIX', 'Paid by invoice - SENTINEL MEMO', 'enforceMfa', 'disableNonSsoLogin',
        'useSmtp', 'customDomain', 'preAuth', 'bcAutoPost',
    ];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('benjipays_api_key', self::KEY);
        $this->client = Client::factory()->create(['qbo_customer_id' => self::CUST]);
    }

    /** @return array<string, mixed> Every property of EmailSummary. */
    private static function email(array $overrides = []): array
    {
        return array_merge([
            'id' => 'SENTINEL-EMAIL-ID', 'type' => 'receipt', 'status' => 'sent', 'subject' => 'SENTINEL-SUBJECT',
            'from' => 'sentinel-from@example.org', 'to' => ['jane.sentinel@example.com', 'ops-sentinel@example.net'],
            'cc' => ['sentinel-cc@example.org'], 'bcc' => ['sentinel-bcc@example.org'],
            'sentDate' => '2026-09-29T17:04:11.000Z', 'opened' => true, 'lastOpened' => '2026-09-29T18:00:00.000Z',
            'bounced' => false, 'delivered' => true, 'customerId' => self::CUST, 'invoiceIds' => ['SENTINEL-INV-77'],
            'sentBy' => 'SENTINEL-SENT-BY', 'reminderRule' => ['displayName' => 'SENTINEL-REMINDER-RULE'], 'hasAttachments' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> Every property of OrganizationSettingsResponse.data. */
    private static function settings(array $autoOverrides = [], array $skipOverrides = []): array
    {
        $agreement = ['en' => 'SENTINEL-AGREEMENT', 'fr' => null];

        return [
            'autoProcessing' => array_merge([
                'enabled' => true, 'runHour' => 6, 'delayDays' => 2, 'startDate' => '2026-01-15',
                'processCreditMemos' => false, 'useParentProfiles' => true,
                'skips' => array_merge([
                    'noTermsDisabled' => true, 'skipDueDateNotMet' => false,
                    'memoSkip' => ['text' => 'Paid by invoice - SENTINEL MEMO', 'action' => 'skip'],
                    'skipSurcharge' => ['enabled' => true, 'min' => null, 'max' => 2.5],
                    'autoProcessAmountSkip' => ['enabled' => true, 'min' => 0, 'max' => 5000],
                    'invoicePrefixSkip' => ['enabled' => true, 'prefixes' => ['SENTINEL-PREFIX', 'CM-'], 'action' => 'skip'],
                ], $skipOverrides),
            ], $autoOverrides),
            'email' => [
                'sendReceipts' => true, 'attachInvoice' => true, 'sendInvoiceAttachments' => false,
                'disableCompanyEmailResults' => false, 'onlySendAttemptedBatchResults' => true, 'displayInvoiceEmails' => true,
                'selectInvoiceEmails' => false, 'autoPaySendReceiptsInvoiceEmails' => true,
                'invoiceCcEmails' => ['sentinel-cc@example.org'], 'invoiceBccEmails' => ['sentinel-bcc@example.org'],
                'receiptCcEmails' => ['sentinel-rcc@example.org'], 'receiptBccEmails' => ['sentinel-rbcc@example.org'], 'useSmtp' => false,
            ],
            'customerPortal' => [
                'enabled' => true, 'url' => 'https://sentinel-portal.example.org', 'name' => 'SENTINEL-PORTAL-NAME',
                'theme' => ['colour' => '#5e17eb'], 'accessDisabled' => false, 'forceSaveCards' => false, 'disableSaveCards' => false,
                'autoEnableProfiles' => true, 'allowPortalChangeAutoPay' => true, 'allowPortalManageEmails' => true,
                'allowPortalManageAddress' => false, 'allowPortalSchedulePayment' => true, 'allowAllProfileDeleteOnPortal' => false,
                'limitOpenInvoicesView' => false, 'showInvoicesAfterDate' => null, 'displayInvoiceAttachments' => true,
                'sendInvoiceAttachments' => false, 'disablePortalEmailLinks' => false, 'disablePaymentAmountInput' => false,
                'disablePartialPayments' => true, 'disableGenericPaymentLink' => false, 'payNowAmountRequired' => false,
                'customDomain' => 'sentinel-domain.example.org',
                'preAuthAgreements' => ['card' => $agreement, 'ach' => $agreement, 'eft' => $agreement, 'bacs' => $agreement, 'sepa' => $agreement],
            ],
            'accountingSystem' => [
                'emailAutoEnable' => true, 'autoEnableNewCustomers' => false, 'surchargeAutoEnable' => true, 'bcAutoPostJournals' => false,
            ],
            'security' => ['disableNonSsoLogin' => false, 'enforceMfa' => true],
        ];
    }

    /** @return array<string, mixed> */
    private static function page(array $items, bool $hasMore = false): array
    {
        return ['data' => $items, 'pagination' => [
            'total' => count($items) + ($hasMore ? 10 : 0), 'limit' => 25, 'offset' => 0,
            'hasMore' => $hasMore, 'nextOffset' => $hasMore ? 25 : null, 'prevOffset' => null,
        ]];
    }

    private function mcp(string $tool, array $arguments, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], ['Authorization' => 'Bearer '.($token ?? McpConfig::rotateStaffToken(allowedTools: [$tool], label: 'bp-reader'))]);
    }

    /** @return array<string, mixed> */
    private function answer(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->getContent());
        $decoded = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), (string) $response->getContent());

        return (string) $response->json('result.content.0.text');
    }

    private function assertOnlyGets(int $count): void
    {
        Http::assertSentCount($count);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->body() === '' && $r->hasHeader('x-api-key', self::KEY));
    }

    // ── benjipays_list_sent_emails ──────────────────────────────────────────

    public function test_emails_happy_path_is_one_get_filtered_to_the_mapped_customer(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.benjipays.com/v2/emails?customerId=QBO-CUST-77&sort=sentDate&order=desc&limit=25&offset=0', $request->url());

            return Http::response(self::page([
                self::email(),
                self::email(['type' => 'emailreminder', 'status' => 'error', 'to' => ['B@example.net'], 'sentDate' => null]),
            ], true));
        });

        $answer = $this->answer($this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertSame($this->client->id, $answer['client_id']);
        $this->assertSame([2, true, 12], [$answer['count'], $answer['has_more'], $answer['total']]);
        $this->assertSame([
            ['date' => '2026-09-29T17:04:11.000Z', 'type' => 'receipt', 'status' => 'sent',
                'recipients' => ['j***@example.com', 'o***@example.net'], 'recipient_count' => 2, 'recipients_truncated' => false],
            ['date' => null, 'type' => 'emailreminder', 'status' => 'error',
                'recipients' => ['B***@example.net'], 'recipient_count' => 1, 'recipients_truncated' => false],
        ], $answer['emails']);
        $this->assertOnlyGets(1);
    }

    public function test_emails_type_and_status_filters_are_passed_and_the_limit_is_capped(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([]))]);

        $answer = $this->answer($this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id, 'type' => 'receipt', 'status' => 'error', 'limit' => 1000]));

        $this->assertSame([], $answer['emails']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.benjipays.com/v2/emails?customerId=QBO-CUST-77&type=receipt&status=error&sort=sentDate&order=desc&limit=50&offset=0');
        $this->assertOnlyGets(1);
    }

    public function test_emails_redaction_strips_everything_but_date_type_status_and_masked_recipient(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::email()]))]);

        $response = $this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id]);
        $body = (string) $response->getContent();
        $answer = $this->answer($response);

        foreach (self::EMAIL_SENTINELS as $needle) {
            $this->assertStringNotContainsString($needle, $body, "sent-email output leaked {$needle}");
        }
        $this->assertSame(['date', 'type', 'status', 'recipients', 'recipient_count', 'recipients_truncated'], array_keys($answer['emails'][0]));
    }

    public function test_emails_recipients_past_the_cap_are_flagged_not_silently_dropped(): void
    {
        $to = array_map(fn (int $i) => "r{$i}@example.com", range(1, 13));
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::email(['to' => $to]), self::email(['to' => []])]))]);

        $rows = $this->answer($this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id]))['emails'];

        $this->assertCount(10, $rows[0]['recipients']);
        $this->assertSame([13, true], [$rows[0]['recipient_count'], $rows[0]['recipients_truncated']]);
        $this->assertSame([[], 0, false], [$rows[1]['recipients'], $rows[1]['recipient_count'], $rows[1]['recipients_truncated']]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badEmailRows(): array
    {
        return [
            'another customer' => [['customerId' => 'QBO-OTHER']],
            'no customer' => [['customerId' => null]],
            'undocumented type' => [['type' => 'marketing']],
            'undocumented status' => [['status' => 'delivered']],
            'display-name recipient' => [['to' => ['Jane Sentinel <jane.sentinel@example.com>']]],
            'recipient without a domain dot' => [['to' => ['jane@localhost']]],
            'recipient with two @' => [['to' => ['a@b@example.com']]],
            'recipient not a string' => [['to' => [['address' => 'jane.sentinel@example.com']]]],
            'to not a list' => [['to' => 'jane.sentinel@example.com']],
            'bad sentDate' => [['sentDate' => 'yesterday']],
        ];
    }

    #[DataProvider('badEmailRows')]
    public function test_emails_a_row_not_provably_this_clients_or_undocumented_fails_the_whole_read(array $overrides): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(self::page([self::email(), self::email($overrides)]))]);

        $response = $this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id]);
        $text = $this->refusal($response);

        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
        $this->assertStringNotContainsString('example.com', (string) $response->getContent());
    }

    public function test_emails_an_unmapped_client_is_refused_without_a_vendor_call(): void
    {
        $this->client->update(['qbo_customer_id' => null]);
        Http::fake();

        $text = $this->refusal($this->mcp(BenjiPaysSentEmailsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertStringContainsString('has no QuickBooks customer mapping', $text);
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badEmailArguments(): array
    {
        return [
            'nothing' => [[]],
            'unknown client' => [['client_id' => 999999]],
            'malformed client_id' => [['client_id' => 'abc']],
            'raw vendor customerId' => [['customerId' => self::CUST]],
            'raw vendor customer_id' => [['client_id' => 1, 'customer_id' => self::CUST]],
            'invoice argument (not declared)' => [['qbo_invoice_id' => '1042']],
            'undocumented type' => [['client_id' => 1, 'type' => 'marketing']],
            'undocumented status' => [['client_id' => 1, 'status' => 'bounced']],
            'limit zero' => [['client_id' => 1, 'limit' => 0]],
            'free-text search' => [['client_id' => 1, 'search' => 'jane']],
        ];
    }

    #[DataProvider('badEmailArguments')]
    public function test_emails_bad_or_raw_vendor_arguments_are_refused_without_a_vendor_call(array $arguments): void
    {
        Http::fake();
        if (($arguments['client_id'] ?? null) === 1) {
            $arguments['client_id'] = $this->client->id;
        }

        $this->refusal($this->mcp(BenjiPaysSentEmailsTool::NAME, $arguments));

        Http::assertNothingSent();
    }

    // ── benjipays_get_settings ───────────────────────────────────────────────

    public function test_settings_happy_path_reports_autopay_skip_and_surcharge_flags(): void
    {
        config(['billing.qbo_nonrecurring_skip_memo' => '  Paid by invoice - SENTINEL MEMO ']);
        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.benjipays.com/v2/settings', $request->url());

            return Http::response(['data' => self::settings()]);
        });

        $answer = $this->answer($this->mcp(BenjiPaysSettingsTool::NAME, []));

        $this->assertSame([
            'auto_processing' => ['enabled' => true, 'run_hour' => 6, 'delay_days' => 2, 'start_date' => '2026-01-15',
                'process_credit_memos' => false, 'use_parent_profiles' => true],
            'skips' => [
                'no_terms_disabled' => true, 'skip_due_date_not_met' => false,
                'memo_skip' => ['text_set' => true, 'text_equals_psa_skip_memo' => true, 'action' => 'skip'],
                'skip_surcharge' => ['enabled' => true, 'min' => null, 'max' => 2.5],
                'amount_skip' => ['enabled' => true, 'min' => 0, 'max' => 5000],
                'invoice_prefix_skip' => ['enabled' => true, 'prefix_count' => 2, 'action' => 'skip'],
            ],
            'accounting_system' => ['surcharge_auto_enable' => true, 'auto_enable_new_customers' => false, 'email_auto_enable' => true],
            'customer_portal' => ['enabled' => true, 'allow_portal_change_autopay' => true, 'auto_enable_profiles' => true,
                'force_save_cards' => false, 'disable_save_cards' => false, 'disable_partial_payments' => true],
            'email' => ['send_receipts' => true, 'autopay_send_receipts_invoice_emails' => true],
        ], array_diff_key($answer, ['source' => 1, 'read_at' => 1]));
        $this->assertOnlyGets(1);
    }

    /** @return array<string, array{0: ?string, 1: mixed, 2: ?bool, 3: bool}> psa memo, vendor memoSkip, equals, text_set */
    public static function memoComparisons(): array
    {
        return [
            'differs' => ['Another wording', ['text' => 'Paid by invoice - SENTINEL MEMO', 'action' => 'skip'], false, true],
            'psa memo unset' => [null, ['text' => 'Paid by invoice - SENTINEL MEMO', 'action' => 'skip'], null, true],
            'vendor text null' => ['Paid by invoice', ['text' => null, 'action' => null], null, false],
            'escaped newline folded like QboSyncService' => ['Line one\nLine two', ['text' => "Line one\nLine two", 'action' => 'skip'], true, true],
        ];
    }

    #[DataProvider('memoComparisons')]
    public function test_settings_memo_skip_is_compared_with_the_psa_wording_never_echoed(?string $psa, mixed $memoSkip, ?bool $equals, bool $set): void
    {
        config(['billing.qbo_nonrecurring_skip_memo' => $psa]);
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::settings([], ['memoSkip' => $memoSkip])])]);

        $response = $this->mcp(BenjiPaysSettingsTool::NAME, []);
        $memo = $this->answer($response)['skips']['memo_skip'];

        $this->assertSame([$set, $equals], [$memo['text_set'], $memo['text_equals_psa_skip_memo']]);
        $this->assertStringNotContainsString('SENTINEL MEMO', (string) $response->getContent());
        $this->assertStringNotContainsString('Line one', (string) $response->getContent());
    }

    public function test_settings_null_memo_skip_is_reported_as_null(): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::settings([], ['memoSkip' => null])])]);

        $this->assertNull($this->answer($this->mcp(BenjiPaysSettingsTool::NAME, []))['skips']['memo_skip']);
    }

    public function test_settings_redaction_strips_keys_urls_addresses_and_free_text(): void
    {
        config(['billing.qbo_nonrecurring_skip_memo' => 'Paid by invoice - SENTINEL MEMO']);
        Http::fake(['https://api.benjipays.com/*' => Http::response(['data' => self::settings()])]);

        $response = $this->mcp(BenjiPaysSettingsTool::NAME, []);
        $this->answer($response);
        $body = (string) $response->getContent();

        foreach ([...self::SETTINGS_SENTINELS, '@example', 'security', 'url'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "settings output leaked {$needle}");
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badSettings(): array
    {
        $s = self::settings();
        $undocumentedSkip = self::settings([], ['skipWeekends' => true]);
        $undocumentedAuto = self::settings(['retryDays' => 3]);
        $missingSkip = self::settings();
        unset($missingSkip['autoProcessing']['skips']['skipSurcharge']);
        $noSecurity = $s;
        unset($noSecurity['security']);

        return [
            'bare object (no data envelope)' => [$s],
            'data is a list' => [['data' => [$s]]],
            'undocumented skip rule' => [['data' => $undocumentedSkip]],
            'undocumented autoProcessing key' => [['data' => $undocumentedAuto]],
            'documented skip rule missing' => [['data' => $missingSkip]],
            'block missing' => [['data' => $noSecurity]],
            'enabled as a string' => [['data' => self::settings(['enabled' => 'true'])]],
            'runHour null' => [['data' => self::settings(['runHour' => null])]],
            'surcharge max as a string' => [['data' => self::settings([], ['skipSurcharge' => ['enabled' => true, 'min' => null, 'max' => '2.5']])]],
            'prefixes not a list' => [['data' => self::settings([], ['invoicePrefixSkip' => ['enabled' => true, 'prefixes' => 'CM-', 'action' => 'skip']])]],
            'memoSkip extra key' => [['data' => self::settings([], ['memoSkip' => ['text' => 'x', 'action' => 'skip', 'regex' => true]])]],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_settings_an_unknown_shape_fails_closed(array $payload): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response($payload)]);

        $response = $this->mcp(BenjiPaysSettingsTool::NAME, []);
        $text = $this->refusal($response);

        $this->assertStringContainsString('BenjiPays returned an unusable response', $text);
        $this->assertStringNotContainsString('SENTINEL', (string) $response->getContent());
    }

    public function test_settings_refuses_a_client_id_because_it_is_organization_wide(): void
    {
        Http::fake();

        $text = $this->refusal($this->mcp(BenjiPaysSettingsTool::NAME, ['client_id' => $this->client->id]));

        $this->assertStringContainsString('not a client', $text);
        Http::assertNothingSent();
    }

    public function test_settings_refuses_any_argument_without_a_vendor_call(): void
    {
        Http::fake();

        $this->refusal($this->mcp(BenjiPaysSettingsTool::NAME, ['customerId' => self::CUST]));

        Http::assertNothingSent();
    }

    public function test_settings_description_says_it_has_no_client_fence(): void
    {
        $this->assertStringContainsString('has no client fence', BenjiPaysSettingsTool::definition()['description']);
    }

    // ── both tools ────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: bool}> tool, scope, takes the fixture client */
    public static function tools(): array
    {
        return [
            'sent emails' => [BenjiPaysSentEmailsTool::NAME, 'organizations:emails:read', true],
            'settings' => [BenjiPaysSettingsTool::NAME, 'organizations:settings:read', false],
        ];
    }

    private function args(bool $client): array
    {
        return $client ? ['client_id' => $this->client->id] : [];
    }

    #[DataProvider('tools')]
    public function test_a_403_names_the_missing_scope(string $tool, string $scope, bool $client): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'https://api.benjipays.com/problems/forbidden', 'title' => 'Forbidden', 'status' => 403,
            'detail' => 'VENDOR-DETAIL-SENTINEL', 'instance' => '/v2/x',
        ], 403, ['Content-Type' => 'application/problem+json'])]);

        $response = $this->mcp($tool, $this->args($client));
        $text = $this->refusal($response);

        $this->assertStringContainsString('the BenjiPays API key lacks the '.$scope.' scope', $text);
        $this->assertStringContainsString('Ask Charlie', $text);
        $this->assertStringNotContainsString('VENDOR-', (string) $response->getContent());
        $this->assertOnlyGets(1);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: int}> */
    public static function toolsAndFailures(): array
    {
        $out = [];
        foreach (self::tools() as $label => $row) {
            foreach ([500, 502, 429, 404, 401] as $status) {
                $out[$label.' '.$status] = [...$row, $status];
            }
        }

        return $out;
    }

    #[DataProvider('toolsAndFailures')]
    public function test_a_vendor_failure_is_status_only(string $tool, string $scope, bool $client, int $status): void
    {
        Http::fake(['https://api.benjipays.com/*' => Http::response([
            'type' => 'x', 'title' => 'VENDOR-TITLE-SENTINEL', 'status' => $status, 'detail' => 'VENDOR-DETAIL-SENTINEL leaked@example.com',
        ], $status)]);

        $response = $this->mcp($tool, $this->args($client));
        $text = $this->refusal($response);

        $this->assertStringContainsString('(HTTP '.$status.')', $text);
        foreach (['VENDOR-', 'leaked@example.com', $scope] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent());
        }
        $this->assertOnlyGets(1);
    }

    #[DataProvider('tools')]
    public function test_no_stored_key_is_refused_without_a_vendor_call(string $tool, string $scope, bool $client): void
    {
        Setting::where('key', 'benjipays_api_key')->delete();
        Http::fake();

        $this->assertStringContainsString('not configured', $this->refusal($this->mcp($tool, $this->args($client))));
        Http::assertNothingSent();
    }

    #[DataProvider('tools')]
    public function test_each_tool_is_a_psa_read_that_needs_an_explicit_grant(string $tool, string $scope, bool $client): void
    {
        Http::fake();
        $this->assertContains($tool, array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));

        $ungranted = McpConfig::rotateStaffToken(allowedTools: ['psa_version'], label: 'other');
        foreach ([McpConfig::rotateStaffToken(), $ungranted] as $token) {
            $this->assertStringContainsString('not allowed for this token', $this->refusal($this->mcp($tool, $this->args($client), $token)));
        }
        Http::assertNothingSent();

        $matches = json_decode((string) $this->mcp('search_tools', ['query' => $tool], $ungranted)->json('result.content.0.text'), true)['matches'] ?? [];
        $this->assertSame('available_ungranted', collect($matches)->firstWhere('name', $tool)['grant_state'] ?? null, json_encode($matches));
    }
}
