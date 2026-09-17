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
     * Planted in the IP-filter notice for the leak assertions.
     * Documentation-range values (RFC 5737 / RFC 2606): no real infrastructure
     * is named in this file.
     */
    private const LEAK_ADDRESS = '203.0.113.47';

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
     * The IP-filter refusal, in the shape observed 2026-09-17 against the real
     * service subaccount — a `notify--bad` block naming the account's IP Filter
     * whitelist, here carrying a documentation-range address, a host and a
     * contact so the leak assertions have something to catch. No live call: the
     * HTTP layer is faked.
     */
    private function fakeIpFilteredLogin(): void
    {
        Http::fake([
            self::LOGIN_URL => Http::sequence()
                ->push($this->loginPage())
                ->push('<html><body><!-- '.self::BEACON.' --><div class="notify notify--bad">'
                    .'Your IP address '.self::LEAK_ADDRESS.' is not on the account IP Filter whitelist. '
                    .'Contact '.self::LEAK_EMAIL.' or visit '.self::LEAK_HOST.' to add it.</div>'
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

        $serializedRow = json_encode($row->toArray());

        foreach ([$response->getContent(), $serializedRow] as $emitted) {
            $this->assertStringNotContainsString(self::BEACON, $emitted);
            $this->assertStringNotContainsString(self::LEAK_ADDRESS, $emitted);
            $this->assertStringNotContainsString(self::LEAK_HOST, $emitted);
            $this->assertStringNotContainsString(self::LEAK_EMAIL, $emitted);
            $this->assertStringNotContainsString('Your IP address', $emitted);
            $this->assertStringNotContainsString('IP Filter whitelist', $emitted);
        }

        // No address-SHAPED string on either vendor-derived surface. Scoped to
        // those fields deliberately: the row's `source_ip` is an address, but it
        // is the TESTER's own, written by the controller from the request and
        // never read off the portal's page — so it is asserted for what it is
        // rather than swept up by a whole-row regex that would have to be
        // loosened later.
        $this->assertDoesNotMatchRegularExpression('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', (string) $row->error_message);
        $this->assertDoesNotMatchRegularExpression('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', json_encode($row->arguments));
        $this->assertSame('127.0.0.1', $row->source_ip);

        // Nothing about this outcome is logged at all — so there is no log line
        // for the notice to leak into. Asserted rather than assumed.
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
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
