<?php

namespace App\Services\Technician\Scheduled;

use App\Services\Tactical\Actions\TacticalAction;
use App\Services\Tactical\Actions\TacticalActionResult;
use App\Services\Tactical\TacticalClient;

/** Retains the audited Tactical bus, but never equates HTTP success with effect success. */
final class TacticalScheduledAction implements TacticalAction
{
    public function __construct(private string $type) {}

    public function key(): string
    {
        return TacticalPlan::action($this->type)->key();
    }

    public function isDestructive(): bool
    {
        return TacticalPlan::action($this->type)->isDestructive();
    }

    public function validateParams(array $params): array
    {
        return TacticalPlan::params($this->type, $params);
    }

    public function summary(array $params): string
    {
        return TacticalPlan::action($this->type)->summary($params);
    }

    public function payloadHash(array $params): ?string
    {
        $action = TacticalPlan::action($this->type);

        return method_exists($action, 'payloadHash') ? $action->payloadHash($params) : null;
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        try {
            $body = $client->submitScheduledOnce($this->type, $agentId, $params);
            $outcome = TacticalPlan::outcome($this->type, $body);
        } catch (\Throwable) {
            $outcome = 'uncertain';
        }

        // Keep an immutable audit even for an unknown receipt; never log raw vendor output.
        return TacticalActionResult::ok($outcome);
    }
}
