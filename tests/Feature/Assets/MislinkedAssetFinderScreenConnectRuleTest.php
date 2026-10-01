<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ScreenConnectWebhook;
use App\Services\Assets\MislinkedAssetFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Card 6abe578e — MislinkedAssetFinder rule 7 (screenconnect_company_contradiction).
 * The newest stored webhook's company for an asset's session id, resolved through the
 * ingest's own client-name rule, contradicting the asset's client_id is a Tier A
 * finding. An unresolved or ambiguous company is no finding; the single-agreeing case
 * stays silent. Synthetic names only.
 */
class MislinkedAssetFinderScreenConnectRuleTest extends TestCase
{
    use RefreshDatabase;

    private function finder(): MislinkedAssetFinder
    {
        return app(MislinkedAssetFinder::class);
    }

    private function asset(Client $client, string $session, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'hostname' => 'Test-MBP-'.substr($session, 0, 4),
            'serial_number' => null,
            'ip_address' => null,
            'last_user' => null,
            'screenconnect_session_id' => $session,
        ], $overrides));
    }

    /** Store a webhook row the way ScreenConnectWebhookController does (native payload). */
    private function webhook(string $session, ?string $company, string $flatOrNative = 'native'): ScreenConnectWebhook
    {
        $payload = $flatOrNative === 'native'
            ? ['Session' => ['SessionID' => $session, 'SessionType' => 'Access', 'CustomProperty1' => $company], 'Event' => ['EventType' => 'Connected']]
            : ['event_type' => 'Connected', 'session_id' => $session, 'session_type' => 'Access', 'company' => $company];

        return ScreenConnectWebhook::create(['event_type' => 'Connected', 'session_id' => $session, 'payload' => $payload, 'status' => 'processed']);
    }

    private function scRows(array $result): array
    {
        return array_values(array_filter($result['tier_a'], fn ($r) => $r['rule'] === 'screenconnect_company_contradiction'));
    }

    public function test_a_newest_webhook_company_naming_another_client_is_a_tier_a_finding(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $session = 'aaaa0000-0000-0000-0000-000000000001';
        $asset = $this->asset($bravo, $session);
        $this->webhook($session, 'alpha co');

        $rows = $this->scRows($this->finder()->find(null));

        $this->assertCount(1, $rows);
        $this->assertSame($asset->id, $rows[0]['asset_id']);
        $this->assertSame($bravo->id, $rows[0]['client_id']);
        $this->assertSame($alpha->id, $rows[0]['other_client_id']);
        $this->assertSame('screenconnect', $rows[0]['evidence']['source']);
        // Per-client scope sees it too.
        $this->assertCount(1, $this->scRows($this->finder()->find($bravo->id)));
    }

    public function test_the_flat_company_field_is_read_too(): void
    {
        Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $session = 'aaaa0000-0000-0000-0000-000000000002';
        $this->asset($bravo, $session);
        $this->webhook($session, 'Alpha Co', 'flat');

        $this->assertCount(1, $this->scRows($this->finder()->find(null)));
    }

    public function test_only_the_newest_webhook_per_session_is_evidence(): void
    {
        Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $session = 'aaaa0000-0000-0000-0000-000000000003';
        $this->asset($bravo, $session);

        // Older row contradicts, newest agrees → silent.
        $this->webhook($session, 'Alpha Co');
        $this->webhook($session, 'Bravo Co');
        $this->assertCount(0, $this->scRows($this->finder()->find(null)));

        // Newest contradicts again → finding.
        $this->webhook($session, 'Alpha Co');
        $this->assertCount(1, $this->scRows($this->finder()->find(null)));
    }

    public function test_an_agreeing_unresolved_or_missing_company_is_no_finding(): void
    {
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $this->asset($bravo, 'aaaa0000-0000-0000-0000-000000000004');
        $this->webhook('aaaa0000-0000-0000-0000-000000000004', 'Bravo Co');
        $this->asset($bravo, 'aaaa0000-0000-0000-0000-000000000005');
        $this->webhook('aaaa0000-0000-0000-0000-000000000005', 'Nobody Co');
        $this->asset($bravo, 'aaaa0000-0000-0000-0000-000000000006');
        $this->webhook('aaaa0000-0000-0000-0000-000000000006', null);
        $this->asset($bravo, 'aaaa0000-0000-0000-0000-000000000007'); // never sent a webhook

        $this->assertCount(0, $this->scRows($this->finder()->find(null)));
    }

    public function test_an_ambiguous_company_is_no_finding(): void
    {
        Client::factory()->create(['name' => 'Alpha Co']);
        Client::factory()->create(['name' => 'ALPHA CO']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $session = 'aaaa0000-0000-0000-0000-000000000008';
        $this->asset($bravo, $session);
        $this->webhook($session, 'Alpha Co');

        $this->assertCount(0, $this->scRows($this->finder()->find(null)));
    }

    public function test_the_webhook_read_is_bounded_to_subject_sessions(): void
    {
        $alpha = Client::factory()->create(['name' => 'Alpha Co']);
        $bravo = Client::factory()->create(['name' => 'Bravo Co']);
        $this->asset($bravo, 'aaaa0000-0000-0000-0000-000000000009');
        $this->webhook('aaaa0000-0000-0000-0000-000000000009', 'Alpha Co');
        // Noise: many rows for sessions no subject asset carries.
        for ($i = 0; $i < 25; $i++) {
            $this->webhook(sprintf('bbbb0000-0000-0000-0000-%012d', $i), 'Alpha Co');
        }

        DB::enableQueryLog();
        $rows = $this->scRows($this->finder()->find($bravo->id));
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'screenconnect_webhooks'))->values();
        DB::disableQueryLog();

        $this->assertCount(1, $rows);
        $this->assertCount(2, $queries, 'one MAX(id) per-session query and one row fetch for the subject chunk');
        $this->assertStringContainsString('max(id)', strtolower($queries[0]));
        foreach ($queries as $q) {
            $this->assertStringContainsString(' in (', strtolower($q), 'every webhook read is bounded by an IN list');
        }
        // Alpha's own scope has no subject sessions → no webhook read at all.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->finder()->find($alpha->id);
        $alphaQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'screenconnect_webhooks'));
        DB::disableQueryLog();
        $this->assertCount(0, $alphaQueries);
    }

    public function test_the_note_names_the_screenconnect_evidence_and_its_gap(): void
    {
        $result = $this->finder()->find(null);

        $this->assertStringContainsString('ScreenConnect', $result['rmm_authority_note']);
        $this->assertStringContainsString('newest stored webhook', $result['rmm_authority_note']);
        $this->assertStringContainsString('ScreenConnect session id', $result['caveat']);
    }
}
