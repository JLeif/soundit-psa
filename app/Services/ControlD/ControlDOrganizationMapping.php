<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manual mapping may add identities, never erase or replace established ones (#2010). */
class ControlDOrganizationMapping
{
    /**
     * @param  array<string, mixed>  $mappings  submitted organization pk => client id
     * @param  array<int, string>|null  $listed  the organization pks the submitting form actually
     *                                           rendered a select for. Null means the caller
     *                                           declared nothing, so an absent pk is still read as
     *                                           a clear and the save fails closed, as before.
     */
    public function replace(array $mappings, ?array $listed = null): int
    {
        return DB::transaction(function () use ($mappings, $listed) {
            // Same client locks as the onboarding writers. Include deleted owners.
            $clients = Client::withTrashed()->orderBy('id')->lockForUpdate()->get();
            $wanted = [];
            foreach ($mappings as $pk => $id) {
                if ($id === null || $id === '') {
                    continue;
                }
                $pk = (string) $pk;
                if (! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id < 1
                    || $pk === '' || strlen($pk) > 50 || isset($wanted[(int) $id])) {
                    $this->refuse();
                }
                $client = $clients->firstWhere('id', (int) $id);
                if (! $client || $client->trashed()) {
                    $this->refuse('client #'.(int) $id.' no longer exists or is deleted');
                }
                $wanted[(int) $id] = $pk;
            }
            foreach ($clients as $client) {
                $pk = $wanted[$client->id] ?? null;
                if ($client->controld_org_id !== null) {
                    // A mapping counts as CLEARED only when the submitting form actually offered
                    // its organization. An org the page never rendered (gone upstream, outside
                    // the listing, owner not selectable) is absent from the POST for reasons that
                    // are not the operator's intent, and reading that absence as a clear made one
                    // stale mapping refuse every later save on the page, additive ones included.
                    // Deleted rows are not in the form, but still reserve their identity.
                    $offered = $listed === null || in_array($client->controld_org_id, $listed, true);
                    if (! $client->trashed() && $pk !== $client->controld_org_id && ($pk !== null || $offered)) {
                        $this->refuse("client #{$client->id} is already mapped to organization {$client->controld_org_id}");
                    }
                    if (in_array($client->controld_org_id, $wanted, true)
                        && ($wanted[$client->id] ?? null) !== $client->controld_org_id) {
                        $this->refuse("organization {$client->controld_org_id} belongs to client #{$client->id}");
                    }
                }
                foreach (ControlDOnboardingIntent::where('client_id', $client->id)->where('state', 'bound')->get(['org_pk']) as $intent) {
                    // A submission that re-points a bound client is refused; one that is merely
                    // silent about it changes nothing (the column branch above owns the clear).
                    if (! $client->trashed() && $pk !== null && $pk !== $intent->org_pk) {
                        $this->refuse("client #{$client->id} was onboarded to organization {$intent->org_pk}");
                    }
                    // Bound evidence reserves its org for its owner even after the column was
                    // cleared or the owner deleted: nobody else may be handed a B2/B3 org.
                    if (in_array($intent->org_pk, $wanted, true) && $pk !== $intent->org_pk) {
                        $this->refuse("organization {$intent->org_pk} was onboarded for client #{$client->id}");
                    }
                }
            }
            foreach ($wanted as $id => $pk) {
                $client = $clients->firstWhere('id', $id);
                if ($client->controld_org_id === null) {
                    $client->forceFill(['controld_org_id' => $pk])->saveOrFail();
                }
            }

            return count($wanted);
        });
    }

    public function autoMatch(int $clientId, string $orgPk): bool
    {
        return DB::transaction(function () use ($clientId, $orgPk) {
            $client = Client::whereKey($clientId)->lockForUpdate()->first();
            if (! $client || $client->controld_org_id !== null
                || Client::withTrashed()->where('controld_org_id', $orgPk)->exists()) {
                return false;
            }
            if (ControlDOnboardingIntent::where('client_id', $clientId)->where('state', 'bound')->exists()) {
                $this->refuse();
            }
            $client->forceFill(['controld_org_id' => $orgPk])->saveOrFail();

            return true;
        });
    }

    /**
     * Single-client guard for the client-page Link/Unlink (ClientIntegrationService).
     * The bulk form above is not the only sibling writer of `controld_org_id`; the
     * client page can clear or set it one client at a time. A mapping written by B2/B3
     * (a `bound` intent exists for the client) may not be cleared or re-pointed there
     * either, and a new link may not take an org a bound intent or a soft-deleted
     * client already owns. A manual mapping with no bound evidence stays removable
     * from the client page: that is the explicit single-client removal path.
     * $orgPk null means "clear".
     */
    public function assertClientChangeAllowed(Client $client, ?string $orgPk): void
    {
        $bound = ControlDOnboardingIntent::where('client_id', $client->id)->where('state', 'bound');
        if ($client->controld_org_id !== null && $orgPk !== $client->controld_org_id && (clone $bound)->exists()) {
            $this->refuse();
        }
        if ($orgPk !== null) {
            if ((clone $bound)->where('org_pk', '!=', $orgPk)->exists()
                || ControlDOnboardingIntent::where('state', 'bound')->where('org_pk', $orgPk)->where('client_id', '!=', $client->id)->exists()
                || Client::withTrashed()->where('controld_org_id', $orgPk)->where('id', '!=', $client->id)->exists()) {
                $this->refuse();
            }
        }
    }

    /** $detail names the conflicting client/organization so the operator can resolve it. */
    private function refuse(?string $detail = null): never
    {
        throw ValidationException::withMessages(['mappings' => 'Existing Control D identities cannot be cleared, reassigned or replaced. Resolve mapping conflicts before saving.'
            .($detail !== null ? " Conflict: {$detail}." : '')]);
    }
}
