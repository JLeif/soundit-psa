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
        $this->assertFalse(app(ControlDOrganizationMapping::class)->autoMatch($new->id, 'org-reserved'));
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

    private function bound(Client $client): void
    {
        (new ControlDOnboardingIntent)->forceFill([
            'id' => (string) Str::uuid(), 'client_id' => $client->id, 'actor_id' => auth()->id(),
            'operation' => 'organization', 'state' => 'bound', 'phase' => 'local-persistence',
            'org_pk' => $client->controld_org_id, 'payload' => [],
        ])->save();
    }
}
