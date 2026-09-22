<?php

namespace App\Services\Agent\Escalation;

final class OperatorDeliveryResult
{
    public function __construct(
        public readonly bool $posted,
        public readonly bool $postedToChat,
        public readonly ?string $remoteMessageId,
        public readonly ?OperatorScanMetadata $scanMetadata = null,
    ) {}

    /** No verdict is invented for callers that never scan (notably emergency pages).
     * @return array<string, mixed>
     */
    public function scanReceipt(): array
    {
        return $this->scanMetadata?->toArray() ?? ['scan_status' => 'unassessed'];
    }
}
