<?php

namespace App\Services\BenjiPays;

use RuntimeException;

/** Safe, bounded failure metadata only: never retain a vendor body or previous exception. */
final class BenjiPaysException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct(match ($reason) {
            'unauthorized' => 'BenjiPays authentication or organization API access was refused (401). Check credentials and whether API access is active.',
            'forbidden' => 'BenjiPays access was forbidden (403). Check scope, owner mapping permissions, and trial/billing eligibility.',
            'configuration' => 'BenjiPays requires a stored API key without control characters.',
            'invalid_id' => 'A valid accounting invoice ID is required.',
            'invalid_response' => 'BenjiPays returned an unusable response.',
            'transport' => 'BenjiPays could not be reached. Try again later.',
            default => 'BenjiPays request failed. Check service availability, rate limits and accounting integration configuration.',
        });
    }

    public function outcome(): string
    {
        return match ($this->httpStatus) {
            401 => '401',
            403 => '403',
            default => 'error',
        };
    }
}
