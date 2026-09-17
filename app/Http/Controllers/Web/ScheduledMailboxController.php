<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TechnicianRun;
use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Cockpit controls for an already-admitted scheduled authorization. Admission itself is
 * the ordinary cockpit Approve on a proposal that carries `execute_at`
 * (TechnicianCockpitController::approve → ScheduledApproval); the former schedule form
 * (create/store) is gone with the global toggle.
 */
class ScheduledMailboxController extends Controller
{
    public function cancel(TechnicianRun $run, ScheduledCoordinator $coordinator)
    {
        // Cancel is stop-only: it issues no dispatch intent and mutates no mailbox, so it stays
        // available to the approver even when the ticket binding has changed. Dispatch of such a
        // row is refused at intent; withholding the stop control would leave the approver with no
        // way to prevent a mutation they are authorised to prevent.
        try {
            app(ScheduledPolicy::class)->approver((int) auth()->id());
        } catch (\Throwable) {
            abort(403);
        }
        $row = DB::table('scheduled_authorizations')->where('run_id', $run->id)->orderByDesc('revision')->first();
        abort_unless($row && (int) $row->approver_user_id === (int) auth()->id(), 403);
        $ok = $coordinator->cancel($row->id, (int) auth()->id());

        return redirect()->route('cockpit.index')->with($ok ? 'success' : 'error', $ok
            ? 'Scheduled approval cancelled. Nothing will be submitted for this authorization.'
            : 'Cancellation lost the dispatch race or this authorization is already terminal. Inspect its result; do not retry the action.');
    }
}
