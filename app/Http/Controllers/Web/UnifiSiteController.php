<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientUnifiSite;
use App\Services\Unifi\UnifiClient;
use App\Support\UnifiConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Integrations > UniFi > Site Mapping (psa-g5l80; multi-site psa-jpygj).
 *
 * Mirrors HuntressOrganizationController, with one deliberate difference forced by the
 * vendor's data model: a mapping is the PAIR unifi_site_id (the telemetry grain) +
 * unifi_host_id (the owning console, which unifi_list_devices requires because
 * /v1/devices is host-grained). The console id is resolved SERVER-SIDE from the vendor's
 * own /v1/sites listing at save time, never from the submitted form: UnifiReadOnlyToolset's
 * device-attribution guards trust the pair, and letting the browser supply the console id
 * would let a tampered request bind a client to an arbitrary console.
 *
 * Mappings live in the client_unifi_sites pivot: a client may map to MANY sites (several
 * physical locations), while a SITE still maps to at most one client (the pivot's UNIQUE
 * on unifi_site_id). The page is one row per site with a client dropdown, so N sites → 1
 * client is expressed by choosing the same client on several rows.
 *
 * Field names in the projection below come from the vendor's OpenAPI spec
 * (https://developer.ui.com/site-manager/v1.0.0/openapi.json) via UnifiClient::listSites
 * — see that docblock and tests/Fixtures/unifi/list_sites.json.
 */
class UnifiSiteController extends Controller
{
    public function index()
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not list UniFi sites: {$e->getMessage()}");
        }

        // site id => the client mapped to it, for per-row preselection. Joining through
        // Client::query() keeps the soft-delete scope (a trashed client reads unmapped).
        $mappedClients = Client::query()
            ->join('client_unifi_sites', 'client_unifi_sites.client_id', '=', 'clients.id')
            ->get(['clients.id', 'clients.name', 'client_unifi_sites.unifi_site_id'])
            ->keyBy('unifi_site_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.unifi-sites', [
            'sites' => $sites,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        // The form is one row per VISIBLE site. Keep every submitted site id (including
        // deselections, value '') so we can re-assert exactly the rows the operator saw;
        // sites not on the form (e.g. no longer visible to the key) are left untouched.
        $submitted = collect((array) $request->input('mappings', []))
            ->mapWithKeys(fn ($clientId, $siteId) => [(string) $siteId => trim((string) $clientId)]);

        $selected = $submitted->filter(fn ($clientId) => $clientId !== '');

        // psa-jpygj: the one-to-one refusal is gone — a client MAY map to several sites.
        // A SITE still maps to <=1 client (the pivot's UNIQUE), and the form is keyed by
        // site, so a single save cannot double-map a site.

        // Console ids come from the live vendor listing at save time (see class
        // docblock). If the listing cannot be fetched, save nothing — a save that
        // wrote site ids with stale or missing console ids would half-break device
        // reads while looking successful.
        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.unifi-sites.index')
                ->with('error', "Could not save mappings — the UniFi site listing failed ({$e->getMessage()}). Existing mappings were left untouched.");
        }

        $skipped = [];

        DB::transaction(function () use ($submitted, $selected, $sites, &$skipped) {
            // Re-assert only the sites shown in this form: drop their current pivot rows,
            // then insert the chosen ones. Deleting by site id (the pivot is not soft-
            // deleted) also clears a row held by a soft-deleted client, so remapping that
            // site to a live client cannot collide on the UNIQUE.
            ClientUnifiSite::whereIn('unifi_site_id', $submitted->keys()->all())->delete();

            foreach ($selected as $siteId => $clientId) {
                $site = $sites[(string) $siteId] ?? null;

                if ($site === null) {
                    // Submitted for a site the API key can no longer see — writing it
                    // would store a console id we cannot verify. Skip and say so.
                    $skipped[] = (string) $siteId;

                    continue;
                }

                ClientUnifiSite::create([
                    'client_id' => (int) $clientId,
                    'unifi_site_id' => $site['site_id'],
                    'unifi_host_id' => $site['host_id'],
                ]);
            }
        });

        $message = 'Saved '.($selected->count() - count($skipped)).' UniFi site mapping(s).';
        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).' site(s) no longer visible to this API key: '.implode(', ', $skipped).'.';
        }

        return redirect()->route('settings.unifi-sites.index')
            ->with('success', $message);
    }

    /**
     * Auto-match UniFi sites to clients by exact name match against the site's display
     * label (meta.desc — what the UniFi UI shows) or its internal name (meta.name).
     * Only fills unmapped sites and unmapped clients — never overwrites existing
     * mappings. Writes the site + console pair, same as a manual save.
     */
    public function autoMatch()
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.unifi-sites.index')
                ->with('error', "Could not list UniFi sites: {$e->getMessage()}");
        }

        // Lookup: lowercase client name → client, clients with no site yet only.
        $clientsByName = Client::operational()
            ->whereDoesntHave('unifiSites')
            ->get(['id', 'name'])
            ->keyBy(fn ($client) => mb_strtolower(trim($client->name)));

        $matched = 0;

        foreach ($sites as $site) {
            // A site already mapped to any client (the pivot's UNIQUE holds it) is never
            // re-assigned by auto-match.
            if (ClientUnifiSite::where('unifi_site_id', $site['site_id'])->exists()) {
                continue;
            }

            foreach ([$site['description'], $site['internal_name']] as $label) {
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }

                $key = mb_strtolower(trim($label));
                $client = $clientsByName->get($key);

                if ($client) {
                    ClientUnifiSite::create([
                        'client_id' => $client->id,
                        'unifi_site_id' => $site['site_id'],
                        'unifi_host_id' => $site['host_id'],
                    ]);
                    // Remove from lookup so the same client isn't matched twice.
                    $clientsByName->forget($key);
                    $matched++;

                    break;
                }
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} site(s) by name."
            : 'No new matches found. Sites may need manual mapping.';

        return redirect()->route('settings.unifi-sites.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }

    /**
     * All sites the API key can see, projected for this page and keyed by siteId,
     * sorted by display label. UnifiClient::allSites() walks the cursor and FAILS LOUD
     * if it cannot fetch everything — a partial table here would read as "those sites
     * are gone".
     *
     * @return array<string, array{site_id: string, host_id: ?string, label: string, internal_name: ?string, description: ?string, device_count: ?int, isp_name: ?string}>
     */
    private function fetchSites(): array
    {
        $rows = app(UnifiClient::class)->allSites();

        $sites = [];
        foreach ($rows as $row) {
            $siteId = $row['siteId'] ?? null;
            if (! is_string($siteId) || $siteId === '') {
                continue;
            }

            $hostId = $row['hostId'] ?? null;
            $description = $row['meta']['desc'] ?? null;
            $internalName = $row['meta']['name'] ?? null;
            $deviceCount = $row['statistics']['counts']['totalDevice'] ?? null;
            $ispName = $row['statistics']['ispInfo']['name'] ?? null;

            $label = $siteId;
            foreach ([$description, $internalName] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $label = trim($candidate);

                    break;
                }
            }

            $sites[$siteId] = [
                'site_id' => $siteId,
                'host_id' => is_string($hostId) && $hostId !== '' ? $hostId : null,
                'label' => $label,
                'internal_name' => is_string($internalName) ? $internalName : null,
                'description' => is_string($description) ? $description : null,
                'device_count' => is_int($deviceCount) ? $deviceCount : null,
                'isp_name' => is_string($ispName) ? $ispName : null,
            ];
        }

        uasort($sites, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $sites;
    }
}
