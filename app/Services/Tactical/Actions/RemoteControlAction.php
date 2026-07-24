<?php

namespace App\Services\Tactical\Actions;

use App\Rules\SafeTacticalWebUrl;
use App\Services\Tactical\TacticalClient;
use Illuminate\Support\Facades\Validator;

/**
 * Open a MeshCentral remote-control session (control / terminal / file) on a
 * Tactical agent. Unlike every other action here this returns a LIVE URL rather
 * than mutating the endpoint — so when it runs through the STAGED approval path,
 * execute() fetches the link at APPROVAL time (a fresh, non-stale URL), not at
 * proposal time. The staging (human cockpit approval) IS the gate, so the action
 * is not itself "destructive" in the confirm-token sense.
 *
 * Side-effect-free w.r.t. PSA models (m5): it only talks to TacticalClient.
 * Encapsulates the link-resolution + SafeTacticalWebUrl validation that the
 * immediate path (StaffTacticalActionToolExecutor::openRemoteControl) does
 * inline. psa-5s4r2 / so-1jq4.
 */
class RemoteControlAction implements TacticalAction
{
    /** MeshCentral session types getMeshCentralLinks() returns. */
    private const TYPES = ['control', 'terminal', 'file'];

    public function key(): string
    {
        return 'tactical.open_remote_control';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validateParams(array $params): array
    {
        $type = is_string($params['type'] ?? null) ? strtolower(trim($params['type'])) : '';
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidActionParams('type must be one of: '.implode(', ', self::TYPES).'.');
        }

        return ['type' => $type];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function summary(array $params): string
    {
        $type = is_string($params['type'] ?? null) ? $params['type'] : 'control';

        return "Open {$type} remote-control session";
    }

    /**
     * Runs at APPROVAL time: fetch the MeshCentral link for the requested type,
     * validate it, and return it on the result's TRANSIENT sessionUrl (via
     * ::session()) — NOT stdout. This path runs through the TacticalActionService
     * bus, whose audit() persists stdout to tactical_action_logs.output; a live
     * session URL is a one-time credential, so it must never land there. The
     * staged-approval executor reads sessionUrl and hands it to the approver over
     * the one-time no-store secret channel. A missing/invalid link is a normal
     * `error` outcome (the device may not expose that session type).
     *
     * @param  array<string, mixed>  $params  already normalized by validateParams()
     */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $type = is_string($params['type'] ?? null) ? $params['type'] : 'control';

        $links = $client->getMeshCentralLinks($agentId);
        $url = is_array($links) ? ($links[$type] ?? null) : null;

        if (! is_string($url) || $url === '' || ! Validator::make(['u' => $url], ['u' => [new SafeTacticalWebUrl]])->passes()) {
            return TacticalActionResult::error("MeshCentral {$type} link is not available for this device.");
        }

        return TacticalActionResult::session($url);
    }
}
