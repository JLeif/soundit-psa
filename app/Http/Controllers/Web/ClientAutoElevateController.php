<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Support\AutoElevateConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only AutoElevate panel on the client page (stage 2). Returns the panel partial for
 * one client in exactly one of four states, each named explicitly so a blank table can
 * never be mistaken for "no machines" (C-56):
 *
 *   not_mapped   — the client has no autoelevate_company_id (no vendor call is made)
 *   empty        — mapped; the vendor answered and returned zero computers
 *   failed       — mapped; the read was degraded (configuration/transport/status/drift/paging)
 *   ok           — mapped; one or more computers
 *
 * The panel is fetched on demand when the Integrations tab opens (the vendor's bucket is
 * 100 requests per hour per route). Successful reads are cached for 60 seconds per company;
 * a failure is never cached, so the next open retries.
 */
class ClientAutoElevateController extends Controller
{
    public const CACHE_TTL = 60;

    public function __construct(private readonly AutoElevateReadService $reads) {}

    public function computers(Client $client)
    {
        return response()->view('clients.partials.autoelevate', self::panelData($client, $this->reads));
    }

    /**
     * @return array{state: string, reason: ?string, computers: list<array<string, mixed>>, client: Client}
     */
    public static function panelData(Client $client, AutoElevateReadService $reads): array
    {
        $companyId = $client->autoelevate_company_id;
        if ($companyId === null || $companyId === '') {
            return ['state' => 'not_mapped', 'reason' => null, 'computers' => [], 'client' => $client];
        }

        if (! AutoElevateConfig::isConfigured()) {
            return ['state' => 'failed', 'reason' => 'configuration', 'computers' => [], 'client' => $client];
        }

        $cacheKey = 'autoelevate_computers_'.strtolower($companyId);
        $computers = Cache::get($cacheKey);
        if (! is_array($computers)) {
            try {
                $computers = $reads->computersForCompany($companyId);
            } catch (AutoElevateReadException $e) {
                return ['state' => 'failed', 'reason' => $e->reason, 'computers' => [], 'client' => $client];
            }
            Cache::put($cacheKey, $computers, self::CACHE_TTL);
        }

        return [
            'state' => $computers === [] ? 'empty' : 'ok',
            'reason' => null,
            'computers' => $computers,
            'client' => $client,
        ];
    }
}
