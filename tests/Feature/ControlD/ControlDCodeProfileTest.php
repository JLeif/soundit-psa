<?php

namespace Tests\Feature\ControlD;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * eGIRKv51, option 3 (Jeeves 2026-10-10 09:41 PT): the provisioning code enforces the shared
 * baseline profile from `controld_code_profile_id`, never the Global Profile
 * (`controld_default_profile_id`; Control D refuses the Global Profile as a device profile inside
 * a sub-organisation, error 40003). Unset, malformed or equal to the Global Profile refuses AT
 * STAGING: no vendor call, no intent, no staged run. The organization step still sends the Global
 * Profile as parent_profile. Synthetic PKs only (G-9/G-13); Guzzle MockHandler for every vendor
 * exchange and Http::preventStrayRequests().
 */
class ControlDCodeProfileTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-key';

    private const ORG = 'testorg001';

    private const GLOBAL = '111111synthAA';

    private const CODE = '444444synthDD';

    private array $history = [];

    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', self::KEY);
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => self::GLOBAL, 'code_profile_id' => self::CODE, 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
        Setting::setValue('triage_system_user_id', (string) User::factory()->create(['name' => 'AI Actor'])->id);
        $this->bindVendor([]);
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function bindVendor(array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 4, new Response(503))]));
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => self::KEY, 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    private function listed(string $org = self::ORG): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = $org;
        $row['parent_profile']['PK'] = self::GLOBAL;

        return $row;
    }

    /** The code step's exchange after the step-ordering GET: types, own profiles (the code profile), POST, read-back. */
    private function codeExchange(string $org = self::ORG): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row = array_merge($row, ['org' => $org, 'max' => 3, 'ts_exp' => now()->getTimestamp() + 7 * 86400, 'profile_id' => self::CODE]);

        return [
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => self::CODE]]]),
            $this->ok(['provision' => $row]),
            $this->ok(['provisions' => [$row]]),
        ];
    }

    /** @return array{client: Client, ticket: Ticket, stager: User} */
    private function fixture(bool $mapped = true): array
    {
        $client = Client::factory()->create(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.test', 'controld_org_id' => $mapped ? self::ORG : null]);
        Asset::factory()->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return ['client' => $client, 'ticket' => $ticket, 'stager' => User::factory()->admin()->create(['is_active' => true])];
    }

    private function stageButton(array $fixture): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($fixture['stager'])->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client']);
    }

    private function approve(TechnicianRun $run): string
    {
        $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->post(route('cockpit.approve', $run));

        return (string) (session('error') ?? session('success'));
    }

    private function sentBody(string $path): array
    {
        $exchange = collect($this->history)->first(fn ($h) => $h['request']->getMethod() === 'POST' && $h['request']->getUri()->getPath() === $path);
        $this->assertNotNull($exchange, "a POST {$path} was sent");
        $raw = (string) $exchange['request']->getBody();
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        parse_str($raw, $form);

        return $form;
    }

    public function test_the_code_post_sends_the_code_profile_not_the_global_profile(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeExchange()]);

        $this->approve($run);

        $this->assertSame('bound', ControlDOnboardingIntent::where('operation', 'code')->sole()->state);
        $sent = $this->sentBody('/provision');
        $this->assertSame(self::CODE, $sent['profile_id']);
        $this->assertNotSame(ControlDConfig::defaultProfileId(), $sent['profile_id']);
    }

    public function test_the_org_step_still_sends_the_global_profile_as_parent_profile(): void
    {
        $fixture = $this->fixture(mapped: false);
        $fixture['client']->update(['email' => 'synthetic@example.invalid']);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $this->assertSame(['organization', 'code'], $run->proposed_meta['redacted_params']['steps']);
        $org = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
        $this->bindVendor([$this->ok(['organization' => $org]), $this->ok(['sub_organizations' => [$this->listed('syntheticOrg01')]]), ...$this->codeExchange('syntheticOrg01')]);

        $this->approve($run);

        $this->assertSame(['bound', 'bound'], ControlDOnboardingIntent::orderBy('created_at')->pluck('state')->all());
        $this->assertSame(self::GLOBAL, $this->sentBody('/organizations/suborg')['parent_profile']);
        $this->assertSame(self::CODE, $this->sentBody('/provision')['profile_id']);
    }

    public function test_the_plan_text_names_the_code_profile_and_the_inherited_global_profile(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();

        $this->assertStringContainsString('with these pinned values: enforced profile '.self::CODE.' (controld_code_profile_id); the global profile '.self::GLOBAL.' is inherited, expiry 7 days', $run->proposed_content);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedSettings(): array
    {
        return [
            'unset' => ['', 'controld_code_profile_id is required to onboard'],
            'whitespace only' => ['   ', 'controld_code_profile_id is required to onboard'],
            'malformed' => ['not a pk!', 'controld_code_profile_id is not a valid Control D profile id'],
            'equal to the global profile' => [self::GLOBAL, ControlDOnboardingStaged::CODE_PROFILE_IS_GLOBAL],
            'equal to the global profile after trimming' => ['  '.self::GLOBAL.' ', ControlDOnboardingStaged::CODE_PROFILE_IS_GLOBAL],
        ];
    }

    /** Refused at STAGE on both lanes' derivations (mapped: before the step-ordering GET; unmapped: organization + code plan). */
    #[DataProvider('refusedSettings')]
    public function test_staging_refuses_with_no_vendor_call_no_intent_and_no_run(string $value, string $expected): void
    {
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, $value);
        foreach ([true, false] as $mapped) {
            $fixture = $this->fixture($mapped);
            $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);

            $response = $this->stageButton($fixture);

            $response->assertSessionHasErrors('controld_onboarding');
            $error = session('errors')->first('controld_onboarding');
            $this->assertStringContainsString($expected, $error);
            $this->assertStringContainsString('Nothing was staged.', $error);
            $this->assertCount(0, $this->history, 'no vendor call at staging (mapped='.var_export($mapped, true).')');
            $this->assertSame(0, TechnicianRun::where('client_id', $fixture['client']->id)->count(), 'no staged run');
            $this->assertSame(0, ControlDOnboardingIntent::count(), 'no intent');
            $this->assertSame(1, TechnicianActionLog::where('client_id', $fixture['client']->id)->where('result_status', 'rejected')->count());
        }
    }

    public function test_the_global_profile_refusal_text_is_plain_and_names_the_setting(): void
    {
        $this->assertSame('controld_code_profile_id must be a shared main-organisation profile, not the global profile (Control D does not accept the global profile as a device profile inside a sub-organisation); nothing was written.', ControlDOnboardingStaged::CODE_PROFILE_IS_GLOBAL);
    }

    /** A card staged with a valid code profile refuses at approval when the setting later equals the global profile: no vendor call, re-stage. */
    public function test_approval_refuses_when_the_code_profile_became_the_global_profile(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, self::GLOBAL);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeExchange()]);

        $message = $this->approve($run);

        $this->assertSame(StaffControlDOnboardingToolExecutor::CODE_PINS_UNDERIVABLE, $message);
        $this->assertStringContainsString('controld_code_profile_id is unset, invalid or equal to the global profile', $message);
        $this->assertCount(0, $this->history, 'no vendor call');
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** Pin drift (unchanged ruling): a card pinned with the global profile as the code profile refuses with a re-stage. */
    public function test_a_card_pinned_with_the_global_profile_refuses_with_a_restage_and_nothing_written(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $meta = $run->proposed_meta;
        $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($meta['encrypted_payload']), true);
        $payload['code_pins']['profile_id'] = self::GLOBAL;
        $meta['encrypted_payload'] = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload));
        $run->forceFill(['proposed_meta' => $meta])->save();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]]), ...$this->codeExchange()]);

        $message = $this->approve($run);

        $this->assertStringContainsString('The code values pinned on this card', $message);
        $this->assertStringContainsString('stage again', $message);
        $this->assertSame(0, ControlDOnboardingIntent::count());
        foreach ($this->history as $exchange) {
            $this->assertSame('GET', $exchange['request']->getMethod(), 'no vendor write');
        }
    }

    #[DataProvider('refusedSettings')]
    public function test_code_pins_refuse_and_name_the_setting(string $value, string $expected): void
    {
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, $value);
        $client = $this->fixture()['client'];

        try {
            ControlDOnboardingStaged::codePins($client);
            $this->fail('codePins() must refuse');
        } catch (ControlDClientException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * The measured production shape (card eGIRKv51, 2026-10-10): a shared main-org profile is NOT
     * listed in the sub-organization's own GET profiles, and it is not the Global Profile. The
     * existing preflight then refuses before the POST: no write, the intent stays staged.
     */
    public function test_a_code_profile_not_listed_in_the_sub_org_refuses_at_preflight_before_the_post(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $this->bindVendor([
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => []]),
            $this->ok(['sub_organizations' => [$this->listed()]]),
        ]);

        $message = $this->approve($run);

        $this->assertStringContainsString('Control D onboarding was refused before any vendor write', $message);
        $this->assertStringContainsString('Enforced profile is neither this organization\'s global profile nor exactly one of its own profiles.', $message);
        foreach ($this->history as $exchange) {
            $this->assertSame('GET', $exchange['request']->getMethod(), 'no vendor write');
        }
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['code', 'staged', null], [$intent->operation, $intent->state, $intent->vendor_pk]);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** C-58: INSTALL describes the setting, what it must be, and the staging refusal. */
    public function test_install_describes_the_code_profile_setting(): void
    {
        $install = preg_replace('/\s+/', ' ', file_get_contents(base_path('docs/INSTALL.md')));
        $this->assertStringContainsString('It sends the setting **`controld_code_profile_id`** as the code\'s `profile_id`: the PK of a profile in the MAIN organization, toggled **Shared** in Control D, and **different from** `controld_default_profile_id`.', $install);
        $this->assertStringContainsString('staging any onboarding plan that includes the code step **refuses with nothing staged** (no Control D call, no intent, no run) and the message names `controld_code_profile_id`', $install);
    }

    public function test_code_profile_id_is_trimmed_and_blank_is_null(): void
    {
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, '  '.self::CODE.'  ');
        $this->assertSame(self::CODE, ControlDConfig::codeProfileId());
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, '  ');
        $this->assertNull(ControlDConfig::codeProfileId());
        $this->assertSame('controld_code_profile_id', ControlDConfig::CODE_PROFILE_SETTING);
        $this->assertNotSame(ControlDConfig::DEFAULT_PROFILE_SETTING, ControlDConfig::CODE_PROFILE_SETTING);
    }
}
