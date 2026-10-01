<?php

namespace Tests\Feature\Assistant;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\PersonType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientUnifiSite;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KKaM3bWS item 1: get_client, get_asset and get_ticket_detail return a
 * linked_ids block — every id column, null meaning "not mapped" emitted
 * explicitly — and the unscoped cross-client ticket read keeps withholding.
 * All data synthetic.
 */
class LinkedIdsDetailToolsTest extends TestCase
{
    use RefreshDatabase;

    private function person(int $clientId, string $last): Person
    {
        return Person::create([
            'client_id' => $clientId,
            'person_type' => PersonType::User,
            'first_name' => 'Synth',
            'last_name' => $last,
            'email' => strtolower($last).'@example.test',
            'is_active' => true,
        ]);
    }

    public function test_get_client_returns_populated_integration_ids(): void
    {
        $client = Client::factory()->create();
        $client->forceFill([
            'huntress_organization_id' => 424242,
            'zorus_customer_id' => 'zor-synth-1',
            'tactical_site_id' => '77',
            'stripe_customer_id' => 'cus_SYNTH0001',
            'cipp_tenant_domain' => 'synth.onmicrosoft.example',
            'litsrmm_client_id' => 'lits-synth',
        ])->save();
        ClientUnifiSite::create(['client_id' => $client->id, 'unifi_site_id' => 'site-synth-a', 'unifi_host_id' => 'host:synth']);

        $ids = (new AssistantToolExecutor(clientId: $client->id))->execute('get_client', [])['linked_ids'];

        $this->assertSame(424242, $ids['huntress_organization_id']);
        $this->assertSame('zor-synth-1', $ids['zorus_customer_id']);
        $this->assertSame('77', $ids['tactical_site_id']);
        $this->assertSame('cus_SYNTH0001', $ids['stripe_customer_id']);
        $this->assertSame('synth.onmicrosoft.example', $ids['cipp_tenant_domain']);
        $this->assertSame('lits-synth', $ids['litsrmm_client_id']);
        $this->assertSame([['unifi_site_id' => 'site-synth-a', 'unifi_host_id' => 'host:synth']], $ids['unifi_sites']);
    }

    public function test_get_client_emits_unmapped_ids_as_explicit_null(): void
    {
        $client = Client::factory()->create();

        $ids = (new AssistantToolExecutor(clientId: $client->id))->execute('get_client', [])['linked_ids'];

        foreach (['huntress_organization_id', 'zorus_customer_id', 'controld_org_id', 'reseller_id', 'printix_tenant_id'] as $key) {
            $this->assertArrayHasKey($key, $ids, "{$key} must be present even when unmapped");
            $this->assertNull($ids[$key]);
        }
        $this->assertSame([], $ids['unifi_sites']);
    }

    public function test_get_client_never_serves_secrets(): void
    {
        $client = Client::factory()->create();
        $client->forceFill([
            'credentials' => 'SYNTH-CRED-SECRET',
            'comet_backup_password' => 'SYNTH-COMET-SECRET',
            'portal_install_token' => 'SYNTH-PORTAL-TOKEN',
            'controld_provisioning_code' => 'SYNTH-CTRLD-CODE',
            'controld_deactivation_pin' => 'SYNTH-CTRLD-PIN',
        ])->save();

        $json = json_encode((new AssistantToolExecutor(clientId: $client->id))->execute('get_client', []));

        foreach (['SYNTH-CRED-SECRET', 'SYNTH-COMET-SECRET', 'SYNTH-PORTAL-TOKEN', 'SYNTH-CTRLD-CODE', 'SYNTH-CTRLD-PIN'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_get_asset_returns_the_ids_the_old_read_hid(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'screenconnect_session_id' => 'sc-synth-0001',
            'zorus_endpoint_id' => 'zep-synth',
            'comet_device_id' => 'comet-synth',
            'm365_device_id' => 'm365-synth',
            'autoelevate_computer_id' => 'ae-synth',
            'servosity_backup_password' => 'SYNTH-SERVOSITY-SECRET',
        ]);
        $tactical = TacticalAsset::create(['asset_id' => $asset->id, 'agent_id' => 'agent-synth-xyz']);
        $asset->forceFill(['tactical_asset_id' => $tactical->id])->save();
        $user = $this->person($client->id, 'Assetuser');
        $asset->users()->attach($user->id);

        $out = (new AssistantToolExecutor(clientId: $client->id))->execute('get_asset', ['asset_id' => $asset->id]);
        $ids = $out['linked_ids'];

        $this->assertSame('sc-synth-0001', $ids['screenconnect_session_id']);
        $this->assertSame('zep-synth', $ids['zorus_endpoint_id']);
        $this->assertSame('comet-synth', $ids['comet_device_id']);
        $this->assertSame('m365-synth', $ids['m365_device_id']);
        $this->assertSame('ae-synth', $ids['autoelevate_computer_id']);
        $this->assertSame($client->id, $ids['client_id']);
        $this->assertSame('agent-synth-xyz', $ids['tactical_agent_id']);
        $this->assertSame([$user->id], $ids['user_person_ids']);
        $this->assertArrayHasKey('controld_device_id', $ids);
        $this->assertNull($ids['controld_device_id']);
        $this->assertArrayHasKey('merged_into_asset_id', $ids);
        $this->assertNull($ids['merged_into_asset_id']);
        $this->assertStringNotContainsString('SYNTH-SERVOSITY-SECRET', json_encode($out));
    }

    private function ticketWithRelations(Client $client): array
    {
        $contact = $this->person($client->id, 'Contactsynth');
        $assignee = User::factory()->create();
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Synth contract',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'assignee_id' => $assignee->id,
            'contract_id' => $contract->id,
            'halo_id' => 91001,
        ]);
        $a1 = Asset::factory()->create(['client_id' => $client->id]);
        $a2 = Asset::factory()->create(['client_id' => $client->id]);
        $ticket->assets()->attach($a1->id, ['is_primary' => false]);
        $ticket->assets()->attach($a2->id, ['is_primary' => true]);
        $call = PhoneCall::create(['call_uuid' => 'synth-call-1', 'from_number' => '+15550000001', 'ticket_id' => $ticket->id, 'client_id' => $client->id]);
        $email = Email::create(['from_address' => 'synth@example.test', 'subject' => 'synth', 'received_at' => now(), 'ticket_id' => $ticket->id, 'client_id' => $client->id]);
        $run = TechnicianRun::create(['ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'add_note', 'content_hash' => str_repeat('a', 64), 'state' => 'awaiting_approval']);

        return compact('ticket', 'contact', 'assignee', 'contract', 'a1', 'a2', 'call', 'email', 'run');
    }

    public function test_scoped_ticket_detail_lists_every_direct_relation(): void
    {
        $client = Client::factory()->create();
        $r = $this->ticketWithRelations($client);

        $ids = (new AssistantToolExecutor(clientId: $client->id))
            ->execute('get_ticket_detail', ['ticket_id' => $r['ticket']->id])['linked_ids'];

        $this->assertSame($client->id, $ids['client_id']);
        $this->assertSame($r['contact']->id, $ids['contact_id']);
        $this->assertSame($r['assignee']->id, $ids['assignee_id']);
        $this->assertSame($r['contract']->id, $ids['contract_id']);
        $this->assertSame(91001, $ids['halo_id']);
        $this->assertEqualsCanonicalizing([$r['a1']->id, $r['a2']->id], $ids['asset_ids']);
        $this->assertSame($r['a2']->id, $ids['primary_asset_id']);
        $this->assertSame([$r['call']->id], $ids['phone_call_ids']);
        $this->assertSame([$r['email']->id], $ids['email_ids']);
        $this->assertSame([$r['run']->id], $ids['staged_action_ids']);
        $this->assertSame([], $ids['child_ticket_ids']);
        // Unmapped scalars are present and null, not missing.
        foreach (['parent_ticket_id', 'halo_contract_id', 'category_id'] as $key) {
            $this->assertArrayHasKey($key, $ids);
            $this->assertNull($ids[$key]);
        }
        // Denylisted, never served.
        $this->assertArrayNotHasKey('hdb_press_id', $ids);
    }

    public function test_unscoped_ticket_detail_still_withholds_and_carries_no_linked_ids(): void
    {
        $client = Client::factory()->create();
        $r = $this->ticketWithRelations($client);

        $out = (new AssistantToolExecutor)->execute('get_ticket_detail', ['ticket_id' => $r['ticket']->id]);

        $this->assertSame($r['ticket']->id, $out['id']);
        $this->assertArrayNotHasKey('linked_ids', $out);
        foreach (['assets', 'related', 'client', 'contact'] as $withheld) {
            $this->assertArrayNotHasKey($withheld, $out);
        }
        $this->assertStringContainsString('linked_ids', $out['client_scoped_detail']);
        $this->assertStringNotContainsString($client->name, json_encode($out));
    }

    public function test_scoped_ticket_detail_does_not_name_a_foreign_child_ticket(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();
        $parent = Ticket::factory()->create(['client_id' => $mine->id]);
        $ownChild = Ticket::factory()->create(['client_id' => $mine->id, 'parent_ticket_id' => $parent->id]);
        $foreignChild = Ticket::factory()->create(['client_id' => $theirs->id, 'parent_ticket_id' => $parent->id]);

        $ids = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_detail', ['ticket_id' => $parent->id])['linked_ids'];

        $this->assertSame([$ownChild->id], $ids['child_ticket_ids']);
        $this->assertNotContains($foreignChild->id, $ids['child_ticket_ids']);
    }

    public function test_scoped_ticket_detail_does_not_name_a_foreign_parent_ticket(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();
        $parent = Ticket::factory()->create(['client_id' => $mine->id]);
        $ownChild = Ticket::factory()->create(['client_id' => $mine->id, 'parent_ticket_id' => $parent->id]);
        $foreignChild = Ticket::factory()->create(['client_id' => $theirs->id, 'parent_ticket_id' => $parent->id]);

        // Positive control: a same-client parent is named.
        $own = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_detail', ['ticket_id' => $ownChild->id])['linked_ids'];
        $this->assertSame($parent->id, $own['parent_ticket_id']);

        // The reverse of the foreign-child fixture: the other client's read
        // must not name client A's ticket, even as a bare id.
        $foreign = (new AssistantToolExecutor(clientId: $theirs->id))
            ->execute('get_ticket_detail', ['ticket_id' => $foreignChild->id])['linked_ids'];
        $this->assertArrayHasKey('parent_ticket_id', $foreign);
        $this->assertNull($foreign['parent_ticket_id']);
    }
}
