<?php

namespace App\Services;

use App\Models\Contract;
use Illuminate\Support\Collection;

/**
 * The answer ContractResolver gives for one time entry: which contract new
 * time on a ticket belongs to, and by which rule.
 */
final class ContractResolution
{
    public const RESOLVED = 'resolved';

    public const AMBIGUOUS = 'ambiguous';

    public const NONE = 'none';

    public const INVALID_PICK = 'invalid_pick';

    /**
     * @param  Collection<int, Contract>  $candidates
     */
    private function __construct(
        public readonly string $status,
        public readonly ?Contract $contract,
        public readonly ?string $rule,
        public readonly Collection $candidates,
    ) {}

    public static function resolved(Contract $contract, string $rule): self
    {
        return new self(self::RESOLVED, $contract, $rule, collect());
    }

    /**
     * @param  Collection<int, Contract>  $candidates
     */
    public static function ambiguous(Collection $candidates): self
    {
        return new self(self::AMBIGUOUS, null, null, $candidates->values());
    }

    public static function none(): self
    {
        return new self(self::NONE, null, null, collect());
    }

    public static function invalidPick(): self
    {
        return new self(self::INVALID_PICK, null, null, collect());
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::AMBIGUOUS;
    }
}
