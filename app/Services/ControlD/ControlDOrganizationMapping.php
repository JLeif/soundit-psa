<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manual mapping may add identities, never erase or replace established ones (#2010). */
class ControlDOrganizationMapping
{
    public function replace(array $mappings): int
    {
        return DB::transaction(function () use ($mappings) {
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
                    $this->refuse();
                }
                $wanted[(int) $id] = $pk;
            }
            foreach ($clients as $client) {
                $pk = $wanted[$client->id] ?? null;
                if ($client->controld_org_id !== null) {
                    // Deleted rows are not in the form, but still reserve their identity.
                    if (! $client->trashed() && $pk !== $client->controld_org_id) {
                        $this->refuse();
                    }
                    if (in_array($client->controld_org_id, $wanted, true)
                        && ($wanted[$client->id] ?? null) !== $client->controld_org_id) {
                        $this->refuse();
                    }
                }
                foreach (ControlDOnboardingIntent::where('client_id', $client->id)->where('state', 'bound')->get(['org_pk']) as $intent) {
                    if (! $client->trashed() && $pk !== $intent->org_pk) {
                        $this->refuse();
                    }
                    // Bound evidence reserves its org for its owner even after the column was
                    // cleared or the owner deleted: nobody else may be handed a B2/B3 org.
                    if (in_array($intent->org_pk, $wanted, true) && $pk !== $intent->org_pk) {
                        $this->refuse();
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

    private function refuse(): never
    {
        throw ValidationException::withMessages(['mappings' => 'Existing Control D identities cannot be cleared, reassigned or replaced. Resolve mapping conflicts before saving.']);
    }
}
