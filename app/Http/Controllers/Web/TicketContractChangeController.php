<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TimeEntryMoveProposal;
use App\Services\PhoneCallActionService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Http\Request;

/**
 * The ticket page's contract-change modal (card I3EvQKUV PR 2, SPEC §4, mockup 3)
 * and the technician cockpit's approval or denial of held agent moves (ruling Q9).
 */
class TicketContractChangeController extends Controller
{
    public function update(Request $request, Ticket $ticket, TimeEntryContractMoveService $moves)
    {
        $validated = $request->validate([
            'contract_id' => ['nullable', 'integer'],
            'move' => ['sometimes', 'array'],
            'move.*' => ['string', 'regex:/^(note|call):\d+$/'],
            'move_reason' => ['nullable', 'string', 'max:800'],
        ]);
        $selected = array_map(function (string $key) {
            [$type, $id] = explode(':', $key);

            return ['type' => $type, 'id' => (int) $id];
        }, $validated['move'] ?? []);

        try {
            $result = $moves->changeTicketContract($ticket, $validated['contract_id'] ?? null, $selected,
                $validated['move_reason'] ?? null, $request->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('tickets.show', $ticket)->withInput()
                ->withErrors(['contract_id' => $e->getMessage()])->with('error', $e->getMessage());
        }

        $message = 'Ticket contract updated.';
        if ($result['moved'] > 0) {
            $message .= ' Moved '.$result['moved'].' time '.($result['moved'] === 1 ? 'entry' : 'entries')
                .' ('.number_format($result['hours'], 2).' h).';
        }
        $redirect = redirect()->route('tickets.show', $ticket)->with('success', $message);

        return $result['errors'] === [] ? $redirect : $redirect->with('error', implode(' ', $result['errors']));
    }

    public function approve(Request $request, TimeEntryMoveProposal $proposal, TimeEntryContractMoveService $moves)
    {
        abort_unless(app(PhoneCallActionService::class)->canApprove($request->user()), 403);
        $result = $moves->approve($proposal, $request->user());

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Time entry moved.');
    }

    public function deny(Request $request, TimeEntryMoveProposal $proposal, TimeEntryContractMoveService $moves)
    {
        abort_unless(app(PhoneCallActionService::class)->canApprove($request->user()), 403);
        $result = $moves->deny($proposal->id, $request->user());

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? $result['message']);
    }
}
