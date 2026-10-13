<?php

namespace App\Services;

use App\Models\Contract;
use Illuminate\Support\Collection;

/**
 * A resolved contract outcome: picked | ticket | client_default | only_active | none | ambiguous.
 * Rule ticket identifies an existing ticket's contract: in a resolver answer, its active contract.
 * The deleted-ticket block in StaffPsaActionToolExecutor::existingTicketContractBlock is built by
 * hand, not by this class, and reports the stored contract under rule ticket whatever its status.
 * For a live existing ticket, callers add ticket_contract_id when the stored id differs; the
 * deleted-ticket block always adds it. Instances are built from a ContractResolution:
 * a ContractResolver answer, or ContractResolution::none() where there is no client or no ticket;
 * this class decides nothing.
 */
final class TicketContractOutcome
{
    public const RULE_NONE = 'none';

    public const RULE_AMBIGUOUS = 'ambiguous';

    /**
     * @param  Collection<int, Contract>  $candidates
     */
    private function __construct(
        public readonly string $rule,
        public readonly ?Contract $contract,
        public readonly Collection $candidates,
    ) {}

    public static function fromResolution(ContractResolution $resolution): self
    {
        if ($resolution->isResolved()) {
            return new self((string) $resolution->rule, $resolution->contract, collect());
        }

        return $resolution->isAmbiguous()
            ? new self(self::RULE_AMBIGUOUS, null, $resolution->candidates)
            : new self(self::RULE_NONE, null, collect());
    }

    /**
     * Serialize this resolution for agent responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'contract' => $this->contract === null ? null : [
                'id' => $this->contract->id,
                'name' => $this->contract->name,
                'rule' => $this->rule,
            ],
            'contract_rule' => $this->rule,
        ];

        if ($this->rule === self::RULE_AMBIGUOUS) {
            $out['candidate_contracts'] = $this->candidates
                ->sortBy('id')
                ->map(fn (Contract $c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type?->value])
                ->values()
                ->all();
        }

        return $out;
    }
}
