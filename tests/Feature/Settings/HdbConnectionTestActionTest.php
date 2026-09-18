<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Hdb\HdbAuthResult;
use App\Support\HdbPortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The Test Connection action behind the HDB Report Portal fieldset (psa #340).
 *
 * The action is deliberately not a copy of its siblings on this page, and the
 * two differences are what these tests pin:
 *
 * - testLevel() interpolates `$e->getMessage()` into the operator's message and
 *   the page renders that through `innerHTML`. This action returns only fixed
 *   strings, so a hostile or merely chatty portal cannot write into an admin's
 *   DOM.
 * - It is admin-only, because it is the one control here that spends a stored
 *   credential against a third party. That does NOT close psa #1344 — the rest
 *   of this page is still auth-only — and the coverage below says so rather than
 *   implying the page is gated.
 */
class HdbConnectionTestActionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://portal.example.test';

    private const LOGIN_URL = 'portal.example.test/login';

    private const BEACON = 'VENDOR-TEXT-<script>alert(1)</script>-BEACON';

    /**
     * Planted in the IP-filter notice for the leak assertions. Documentation
     * ranges only — RFC 5737 (v4), RFC 3849 (v6), RFC 2606 (names) — so no real
     * infrastructure is named in this file.
     */
    private const LEAK_ADDRESS = '203.0.113.47';

    private const LEAK_ADDRESS_V6 = '2001:db8::47';

    private const LEAK_HOST = 'psa.example.invalid';

    private const LEAK_EMAIL = 'support@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        // The destination guard resolves the host and fails closed on NXDOMAIN;
        // this example portal name resolves nowhere, so the resolution step gets
        // an injected public answer.
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn (string $host) => ['93.184.216.34']);

        Setting::setValue('hdb_base_url', self::BASE);
        Setting::setValue('hdb_email', 'reports@example.test');
        Setting::setEncrypted('hdb_password', 'service-account-password');
    }

    private function loginPage(): string
    {
        return '<html><body><!-- '.self::BEACON.' --><form action="" method="post" id="theOnlyForm">'
            .'<input type="email" name="email"><input type="password" name="password">'
            .'<input type="hidden" name="g" value="g"><input type="submit" name="submit"></form></body></html>';
    }

    private function fakeSuccessfulLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><h1>Reports</h1><!-- '.self::BEACON.' --></body></html>'),
        ]);
    }

    /**
     * The portal refusing the credentials, in the shape measured 2026-09-17: the
     * login page again PLUS its `notify--bad` notice. The bare login page with
     * no notice is a different outcome (`login_not_evaluated`), covered in
     * HdbAuthClientTest.
     */
    private function fakeRefusedLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><!-- '.self::BEACON.' --><div class="notify notify--bad">Invalid email or password. Try again.</div>'
                    .'<form action="" method="post" id="theOnlyForm">'
                    .'<input type="email" name="email"><input type="password" name="password">'
                    .'<input type="hidden" name="g" value="g"><input type="submit" name="submit"></form></body></html>'),
        ]);
    }

    public function test_a_non_admin_cannot_spend_the_stored_credential(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->tech()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, McpAuditLog::count());
    }

    public function test_a_guest_cannot_reach_the_action(): void
    {
        Http::fake();

        $this->post(route('settings.integrations.hdb.test'))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_the_test_button_is_not_offered_to_a_non_admin(): void
    {
        $this->actingAs(User::factory()->tech()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('HDB Report Portal')
            ->assertDontSee("testConnection('hdb')", false);
    }

    public function test_the_test_button_is_offered_to_an_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee("testConnection('hdb')", false)
            ->assertSee('id="test-result-hdb"', false);
    }

    public function test_a_successful_login_reports_success_and_stamps_connected_at(): void
    {
        $this->fakeSuccessfulLogin();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(Setting::getValue('hdb_connected_at'));
        $this->assertStringNotContainsString(self::BEACON, $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
    }

    public function test_a_refused_login_reports_failure_and_does_not_stamp_connected_at(): void
    {
        $this->fakeRefusedLogin();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertNull(Setting::getValue('hdb_connected_at'));
        $this->assertStringNotContainsString(self::BEACON, $response->getContent());
    }

    public function test_the_operator_message_is_a_fixed_string_from_the_closed_vocabulary(): void
    {
        $this->fakeRefusedLogin();

        $message = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->json('message');

        $this->assertSame(
            (new HdbAuthResult(
                \App\Services\Hdb\HdbAuthStatus::Rejected,
                HdbAuthResult::REASON_CREDENTIALS_REJECTED,
            ))->message(),
            $message,
        );
    }

    public function test_it_audits_who_tested_and_what_happened(): void
    {
        $this->fakeSuccessfulLogin();

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('staff', $row->server_name);
        $this->assertSame('success', $row->status);
        $this->assertSame('web:admin@example.test', $row->actor_label);
        $this->assertNull($row->error_message);
        $this->assertSame(self::BASE, $row->arguments['base_url']);
    }

    public function test_the_audit_row_records_the_reason_symbol_not_vendor_text(): void
    {
        // This row is read back in a UI too, so the same no-vendor-text rule
        // applies to it as to the flash message.
        $this->fakeRefusedLogin();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('error', $row->status);
        $this->assertSame(HdbAuthResult::REASON_CREDENTIALS_REJECTED, $row->error_message);
        $this->assertStringNotContainsString(self::BEACON, json_encode($row->toArray()));
    }

    public function test_an_unevaluated_post_reports_its_own_symbol_through_the_action_not_a_credential_verdict(): void
    {
        // The portal re-serving its bare login form (measured 2026-09-17 for
        // an empty submit field) is not a credential verdict, and neither the
        // audit row nor the operator sentence may say it is.
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push($this->loginPage()),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('error', $row->status);
        $this->assertSame(HdbAuthResult::REASON_LOGIN_NOT_EVALUATED, $row->error_message);
        $this->assertNull(Setting::getValue('hdb_connected_at'));
        $this->assertSame(
            (new HdbAuthResult(\App\Services\Hdb\HdbAuthStatus::Rejected, HdbAuthResult::REASON_LOGIN_NOT_EVALUATED))->message(),
            $response->json('message'),
        );
        $this->assertStringNotContainsString(self::BEACON, $response->getContent());
        $this->assertStringNotContainsString(
            (new HdbAuthResult(\App\Services\Hdb\HdbAuthStatus::Rejected, HdbAuthResult::REASON_CREDENTIALS_REJECTED))->message(),
            $response->getContent(),
        );
    }

    /**
     * The IP-filter refusal reaching this action. The BLOCK is the observed
     * shape (a `notify--bad` notice on the re-served login page); the SENTENCE
     * is deliberately a WIDER invention than the one observed — it carries two
     * address shapes, a host and a contact — so the leak assertions have
     * something to catch on every surface this action writes. The observed
     * sentence itself is pinned byte-for-byte in HdbAuthClientTest and is not
     * re-stated here. No live call: the HTTP layer is faked.
     */
    private function fakeIpFilteredLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><!-- '.self::BEACON.' --><div class="notify notify--bad">'
                    .'Your IP address '.self::LEAK_ADDRESS.' ('.self::LEAK_ADDRESS_V6.') is not on the '
                    .'account IP Filter whitelist. Contact '.self::LEAK_EMAIL.' or visit '
                    .self::LEAK_HOST.' to add it.</div>'
                    .'<form action="" method="post" id="theOnlyForm">'
                    .'<input type="email" name="email"><input type="password" name="password">'
                    .'<input type="hidden" name="g" value="g"><input type="submit" name="submit"></form></body></html>'),
        ]);
    }

    public function test_an_ip_filtered_refusal_audits_its_own_symbol_and_no_vendor_text(): void
    {
        // The new branch gets the same treatment as the other two notices: the
        // audit row carries the SYMBOL, the operator sentence is the fixed one
        // for that symbol, and the notice's sentence, address, host and contact
        // reach neither — nor the log, which is checked below.
        $this->fakeIpFilteredLogin();

        Log::spy();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        $row = McpAuditLog::where('method', 'hdb/test_connection')->sole();

        $this->assertSame('error', $row->status);
        $this->assertSame(HdbAuthResult::REASON_PORTAL_IP_FILTERED, $row->error_message);
        $this->assertNull(Setting::getValue('hdb_connected_at'));

        $this->assertSame(
            (new HdbAuthResult(
                \App\Services\Hdb\HdbAuthStatus::Rejected,
                HdbAuthResult::REASON_PORTAL_IP_FILTERED,
            ))->message(),
            $response->json('message'),
        );

        // Not the credentials sentence: a perimeter refusal never reads as a
        // verdict on the stored password.
        $this->assertStringNotContainsString(
            (new HdbAuthResult(
                \App\Services\Hdb\HdbAuthStatus::Rejected,
                HdbAuthResult::REASON_CREDENTIALS_REJECTED,
            ))->message(),
            $response->getContent(),
        );

        // The WHOLE row, minus the one column that legitimately holds an
        // address: `source_ip` is the TESTER's own, written by the controller
        // from the request and never read off the portal's page. Excluding it
        // by name (and asserting what it holds) keeps every OTHER column —
        // including any added later — inside the guard, rather than narrowing
        // the guard to the columns known today.
        $rowAttributes = $row->toArray();
        $this->assertSame('127.0.0.1', $rowAttributes['source_ip']);
        unset($rowAttributes['source_ip']);
        $serializedRow = json_encode($rowAttributes);

        foreach (['response' => $response->getContent(), 'audit row' => $serializedRow] as $what => $emitted) {
            $this->assertStringNotContainsString(self::BEACON, $emitted);
            $this->assertStringNotContainsString(self::LEAK_ADDRESS, $emitted);
            $this->assertStringNotContainsString(self::LEAK_ADDRESS_V6, $emitted);
            $this->assertStringNotContainsString(self::LEAK_HOST, $emitted);
            $this->assertStringNotContainsString(self::LEAK_EMAIL, $emitted);

            // The vendor sentence's distinctive parts, case-insensitively — not
            // "IP Filter whitelist", which our own fixed prose contains.
            $this->assertStringNotContainsStringIgnoringCase('Your IP address', $emitted);
            $this->assertStringNotContainsStringIgnoringCase('is not on the account', $emitted);
            $this->assertStringNotContainsStringIgnoringCase('to add it', $emitted);

            // Both address families, plus a bracketed host:port and a CIDR: a
            // guard that only knows IPv4 reports the contract as held for an
            // IPv6 caller.
            foreach ([
                '~\b\d{1,3}(?:\.\d{1,3}){3}\b~',
                '~\b\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}\b~',
                '~(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?|(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}~i',
            ] as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $emitted, "{$what} carries an address-shaped string.");
            }
        }

        // Nothing about this outcome is logged at ANY level, on the default
        // channel or a named one — so there is no log line for the notice to
        // leak into. Every level PSR-3 defines, plus the generic log() and the
        // channel/stack entry points, because a later Log::debug() or
        // Log::channel('integrations')->warning() on this path would otherwise
        // carry the notice, the address, the host and the contact into a file
        // while this test stayed green.
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $level) {
            Log::shouldNotHaveReceived($level);
        }

        Log::shouldNotHaveReceived('channel');
        Log::shouldNotHaveReceived('stack');
    }

    public function test_it_never_stores_the_password_or_seed_in_the_audit_row(): void
    {
        Setting::setEncrypted('hdb_totp_secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $this->fakeSuccessfulLogin();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk();

        $serialized = json_encode(McpAuditLog::where('method', 'hdb/test_connection')->sole()->toArray());

        $this->assertStringNotContainsString('service-account-password', $serialized);
        $this->assertStringNotContainsString('GEZDGNBVGY3TQOJQ', $serialized);
    }

    public function test_unconfigured_credentials_are_reported_without_touching_the_network(): void
    {
        Setting::setValue('hdb_email', '');
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.hdb.test'))
            ->assertOk()
            ->assertJson(['success' => false]);

        Http::assertNothingSent();
        $this->assertNull(Setting::getValue('hdb_connected_at'));
    }
}
