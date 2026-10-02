<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;

/**
 * THE producer of "which contract does new time on this ticket belong to".
 * Card I3EvQKUV, spec §2, as ruled 2026-10-02 (Q1, Q3, Q7, Q10).
 *
 * Order:
 *  1. picked        the contract chosen on the entry; must be an ACTIVE
 *                   contract of the ticket's client, otherwise INVALID_PICK;
 *  2. ticket        tickets.contract_id, only while it is active and belongs
 *                   to the ticket's client (an expired ticket contract is
 *                   skipped, so new time goes to the client default: Q3);
 *  3. client_default clients.default_contract_id, under the same checks (a
 *                   query-builder write can leave a stale default, so the
 *                   column is never trusted on its own);
 *  4. only_active   the client has exactly one active contract;
 *  5. several active contracts and no default: AMBIGUOUS (the caller asks a
 *     person, or holds the debit under "Needs contract": Q7); zero: NONE.
 *
 * There is no draw order: nothing here picks between several contracts.
 * "Active" is ContractStatus::Active and not soft-deleted, the one definition
 * Contract::scopeActive() already uses.
 */
class ContractResolver
{
    public const RULE_PICKED = 'picked';

    public const RULE_TICKET = 'ticket';

    public const RULE_CLIENT_DEFAULT = 'client_default';

    public const RULE_ONLY_ACTIVE = 'only_active';

    public function forEntry(Ticket $ticket, ?int $pickedContractId = null): ContractResolution
    {
        $clientId = $ticket->client_id === null ? null : (int) $ticket->client_id;

        if ($pickedContractId !== null) {
            $picked = $this->activeContractOf($clientId, $pickedContractId);

            return $picked
                ? ContractResolution::resolved($picked, self::RULE_PICKED)
                : ContractResolution::invalidPick();
        }

        if ($ticket->contract_id !== null) {
            $ticketContract = $this->activeContractOf($clientId, (int) $ticket->contract_id);
            if ($ticketContract) {
                return ContractResolution::resolved($ticketContract, self::RULE_TICKET);
            }
        }

        if ($clientId === null) {
            return ContractResolution::none();
        }

        return $this->forClient($clientId);
    }

    /**
     * Rules 3-5 only: the client default, else the only active contract.
     */
    public function forClient(int $clientId): ContractResolution
    {
        $defaultId = Client::whereKey($clientId)->value('default_contract_id');
        if ($defaultId !== null) {
            $default = $this->activeContractOf($clientId, (int) $defaultId);
            if ($default) {
                return ContractResolution::resolved($default, self::RULE_CLIENT_DEFAULT);
            }
        }

        $active = Contract::query()
            ->where('client_id', $clientId)
            ->where('status', ContractStatus::Active)
            ->get();

        if ($active->count() === 1) {
            return ContractResolution::resolved($active->first(), self::RULE_ONLY_ACTIVE);
        }

        return $active->isEmpty()
            ? ContractResolution::none()
            : ContractResolution::ambiguous($active);
    }

    /**
     * Whether $contractId may be a client's default: an active contract of
     * that client (Q1: any type, not only prepay).
     */
    public function canBeDefault(int $clientId, int $contractId): bool
    {
        return $this->activeContractOf($clientId, $contractId) !== null;
    }

    private function activeContractOf(?int $clientId, int $contractId): ?Contract
    {
        if ($clientId === null) {
            return null;
        }

        return Contract::query()
            ->whereKey($contractId)
            ->where('client_id', $clientId)
            ->where('status', ContractStatus::Active)
            ->first();
    }
}
