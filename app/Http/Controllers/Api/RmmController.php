<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

/**
 * The read surface Leif RMM consumes.
 *
 * The PSA owns client identity and billing; the RMM owns device truth. So this
 * is the RMM's only source for who the clients are, and it exists because the
 * PSA previously had no machine-consumable way to answer that question at all —
 * every `api/clients` route is session + CSRF, usable by a browser and by
 * nothing else.
 *
 * Read-only by design. The RMM mirrors what the PSA asserts and never writes
 * back, so there is no route here that changes anything.
 */
class RmmController extends Controller
{
    /**
     * GET /api/rmm/clients
     *
     * Every client, with the integration ids the RMM needs to join vendor
     * records to them.
     *
     * NOT paginated, deliberately. This is an estate of tens of clients, and a
     * paginated list is a list a consumer can silently read the first page of
     * and treat as complete. If it ever grows enough to need paging, add a
     * cursor and a total the caller must reconcile against — do not add a
     * default page size.
     *
     * Soft-deleted clients are excluded by the model's global scope, which is
     * the intended behaviour: a deleted client is not one the RMM should be
     * managing devices for. Inactive ones ARE returned, with `is_active` false,
     * because the RMM still needs to account for their devices rather than have
     * them vanish from the estate.
     */
    public function clients(): JsonResponse
    {
        $clients = Client::query()
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'is_active',
                'huntress_organization_id',
                'controld_org_id',
                'tactical_site_id',
            ])
            ->map(fn (Client $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'is_active' => (bool) $c->is_active,
                // Vendor ids are returned as strings or null. They land in the
                // RMM's `external_ref.external_id`, which is TEXT, and an id
                // that arrives as 1001 in one place and "1001" in another is a
                // join that fails for no visible reason.
                'huntress_organization_id' => self::asId($c->huntress_organization_id),
                'controld_org_id' => self::asId($c->controld_org_id),
                'tactical_site_id' => self::asId($c->tactical_site_id),
            ])
            ->values();

        return response()->json([
            'clients' => $clients,
            // An explicit count so a truncated or partially-read response is
            // detectable by the consumer rather than silently short.
            'count' => $clients->count(),
        ]);
    }

    /**
     * Normalise a vendor id to a string, or null when genuinely absent.
     *
     * Empty string and "0" both mean absent. A blank column says nobody has
     * mapped this client, and `huntress_organization_id` is cast to integer on
     * the model — so a blank that ever reached the database arrives here as 0
     * rather than as an empty string. Returning either would let the consumer
     * write a mapping to an organisation that does not exist, which is worse
     * than no mapping: it produces a confident join to nothing.
     */
    private static function asId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return ($s === '' || $s === '0') ? null : $s;
    }
}
