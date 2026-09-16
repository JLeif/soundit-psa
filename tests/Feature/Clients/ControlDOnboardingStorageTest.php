<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ControlDOnboardingStorageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = [
        'controld_provisioning_code' => 'synthetic-code-only',
        'controld_deactivation_pin' => '001204',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_columns_are_nullable_and_existing_client_mapping_is_preserved(): void
    {
        $this->assertTrue(Schema::hasColumns('clients', array_keys(self::SECRETS)));
        $client = Client::factory()->create(['controld_org_id' => 'synthetic-org']);
        foreach (self::SECRETS as $field => $value) {
            $this->assertNull($client->fresh()->getAttribute($field));
            $this->assertNull(DB::table('clients')->where('id', $client->id)->value($field));
        }
        $this->assertSame('synthetic-org', $client->fresh()->controld_org_id);
    }

    public function test_both_secrets_round_trip_encrypted_and_client_isolated(): void
    {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        foreach (self::SECRETS as $field => $value) {
            $client->setAttribute($field, $value);
        }
        $client->save();
        foreach (self::SECRETS as $field => $value) {
            $ciphertext = DB::table('clients')->where('id', $client->id)->value($field);
            $this->assertNotSame($value, $ciphertext);
            $this->assertStringNotContainsString($value, $ciphertext);
            $this->assertSame($value, Crypt::decryptString($ciphertext));
            $this->assertSame($value, $client->fresh()->getAttribute($field));
            $this->assertNull($other->fresh()->getAttribute($field));
        }
    }

    public function test_secrets_are_absent_from_model_array_and_json(): void
    {
        $client = Client::factory()->create(['name' => 'Synthetic storage control']);
        foreach (self::SECRETS as $field => $value) {
            $client->setAttribute($field, $value);
        }
        $client->save();
        $client = $client->fresh();
        $array = $client->toArray();
        $json = $client->toJson();
        $this->assertSame('Synthetic storage control', $array['name']);
        foreach (self::SECRETS as $field => $value) {
            $this->assertArrayNotHasKey($field, $array);
            $this->assertStringNotContainsString($field, $json);
            $this->assertStringNotContainsString($value, $json);
        }
    }

    public function test_generic_mass_assignment_cannot_set_or_clobber_secrets(): void
    {
        $client = Client::factory()->create();
        foreach (self::SECRETS as $field => $value) {
            $this->assertFalse($client->isFillable($field));
            $client->setAttribute($field, $value);
        }
        $client->save();
        $client->fill(array_fill_keys(array_keys(self::SECRETS), 'attempted-replacement'));
        $client->name = 'Ordinary edit';
        $client->save();
        $this->assertSame('Ordinary edit', $client->fresh()->name);
        foreach (self::SECRETS as $field => $value) {
            $this->assertSame($value, $client->fresh()->getAttribute($field));
        }
    }

    public function test_explicit_internal_clear_preserves_other_secret_and_mapping(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'synthetic-org']);
        foreach (self::SECRETS as $field => $value) {
            $client->setAttribute($field, $value);
        }
        $client->save();
        foreach (self::SECRETS as $field => $value) {
            $client->setAttribute($field, null);
            $client->save();
            $this->assertNull(DB::table('clients')->where('id', $client->id)->value($field));
            $this->assertNull($client->fresh()->getAttribute($field));
            $this->assertSame('synthetic-org', $client->fresh()->controld_org_id);
            if ($field === 'controld_provisioning_code') {
                $this->assertSame('001204', $client->fresh()->controld_deactivation_pin);
            }
        }
    }

    public function test_plaintext_or_corrupt_ciphertext_is_not_accepted_as_a_secret(): void
    {
        $client = Client::factory()->create();
        foreach (self::SECRETS as $field => $value) {
            DB::table('clients')->where('id', $client->id)->update([$field => $value]);
            try {
                $client->fresh()->getAttribute($field);
                $this->fail('Invalid ciphertext must fail decryption');
            } catch (DecryptException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_migration_round_trip_preserves_unrelated_client_data(): void
    {
        $client = Client::factory()->create(['controld_org_id' => 'synthetic-org']);
        $migration = require database_path('migrations/2026_09_16_100000_add_controld_onboarding_secrets_to_clients.php');
        $migration->down();
        foreach (array_keys(self::SECRETS) as $field) {
            $this->assertFalse(Schema::hasColumn('clients', $field));
        }
        $this->assertSame('synthetic-org', $client->fresh()->controld_org_id);
        $migration->up();
        $this->assertTrue(Schema::hasColumns('clients', array_keys(self::SECRETS)));
        foreach (array_keys(self::SECRETS) as $field) {
            $this->assertNull($client->fresh()->getAttribute($field));
        }
    }
}
