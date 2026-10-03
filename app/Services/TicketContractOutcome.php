<?php

namespace App\Services;

use App\Models\Contract;
use Illuminate\Support\Collection;

/**
 * Which contract a NEW ticket took, and by which rule (card I3EvQKUV PR 3,
 * spec §7): picked | client_default | only_active | none | ambiguous.
 * Built only from a ContractResolver answer; this class decides nothing.
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
     * The response block every agent create/update reports.
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
