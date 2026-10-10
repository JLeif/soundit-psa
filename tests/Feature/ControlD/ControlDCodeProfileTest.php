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

        $this->assertStringContainsString('with these pinned values: enforced profile '.self::CODE.', the shared baseline profile (controld_code_profile_id); the global profile '.self::GLOBAL.' is inherited, expiry 7 days', $run->proposed_content);
        // diff:3 (G-14): arm (ii) accepts without the main-organization read, so the card states each arm and confines that check to arm (iii).
        $this->assertStringContainsString('Before writing, the step reads Control D and accepts the enforced profile only when it is listed exactly once among the organization\'s own profiles; or, when it is not listed there, it is the organization\'s global profile; or, only when neither holds, it is the shared baseline profile and the main organization lists it exactly once with sharing on (the main organization\'s profile list is read only for this last check). Otherwise it refuses and writes nothing.', $run->proposed_content);
        // c1:v1:1 (G-14): arm (i) reads the parent's sub_organizations, so only the main organization's profile list is confined to arm (iii).
        $this->assertStringNotContainsString('(the main organization is read only for this last check)', $run->proposed_content);
        $this->assertStringNotContainsString('for the shared baseline profile: listed exactly once, with sharing on, in the main organization', $run->proposed_content);
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

    // ── Arm (iii) (Jeeves, eGIRKv51 17:1xZ 2026-10-10): the configured shared code profile ──

    private const NEITHER = 'Enforced profile is neither this organization\'s global profile nor exactly one of its own profiles.';

    private const OTHER = '555555synthEE';

    /** A main-organization GET profiles row in the producer shape (body.profiles[].profile.opt.data[] {PK, value}). */
    private static function mainRow(string $pk, array $options = [['PK' => 'shared', 'value' => 1]]): array
    {
        return ['PK' => $pk, 'name' => 'Synthetic baseline', 'updated' => 1700000000, 'stats' => 0,
            'profile' => ['opt' => ['count' => count($options), 'data' => $options]]];
    }

    private function provisioning(array $responses): ControlDProvisioning
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 4, new Response(503))]));
        $stack->push(Middleware::history($this->history));

        return new ControlDProvisioning(new ControlDClient(['api_key' => self::KEY, 'handler' => $stack]));
    }

    private function fields(string $profile): array
    {
        return ['icon' => 'desktop-windows', 'profile_id' => $profile, 'max' => 3, 'ts_exp' => 0, 'stats' => 0, 'intercept_mode' => 'standard'];
    }

    /** types, the sub-org's own profiles (the code profile is NOT there), the parent inventory (global = GLOBAL), then the main-org GET profiles. */
    private function armThreeReads(Response $main): array
    {
        return [
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => '222222synthBB']]]),
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $main,
        ];
    }

    private function calls(): array
    {
        return array_map(fn ($h) => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(), $this->history);
    }

    public function test_arm_iii_accepts_the_configured_shared_code_profile_and_the_post_happens(): void
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row = array_merge($row, ['org' => self::ORG, 'max' => 3, 'ts_exp' => 0, 'profile_id' => self::CODE]);
        $service = $this->provisioning([
            ...$this->armThreeReads($this->ok(['profiles' => [self::mainRow('222222synthBB', []), self::mainRow(self::CODE)]])),
            $this->ok(['provision' => $row]), $this->ok(['provisions' => [$row]]),
        ]);

        $this->assertSame('fixture001', $service->create(self::ORG, $this->fields(self::CODE))['PK']);

        $this->assertSame(['GET /devices/types', 'GET /profiles', 'GET /organizations/sub_organizations', 'GET /profiles', 'POST /provision', 'GET /provision'], $this->calls());
        $this->assertSame([self::ORG], $this->history[1]['request']->getHeader('X-Force-Org-Id'), 'the own-profiles read is the sub-organization\'s');
        $this->assertFalse($this->history[3]['request']->hasHeader('X-Force-Org-Id'), 'arm (iii) reads the MAIN organization\'s profiles');
        $this->assertSame('', (string) $this->history[3]['request']->getBody(), 'a read-only GET with no body');
        $this->assertSame(self::CODE, json_decode((string) $this->history[4]['request']->getBody(), true)['profile_id']);
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function armThreeRefusals(): array
    {
        $not = self::NEITHER.' Arm (iii) refused: the configured code profile is not shared in the main organization.';
        $count = self::NEITHER.' Arm (iii) refused: the configured shared code profile is not listed exactly once in the main organization\'s profiles.';
        $bad = self::NEITHER.' Arm (iii) could not be confirmed: the main organization\'s profile inventory is unreadable or malformed.';
        $ok = fn (array $body) => new Response(200, [], json_encode(['success' => true, 'body' => $body]));

        return [
            'not shared: no options' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE, [])]]), $not],
            'not shared: value 0' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE, [['PK' => 'shared', 'value' => 0]])]]), $not],
            'not shared: another option on' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE, [['PK' => 'block_rfc1918', 'value' => 1]])]]), $not],
            'not shared: shared listed twice' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE, [['PK' => 'shared', 'value' => 1], ['PK' => 'shared', 'value' => 1]])]]), $not],
            'missing from the main organization' => [fn () => $ok(['profiles' => [self::mainRow(self::OTHER)]]), $count],
            'empty main-organization list' => [fn () => $ok(['profiles' => []]), $count],
            'listed twice' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE), self::mainRow(self::CODE)]]), $count],
            'malformed: profiles not a list' => [fn () => $ok(['profiles' => (object) []]), $bad],
            'malformed: profiles absent' => [fn () => $ok([]), $bad],
            'malformed: row without a PK' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE), ['name' => 'x']]]), $bad],
            'malformed: opt.data absent' => [fn () => $ok(['profiles' => [['PK' => self::CODE, 'profile' => ['opt' => ['count' => 0]]]]]), $bad],
            'malformed: option value not an integer' => [fn () => $ok(['profiles' => [self::mainRow(self::CODE, [['PK' => 'shared', 'value' => '1']])]]), $bad],
            'unreadable: 503' => [fn () => new Response(503), $bad],
            'unreadable: success false' => [fn () => new Response(200, [], json_encode(['success' => false, 'body' => ['profiles' => [self::mainRow(self::CODE)]]])), $bad],
        ];
    }

    #[DataProvider('armThreeRefusals')]
    public function test_arm_iii_refuses_with_no_post(\Closure $main, string $message): void
    {
        $service = $this->provisioning($this->armThreeReads($main()));

        try {
            $service->create(self::ORG, $this->fields(self::CODE));
            $this->fail('arm (iii) must refuse');
        } catch (ControlDClientException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(['GET /devices/types', 'GET /profiles', 'GET /organizations/sub_organizations', 'GET /profiles'], $this->calls(), 'no write');
        $this->assertFalse($this->history[3]['request']->hasHeader('X-Force-Org-Id'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function armThreeNotApplicable(): array
    {
        $notConfigured = self::NEITHER.' Arm (iii) does not apply: it is not the configured shared code profile (controld_code_profile_id).';

        return [
            // The main organization lists the profile once, shared: only the equality check refuses.
            'not the configured code profile' => [self::CODE, self::OTHER, $notConfigured],
            'code profile setting unset' => ['', self::OTHER, $notConfigured],
            // The configured code profile IS the global PK (not the sub-org's parent_profile here,
            // so arm (i) does not take it) and is shared once in the main organization.
            'the global PK' => [self::GLOBAL, self::GLOBAL, self::NEITHER.' Arm (iii) does not apply: the configured code profile is the global profile.'],
        ];
    }

    #[DataProvider('armThreeNotApplicable')]
    public function test_arm_iii_refuses_a_profile_it_does_not_cover_before_the_main_org_read(string $configured, string $sent, string $message): void
    {
        Setting::setValue(ControlDConfig::CODE_PROFILE_SETTING, $configured);
        $parent = $this->listed();
        $parent['parent_profile']['PK'] = '333333synthCC';
        $service = $this->provisioning([
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => '222222synthBB']]]),
            $this->ok(['sub_organizations' => [$parent]]),
            $this->ok(['profiles' => [self::mainRow($sent)]]),
        ]);

        try {
            $service->create(self::ORG, $this->fields($sent));
            $this->fail('arm (iii) must refuse');
        } catch (ControlDClientException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertNotContains('POST /provision', $this->calls(), 'no write');
    }

    /** The new parent read is exactly GET profiles with no body; nothing else on that path is allowed. */
    public function test_the_main_org_profiles_read_is_get_only_without_a_body(): void
    {
        $transport = new ControlDClient(['api_key' => self::KEY, 'handler' => HandlerStack::create(new MockHandler([new Response(503)]))]);
        foreach ([['POST', 'profiles', []], ['GET', 'profiles', []], ['PUT', 'profiles', null], ['DELETE', 'profiles', null], ['GET', 'profiles/'.self::CODE, null]] as [$method, $path, $body]) {
            try {
                $transport->requestParent($method, $path, $body);
                $this->fail("{$method} {$path} must refuse");
            } catch (ControlDClientException $e) {
                $this->assertSame('Control D parent request is invalid or unconfigured.', $e->getMessage());
            }
        }
    }

    /** Arms (i) and (ii) never consult the main organization: no fourth read. */
    public function test_arms_i_and_ii_make_no_main_org_read(): void
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        foreach ([[self::CODE, [['PK' => self::CODE]], false], [self::GLOBAL, [['PK' => '222222synthBB']], true]] as [$profile, $own, $parentRead]) {
            $row = array_merge($row, ['org' => self::ORG, 'max' => 3, 'ts_exp' => 0, 'profile_id' => $profile]);
            $service = $this->provisioning([
                $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
                $this->ok(['profiles' => $own]),
                ...($parentRead ? [$this->ok(['sub_organizations' => [$this->listed()]])] : []),
                $this->ok(['provision' => $row]), $this->ok(['provisions' => [$row]]),
            ]);
            $this->assertSame('fixture001', $service->create(self::ORG, $this->fields($profile))['PK']);
            $this->assertSame([...['GET /devices/types', 'GET /profiles'], ...($parentRead ? ['GET /organizations/sub_organizations'] : []), 'POST /provision', 'GET /provision'], $this->calls());
        }
    }

    /** End to end through approval: the measured production shape (shared baseline not in the sub-org's list, not the global) now reaches the POST. */
    public function test_approval_cuts_the_code_with_the_shared_baseline_profile_via_arm_iii(): void
    {
        $fixture = $this->fixture();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->stageButton($fixture)->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $exchange = $this->codeExchange();
        $this->bindVendor([
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $exchange[0],
            $this->ok(['profiles' => []]),
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $this->ok(['profiles' => [self::mainRow(self::CODE)]]),
            $exchange[2], $exchange[3],
        ]);

        $this->approve($run);

        $this->assertSame('bound', ControlDOnboardingIntent::where('operation', 'code')->sole()->state);
        $this->assertSame(self::CODE, $this->sentBody('/provision')['profile_id']);
        $mainRead = collect($this->history)->filter(fn ($h) => $h['request']->getUri()->getPath() === '/profiles')->values();
        $this->assertCount(2, $mainRead);
        $this->assertFalse($mainRead[1]['request']->hasHeader('X-Force-Org-Id'));
    }

    /** The approval path refuses before any write when arm (iii) refuses: the intent stays staged. */
    public function test_approval_refuses_before_the_post_when_the_baseline_is_not_shared(): void
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
            $this->ok(['profiles' => [self::mainRow(self::CODE, [])]]),
        ]);

        $message = $this->approve($run);

        $this->assertStringContainsString('Control D onboarding was refused before any vendor write', $message);
        $this->assertStringContainsString(self::NEITHER.' Arm (iii) refused: the configured code profile is not shared in the main organization.', $message);
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
        $this->assertStringContainsString('When neither holds, a third arm (iii) accepts the profile only when it equals `controld_code_profile_id` (and differs from `controld_default_profile_id`) and one read-only GET `/profiles` of the MAIN organization (no `X-Force-Org-Id`) lists it exactly once with its `shared` option on;', $install);
        $this->assertStringNotContainsString('checks the profile only against the sub-organization\'s own profiles and its Global Profile', $install);
        // diff:3, c1:v1:1 (G-14): the main-organization shared check is stated as arm (iii) only; arms (i)/(ii) make no GET /profiles of the main organization (arm (i) does read the parent's sub_organizations).
        $this->assertStringNotContainsString('no main-organization read is made', $install);
        $this->assertStringContainsString('Arm (iii) is consulted only when arms (i) and (ii) both miss: if arm (i) or (ii) accepts the code profile (for example, a sub-organization\'s own profiles list it exactly once), no GET `/profiles` of the main organization is made (arm (i) does read the parent\'s `sub_organizations` inventory), so neither its listing in the main organization nor its sharing is checked on that path. If arm (iii) is consulted and refuses, the step refuses before the POST with nothing written at Control D;', $install);
        $this->assertStringNotContainsString('so the code preflight accepts it through arm (iii) above: it must be', $install);
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
