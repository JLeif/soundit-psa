<?php

namespace App\Services\Technician\Scheduled;

use App\Services\Tactical\Actions\TacticalAction;
use App\Services\Tactical\Actions\TacticalActionResult;
use App\Services\Tactical\TacticalClient;

/** Retains the audited Tactical bus, but never equates HTTP success with effect success. */
final class TacticalScheduledAction implements TacticalAction
{
    public bool $receiptHandled = false;

    public function __construct(private string $type, private ?int $authorizationId = null, private ?string $nonce = null) {}

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
            $body = $client->submitScheduledOnce($this->type, $agentId, $params,
                $this->authorizationId === null ? null : fn () => app(ScheduledCoordinator::class)->beforeSend($this->authorizationId, $this->nonce ?? ''),
            );
            $outcome = TacticalPlan::outcome($this->type, $body);
        } catch (ScheduledNoSend) {
            return TacticalActionResult::blocked('scheduled_no_send');
        } catch (\Throwable) {
            $outcome = 'uncertain';
        }

        if ($this->authorizationId !== null) {
            $coordinator = app(ScheduledCoordinator::class);
            $settled = $coordinator->settle($this->authorizationId, $this->nonce ?? '', $outcome);
            $this->receiptHandled = true;
            if (! $settled) {
                $coordinator->lateReceipt($this->authorizationId, $this->nonce ?? '', 'tactical', null, $outcome);

                // The bus audits this result: do not report success after refusal.
                return TacticalActionResult::error('scheduled_late_receipt');
            }
        }

        // Keep an immutable audit even for an unknown receipt; never log raw vendor output.
        return TacticalActionResult::ok($outcome);
    }
}
