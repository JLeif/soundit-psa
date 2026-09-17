<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use Illuminate\Http\Request;

/**
 * The client-page "Onboard to Control D" button (B4). Admin-only (RequireAdmin on
 * the route). It stages the SAME cockpit proposal the `controld_onboard_client`
 * verb stages — it never calls the onboarding services itself — so the second-Admin
 * approval, the audit row and the one-step-per-proposal rule are identical whether
 * the trigger was a person or an MCP token. The route is inert (404) unless
 * ControlDConfig::isOnboardingActive(), matching the button's own render guard.
 */
class ClientControlDOnboardingController extends Controller
{
    public function stage(Request $request, Client $client, StaffControlDOnboardingToolExecutor $executor)
    {
        abort_unless(ControlDConfig::isEnabled() && ControlDConfig::isConfigured() && ControlDConfig::isOnboardingActive(), 404);

        $validated = $request->validate([
            'ticket_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $ticket = Ticket::find((int) $validated['ticket_id']);
        if (! $ticket || (int) $ticket->client_id !== (int) $client->id) {
            return redirect()->route('clients.show', $client)->withErrors(['ticket_id' => 'Pick one of this client\'s tickets to hold the onboarding proposal on.']);
        }

        $result = $executor->stageForClient($client, $ticket, $validated['reason'], $request->user());

        if (isset($result['error'])) {
            return redirect()->route('clients.show', $client)->withErrors(['controld_onboarding' => $result['error']]);
        }

        $step = $result['step'] ?? '';
        $message = ($result['idempotent'] ?? false)
            ? ($result['message'] ?? 'Already staged.')
            : "Control D onboarding step '{$step}' staged for cockpit approval by a second Admin (run #{$result['run_id']}).";

        return redirect()->route('clients.show', $client)->with('success', $message);
    }
}
