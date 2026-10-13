<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * A contract_id supplied at ticket creation that is not an ACTIVE contract of
 * the ticket's client (card I3EvQKUV PR 3, spec §7). Thrown by
 * TicketService::contractForNewTicket() before any row is written; surfaces
 * map it to their own refusal shape (agent tools: error_code
 * contract_not_allowed).
 */
class ContractNotAllowedException extends InvalidArgumentException
{
    public function __construct(public readonly string $renderedContractId)
    {
        parent::__construct("contract_id {$renderedContractId} cannot be used for this ticket.");
    }

    public static function for(mixed $contractId): self
    {
        return new self(is_bool($contractId) ? ($contractId ? 'true' : 'false') : (is_scalar($contractId) ? (string) $contractId : get_debug_type($contractId)));
    }

    /**
     * The agent-tool refusal (spec §7 refusal shape).
     *
     * @return array{error: string, error_code: string}
     */
    public function toolRefusal(): array
    {
        return [
            'error' => $this->getMessage().' Select a client and an active contract belonging to it, or omit contract_id for automatic selection (which may leave the contract unset).',
            'error_code' => 'contract_not_allowed',
        ];
    }
}
