<?php

namespace Tests\Feature\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\User;
use App\Services\ControlD\ControlDOrganizationMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ControlDOrganizationMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_controller_refuses_to_clear_a_bound_mapping(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $this->bound($client);
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => []])->assertUnprocessable();
        $this->assertSame('org-original', $client->fresh()->controld_org_id);
    }

    public function test_controller_refuses_to_replace_any_existing_mapping_atomically(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $new = Client::factory()->create();
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-new' => $new->id, 'org-replace' => $client->id]])->assertUnprocessable();
        $this->assertSame('org-original', $client->fresh()->controld_org_id);
        $this->assertNull($new->fresh()->controld_org_id);
    }

    public function test_unchanged_mapping_and_new_mapping_are_saved(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $this->bound($client);
        $new = Client::factory()->create();
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-original' => $client->id, 'org-new' => $new->id]])->assertRedirect();
        $this->assertSame('org-original', $client->fresh()->controld_org_id);
        $this->assertSame('org-new', $new->fresh()->controld_org_id);
    }

    public function test_deleted_owner_reserves_its_pk_for_manual_and_auto_mapping(): void
    {
        $deleted = Client::factory()->create(['controld_org_id' => 'org-reserved']);
        $deleted->delete();
        $new = Client::factory()->create();
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-reserved' => $new->id]])->assertUnprocessable();
        try {
            $matched = app(ControlDOrganizationMapping::class)->autoMatch($new->id, 'org-reserved');
        } catch (\Throwable $e) {
            $this->fail('autoMatch must decline a soft-deleted owner\'s pk by lookup, not by hitting the unique constraint: '.$e::class);
        }
        $this->assertFalse($matched);
        $this->assertNull($new->fresh()->controld_org_id);
        $this->assertTrue(app(ControlDOrganizationMapping::class)->autoMatch($new->id, 'org-free'));
        $this->assertSame('org-free', $new->fresh()->controld_org_id);
    }

    public function test_auto_mapping_does_not_overwrite_a_raced_mapping(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $this->assertFalse(app(ControlDOrganizationMapping::class)->autoMatch($client->id, 'org-replace'));
        $this->assertSame('org-original', $client->fresh()->controld_org_id);
    }

    public function test_bound_evidence_protects_a_previously_cleared_client(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $this->bound($client);
        $client->forceFill(['controld_org_id' => null])->save();
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-other' => $client->id]])->assertUnprocessable();
        $this->assertNull($client->fresh()->controld_org_id);
    }

    public function test_bound_org_is_never_handed_to_another_client_even_after_its_owner_was_cleared(): void
    {
        $owner = Client::factory()->create(['controld_org_id' => 'org-bound']);
        $this->bound($owner);
        $owner->forceFill(['controld_org_id' => null])->save();
        $other = Client::factory()->create();
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-bound' => $other->id]])->assertUnprocessable();
        $this->assertNull($other->fresh()->controld_org_id);
        // Restoring the bound owner to its own org is the one allowed direction.
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-bound' => $owner->id]])->assertRedirect();
        $this->assertSame('org-bound', $owner->fresh()->controld_org_id);
        // A deleted owner whose column was cleared still reserves its bound org.
        $owner = $owner->fresh();
        $owner->forceFill(['controld_org_id' => null])->save();
        $owner->delete();
        $this->assertNull(Client::withTrashed()->findOrFail($owner->id)->controld_org_id);
        $this->postJson(route('settings.controld-orgs.update'), ['mappings' => ['org-bound' => $other->id]])->assertUnprocessable();
        $this->assertNull($other->fresh()->controld_org_id);
    }

    public function test_refusal_is_rendered_on_the_mapping_page_not_silently_skipped(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'org-original']);
        $this->bound($client);
        $response = $this->from(route('settings.controld-orgs.index'))
            ->post(route('settings.controld-orgs.update'), ['mappings' => ['org-original' => '']]);
        $response->assertRedirect(route('settings.controld-orgs.index'))->assertSessionHasErrors('mappings');
        $this->assertSame('org-original', $client->fresh()->controld_org_id);
        $blade = (string) file_get_contents(resource_path('views/settings/controld-organizations.blade.php'));
        $this->assertStringContainsString('$errors->any()', $blade);
        $this->assertStringContainsString('Mappings not saved.', $blade);
    }

    public function test_client_page_unlink_refuses_a_bound_mapping_but_clears_a_manual_one(): void
    {
        $bound = Client::factory()->create(['controld_org_id' => 'org-bound']);
        $this->bound($bound);
        $manual = Client::factory()->create(['controld_org_id' => 'org-manual']);
        $this->post(route('clients.integrations.unlink', [$bound, 'controld']))->assertSessionHasErrors('mappings');
        $this->assertSame('org-bound', $bound->fresh()->controld_org_id);
        $this->post(route('clients.integrations.unlink', [$manual, 'controld']))->assertRedirect();
        $this->assertNull($manual->fresh()->controld_org_id);
    }

    public function test_client_page_link_refuses_to_repoint_a_bound_mapping_or_take_a_bound_or_deleted_org(): void
    {
        $bound = Client::factory()->create(['controld_org_id' => 'org-bound']);
        $this->bound($bound);
        $this->post(route('clients.integrations.link', [$bound, 'controld']), ['entity_id' => 'org-other'])->assertSessionHasErrors('mappings');
        $this->assertSame('org-bound', $bound->fresh()->controld_org_id);

        $cleared = Client::factory()->create(['controld_org_id' => 'org-cleared']);
        $this->bound($cleared);
        $cleared->forceFill(['controld_org_id' => null])->save();
        $other = Client::factory()->create();
        $this->post(route('clients.integrations.link', [$other, 'controld']), ['entity_id' => 'org-cleared'])->assertSessionHasErrors('mappings');
        $this->assertNull($other->fresh()->controld_org_id);

        $deleted = Client::factory()->create(['controld_org_id' => 'org-reserved']);
        $deleted->delete();
        $this->post(route('clients.integrations.link', [$other, 'controld']), ['entity_id' => 'org-reserved'])->assertSessionHasErrors('mappings');
        $this->assertNull($other->fresh()->controld_org_id);

        $this->post(route('clients.integrations.link', [$other, 'controld']), ['entity_id' => 'org-free'])->assertRedirect();
        $this->assertSame('org-free', $other->fresh()->controld_org_id);
    }

    private function bound(Client $client): void
    {
        (new ControlDOnboardingIntent)->forceFill([
            'id' => (string) Str::uuid(), 'client_id' => $client->id, 'actor_id' => auth()->id(),
            'operation' => 'organization', 'state' => 'bound', 'phase' => 'local-persistence',
            'org_pk' => $client->controld_org_id, 'payload' => [],
        ])->save();
    }
}
