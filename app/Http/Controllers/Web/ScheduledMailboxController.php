<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TechnicianRun;
use App\Services\Technician\Scheduled\ActionRegistry;
use App\Services\Technician\Scheduled\MailboxEvidence;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledPolicy;
use App\Services\Technician\Scheduled\TacticalEvidence;
use App\Services\Technician\Scheduled\TacticalPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduledMailboxController extends Controller
{
    private function authorizeRun(TechnicianRun $run): void
    {
        try {
            app(ScheduledPolicy::class)->approver((int) auth()->id());
            app(ScheduledPolicy::class)->ticket($run);
        } catch (\Throwable) {
            abort(403);
        }
    }

    /** Admission demands recorded staging lineage; without it every admission attempt refuses. */
    private function hasSchedulingLineage(TechnicianRun $run): bool
    {
        $provenance = is_array($run->proposed_meta) ? ($run->proposed_meta['scheduled_provenance'] ?? null) : null;

        return is_array($provenance) && ($provenance['version'] ?? null) === 1;
    }

    public function create(TechnicianRun $run)
    {
        $this->authorizeRun($run);
        abort_if(ActionRegistry::admissionRefusal($run->action_type) !== null, 422, ActionRegistry::admissionRefusal($run->action_type) ?? '');
        abort_unless(config('scheduled_approvals.enabled') && ActionRegistry::adapterAvailable($run->action_type), 422, 'Scheduling is disabled or unsupported for this action.');
        abort_unless($this->hasSchedulingLineage($run), 422, 'This proposal carries no recorded staging lineage, so it can never be scheduled. Approve it directly instead.');
        abort_unless($run->state === \App\Enums\TechnicianRunState::AwaitingApproval, 409, 'This proposal is no longer awaiting approval.');

        return view('cockpit.schedule', ['run' => $run]);
    }

    public function store(Request $request, TechnicianRun $run, ScheduledAdmission $admission, MailboxEvidence $evidence)
    {
        $this->authorizeRun($run);
        abort_if(ActionRegistry::admissionRefusal($run->action_type) !== null, 422, ActionRegistry::admissionRefusal($run->action_type) ?? '');
        abort_unless(ActionRegistry::adapterAvailable($run->action_type), 422, 'This action does not support scheduling.');
        if (! $this->hasSchedulingLineage($run)) {
            // Named refusal: lineage is a property of the proposal, not of the window or identity.
            return redirect()->route('cockpit.index')->with('error', 'This proposal carries no recorded staging lineage, so it cannot be scheduled. Approve it directly instead; no scheduled submission was made.');
        }
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'content_hash' => ['required', 'string', 'size:64'],
            'start' => ['required', 'date_format:Y-m-d\\TH:i'], 'end' => ['required', 'date_format:Y-m-d\\TH:i'],
            'timezone' => ['required', 'string', 'max:100'], 'confirm' => ['accepted'],
            'external_smtp' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'internal_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'external_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confirm_hostname' => ['sometimes', 'string', 'max:255'],
            'confirm_service_name' => ['sometimes', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            // Laravel's validate() redirect flashes all input, including mailbox bodies.
            return redirect()->route('cockpit.index')->with('error', 'Scheduling was refused: invalid confirmation or window. No scheduled submission was made.');
        }
        $input = $validator->validated();
        $human = array_intersect_key($input, array_flip(['external_smtp', 'internal_message', 'external_message', 'confirm_hostname', 'confirm_service_name']));
        if (TacticalPlan::supports($run->action_type)) {
            $evidence = app(TacticalEvidence::class);
        }
        $provenance = $run->proposed_meta['scheduled_provenance'] ?? [];
        $tokenId = ($provenance['kind'] ?? null) === 'mcp' ? ($provenance['token_id'] ?? null) : null;
        try {
            $admission->admit($run->id, (int) auth()->id(), $input['content_hash'], $tokenId,
                str_replace('T', ' ', $input['start']).':00', str_replace('T', ' ', $input['end']).':00',
                $input['timezone'], $human, $evidence);
        } catch (\Throwable) {
            // No raw input or vendor error in flash, logs, or old-input session storage.
            return redirect()->route('cockpit.index')->with('error', 'Scheduling was refused. Verify the window, current permissions and target identity; no scheduled submission was made.');
        }

        return redirect()->route('cockpit.index')->with('success', 'Approval scheduled. It has not executed; permissions and target identity will be rechecked in the window.');
    }

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
