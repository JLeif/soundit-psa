<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PortalInstallLinkWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->travelTo(now()->startOfSecond());
        $this->actingAs(User::factory()->create());
        // A mapped RMM counts only while its integration is enabled and
        // configured (#3734). These tests exercise all three, so enable all three.
        Setting::setValue('ninja_enabled', '1');
        Setting::setValue('ninja_client_id', 'synthetic-ninja-id');
        Setting::setEncrypted('ninja_client_secret', 'synthetic-ninja-secret');
        Setting::setEncrypted('level_api_key', 'synthetic-level-key');
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-tactical-key');
    }

    public function test_generate_sets_token_ttl_and_each_single_rmm_primary(): void
    {
        foreach (['ninja_org_id' => 'ninja', 'level_group_id' => 'level', 'tactical_site_id' => 'tactical'] as $field => $rmm) {
            $client = Client::factory()->create([$field => '123']);
            $this->post(route('clients.install-link.generate', $client))
                ->assertRedirect(route('clients.show', $client))
                ->assertSessionHas('success', 'Install link generated.');
            $client->refresh();
            $this->assertSame(32, strlen($client->portal_install_token));
            $this->assertTrue(now()->addDays(30)->equalTo($client->portal_install_token_expires_at));
            $this->assertSame($rmm, $client->portal_primary_rmm);
        }
    }

    public function test_generate_preserves_multiple_rmm_primary_including_null(): void
    {
        foreach ([null, 'level'] as $primary) {
            $client = Client::factory()->create(['ninja_org_id' => '123', 'level_group_id' => '456', 'portal_primary_rmm' => $primary]);
            $this->post(route('clients.install-link.generate', $client))->assertSessionHas('success');
            $this->assertSame($primary, $client->fresh()->portal_primary_rmm);
        }
    }

    public function test_generate_refuses_existing_even_expired_token_before_no_mapping_guard(): void
    {
        foreach ([now()->addDay(), now()->subDay()] as $expiry) {
            $client = Client::factory()->create(['portal_install_token' => 'synthetic-existing-'.$expiry->timestamp, 'portal_install_token_expires_at' => $expiry]);
            $before = $client->fresh()->getAttributes();
            $this->post(route('clients.install-link.generate', $client))
                ->assertRedirect(route('clients.show', $client))
                ->assertSessionHas('error', 'This client already has an install link. Use Rotate to replace it.');
            $this->assertSame($before, $client->fresh()->getAttributes());
        }
    }

    public function test_generate_refuses_unmapped_client_without_writing(): void
    {
        $client = Client::factory()->create();
        $before = $client->fresh()->getAttributes();
        $this->post(route('clients.install-link.generate', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('error', 'Map this client to an RMM (Ninja, Level, or Tactical) before generating an install link.');
        $this->assertSame($before, $client->fresh()->getAttributes());
    }

    public function test_rotate_replaces_token_and_expiry_without_requiring_mapping_or_changing_primary(): void
    {
        $client = Client::factory()->create(['portal_install_token' => 'synthetic-old-link', 'portal_install_token_expires_at' => now()->subDay(), 'portal_primary_rmm' => 'level']);
        $this->post(route('clients.install-link.rotate', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', 'Install link rotated. The previous URL is no longer valid.');
        $client->refresh();
        $this->assertNotSame('synthetic-old-link', $client->portal_install_token);
        $this->assertSame(32, strlen($client->portal_install_token));
        $this->assertTrue(now()->addDays(30)->equalTo($client->portal_install_token_expires_at));
        $this->assertSame('level', $client->portal_primary_rmm);
    }

    public function test_rotate_refuses_missing_token_without_writing(): void
    {
        $client = Client::factory()->create(['portal_primary_rmm' => 'level']);
        $before = $client->fresh()->getAttributes();
        $this->post(route('clients.install-link.rotate', $client))
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('error', 'No install link to rotate.');
        $this->assertSame($before, $client->fresh()->getAttributes());
    }

    public function test_disable_clears_all_three_fields_and_is_repeatable(): void
    {
        $client = Client::factory()->create(['portal_install_token' => 'synthetic-old-link', 'portal_install_token_expires_at' => now()->addDay(), 'portal_primary_rmm' => 'level']);
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('clients.install-link.disable', $client))
                ->assertRedirect(route('clients.show', $client))
                ->assertSessionHas('success', 'Install link disabled.');
            $client->refresh();
            $this->assertNull($client->portal_install_token);
            $this->assertNull($client->portal_install_token_expires_at);
            $this->assertNull($client->portal_primary_rmm);
        }
    }
}
