<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * #3734: a mapped RMM counts as available only while its integration is
 * enabled and configured. The filter is read-time only: mapping columns
 * are never cleared, and re-enabling a configured integration restores it.
 */
class AvailableRmmsIntegrationGateTest extends TestCase
{
    use RefreshDatabase;

    private const MAPPING = ['ninja_org_id' => '901', 'level_group_id' => 'synthetic-group', 'tactical_site_id' => '77'];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->actingAs(User::factory()->create());
    }

    private function configureNinja(bool $enabled = true): void
    {
        Setting::setValue('ninja_enabled', $enabled ? '1' : '0');
        Setting::setValue('ninja_client_id', 'synthetic-ninja-id');
        Setting::setEncrypted('ninja_client_secret', 'synthetic-ninja-secret');
    }

    private function configureLevel(): void
    {
        Setting::setEncrypted('level_api_key', 'synthetic-level-key');
    }

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-tactical-key');
    }

    private function mappedClient(array $only, array $extra = []): Client
    {
        return Client::factory()->create(array_intersect_key(self::MAPPING, array_flip($only)) + $extra);
    }

    public function test_disabled_ninja_is_not_available_and_the_sole_remaining_rmm_resolves(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id']);

        $this->assertSame(['tactical'], $client->availableRmms());
        $this->assertSame('tactical', $client->effectiveInstallRmm());
    }

    public function test_ninja_enabled_but_unconfigured_is_not_available(): void
    {
        Setting::setValue('ninja_enabled', '1');
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id']);

        $this->assertSame(['tactical'], $client->availableRmms());
    }

    public function test_level_is_gated_on_enabled_and_on_configured(): void
    {
        $this->configureTactical();
        $client = $this->mappedClient(['level_group_id', 'tactical_site_id']);
        $this->assertSame(['tactical'], $client->availableRmms(), 'unconfigured Level must not count');

        $this->configureLevel();
        $this->assertSame(['level', 'tactical'], $client->availableRmms(), 'enabled and configured Level counts');

        Setting::setValue('level_enabled', '0');
        $this->assertSame(['tactical'], $client->availableRmms(), 'disabled Level must not count');
    }

    public function test_tactical_is_gated_on_configured_and_on_enabled(): void
    {
        $this->configureLevel();
        $client = $this->mappedClient(['level_group_id', 'tactical_site_id']);
        $this->assertSame(['level'], $client->availableRmms(), 'unconfigured Tactical must not count');

        $this->configureTactical();
        $this->assertSame(['level', 'tactical'], $client->availableRmms(), 'configured Tactical counts');

        Setting::setValue('tactical_enabled', '0');
        $this->assertSame(['level'], $client->availableRmms(), 'disabled Tactical must not count');
    }

    public function test_stored_primary_naming_a_disabled_rmm_falls_through_to_the_sole_remaining_rmm(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_primary_rmm' => 'ninja']);

        $this->assertSame('tactical', $client->effectiveInstallRmm());
        $this->assertSame('ninja', $client->fresh()->portal_primary_rmm, 'the stored value is read past, never rewritten');
    }

    public function test_stored_primary_naming_an_enabled_rmm_still_wins(): void
    {
        $this->configureNinja();
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_primary_rmm' => 'tactical']);

        $this->assertSame(['ninja', 'tactical'], $client->availableRmms());
        $this->assertSame('tactical', $client->effectiveInstallRmm());
    }

    public function test_primary_rmm_update_refuses_a_disabled_rmm_without_writing(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_primary_rmm' => 'tactical']);

        $this->patch(route('clients.portal-primary-rmm.update', $client), ['portal_primary_rmm' => 'ninja'])
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('error', 'That RMM is not mapped to this client.');
        $this->assertSame('tactical', $client->fresh()->portal_primary_rmm);

        $this->patch(route('clients.portal-primary-rmm.update', $client), ['portal_primary_rmm' => 'tactical'])
            ->assertSessionHas('success', 'Primary RMM updated.');
    }

    public function test_reenabling_ninja_restores_it_with_no_data_work(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id']);
        $before = $client->fresh()->getAttributes();

        $this->assertSame(['tactical'], $client->availableRmms());
        Setting::setValue('ninja_enabled', '1');
        $this->assertSame(['ninja', 'tactical'], $client->availableRmms());
        $this->assertNull($client->effectiveInstallRmm(), 'two available RMMs and no primary: operator must choose again');
        $this->assertSame($before, $client->fresh()->getAttributes(), 'no client column was written');
    }

    public function test_install_link_tool_resolves_a_client_whose_ninja_mapping_is_disabled(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id']);

        $result = app(\App\Services\Portal\PortalInstallService::class)->getOrCreateInstallLink($client);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(['tactical'], $result['available_rmms']);
        $this->assertSame('tactical', $result['effective_rmm']);
        $this->assertEquals(901, $client->fresh()->ninja_org_id, 'the Ninja mapping is kept');
        $this->assertNull($client->fresh()->portal_primary_rmm, 'no primary is recorded while the second mapping is only disabled');
    }

    public function test_generate_and_the_install_link_tool_keep_a_stored_primary_naming_a_disabled_rmm(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $viaWeb = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_primary_rmm' => 'ninja']);
        $viaTool = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_primary_rmm' => 'ninja']);

        $this->post(route('clients.install-link.generate', $viaWeb))
            ->assertSessionHas('success', 'Install link generated.');
        $result = app(\App\Services\Portal\PortalInstallService::class)->getOrCreateInstallLink($viaTool);
        $this->assertSame('tactical', $result['effective_rmm']);

        foreach ([$viaWeb, $viaTool] as $client) {
            $this->assertNotNull($client->fresh()->portal_install_token);
            $this->assertSame('ninja', $client->fresh()->portal_primary_rmm, 'the stored primary is kept');
            $this->assertSame('tactical', $client->fresh()->effectiveInstallRmm(), 'read past while Ninja is disabled');
        }

        Setting::setValue('ninja_enabled', '1');
        foreach ([$viaWeb, $viaTool] as $client) {
            $this->assertSame('ninja', $client->fresh()->effectiveInstallRmm(), 'the stored choice returns on re-enable');
        }
    }

    public function test_generate_still_records_the_sole_mapped_rmm_when_no_primary_is_stored(): void
    {
        $this->configureTactical();
        $client = $this->mappedClient(['tactical_site_id']);

        $this->post(route('clients.install-link.generate', $client))
            ->assertSessionHas('success', 'Install link generated.');

        $this->assertSame('tactical', $client->fresh()->portal_primary_rmm);
    }

    public function test_generate_never_overwrites_a_stored_primary_even_with_one_mapping(): void
    {
        $this->configureTactical();
        $client = $this->mappedClient(['tactical_site_id'], ['portal_primary_rmm' => 'level']);

        $this->post(route('clients.install-link.generate', $client))
            ->assertSessionHas('success', 'Install link generated.');

        $this->assertSame('level', $client->fresh()->portal_primary_rmm);
        $this->assertSame('tactical', $client->fresh()->effectiveInstallRmm(), 'a stored value naming an unavailable RMM is read past');
    }

    /** Jeeves 2026-09-25 (card LdzQqSmH): the mapping is history and no read or generate may clear it. */
    public function test_reads_and_web_generate_leave_a_disabled_ninja_mapping_unchanged(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id']);
        $mapping = fn () => $client->fresh()->only(['ninja_org_id', 'level_group_id', 'tactical_site_id']);
        $before = $mapping();

        $client->availableRmms();
        $client->effectiveInstallRmm();
        $this->post(route('clients.install-link.generate', $client))
            ->assertSessionHas('success', 'Install link generated.');

        $this->assertSame($before, $mapping());
        $this->assertEquals(901, $client->fresh()->ninja_org_id);
        // Two RMMs are mapped, so Generate records no primary even though only
        // one is enabled; resolution still reaches Tactical at read time.
        $this->assertNull($client->fresh()->portal_primary_rmm);
        $this->assertSame('tactical', $client->fresh()->effectiveInstallRmm());
        Setting::setValue('ninja_enabled', '1');
        $this->assertNull($client->fresh()->effectiveInstallRmm(), 'after re-enable the operator must choose again');
    }

    public function test_client_page_offers_no_primary_dropdown_when_only_one_rmm_is_enabled(): void
    {
        $this->configureNinja(enabled: false);
        $this->configureTactical();
        $client = $this->mappedClient(['ninja_org_id', 'tactical_site_id'], ['portal_install_token' => 'synthetic-token-0123456789abcdef', 'portal_install_token_expires_at' => now()->addDay()]);

        $this->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Self-Service Install Link')
            ->assertDontSee('name="portal_primary_rmm"', false);

        Setting::setValue('ninja_enabled', '1');
        $this->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('name="portal_primary_rmm"', false);
    }

    /** Negative control: a sole enabled RMM resolves the same with or without the gate. */
    public function test_a_sole_enabled_rmm_resolves_itself(): void
    {
        $this->configureTactical();
        $client = $this->mappedClient(['tactical_site_id']);

        $this->assertSame(['tactical'], $client->availableRmms());
        $this->assertSame('tactical', $client->effectiveInstallRmm());
    }
}
