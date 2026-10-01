<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;

/**
 * Card 6abdcac2: Tactical device reads resolve through the stored keys (the client's
 * clients.tactical_site_id, the agent's synced client_name|site_name, the asset's
 * tactical_asset_id) and refuse a client that is not mapped. Fixtures written before
 * that modelled an UNMAPPED client; this creates the agent row the way the device
 * sync leaves it for a MAPPED one, so those tests keep testing what they test.
 *
 * Fills only what the caller left out: an explicit client_name/site_name, an existing
 * site key or an existing FK is kept as given.
 */
trait CreatesMappedTacticalAgents
{
    /** @param  array<string, mixed>  $attributes */
    protected function createMappedTacticalAgent(array $attributes): TacticalAsset
    {
        $asset = Asset::find($attributes['asset_id'] ?? null);
        $client = $asset?->client_id !== null ? Client::find($asset->client_id) : null;

        if ($client !== null) {
            if (trim((string) $client->tactical_site_id) === '') {
                $client->update(['tactical_site_id' => 'Synthetic Client '.$client->id.'|Main']);
            }
            [$clientName, $siteName] = explode('|', (string) $client->tactical_site_id, 2) + [1 => ''];
            $attributes += ['client_name' => $clientName, 'site_name' => $siteName];
        }

        $tacticalAsset = TacticalAsset::create($attributes);

        if ($asset !== null && $asset->tactical_asset_id === null) {
            $asset->update(['tactical_asset_id' => $tacticalAsset->id]);
        }

        return $tacticalAsset;
    }
}
