<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TechnicianRun;
use App\Services\Technician\Scheduled\MailboxEvidence;
use App\Services\Technician\Scheduled\MailboxPlan;
use App\Services\Technician\Scheduled\ScheduledAdmission;
use App\Services\Technician\Scheduled\ScheduledCoordinator;
use App\Services\Technician\Scheduled\ScheduledPolicy;
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

    public function create(TechnicianRun $run)
    {
        $this->authorizeRun($run);
        abort_unless(config('scheduled_approvals.enabled') && MailboxPlan::supports($run->action_type), 422, 'Scheduling is disabled or unsupported for this action.');
        abort_unless($run->state === \App\Enums\TechnicianRunState::AwaitingApproval, 409, 'This proposal is no longer awaiting approval.');

        return view('cockpit.schedule', ['run' => $run]);
    }

    public function store(Request $request, TechnicianRun $run, ScheduledAdmission $admission, MailboxEvidence $evidence)
    {
        $this->authorizeRun($run);
        abort_unless(MailboxPlan::supports($run->action_type), 422, 'This action does not support scheduling.');
        $input = $request->validate([
            'content_hash' => ['required', 'string', 'size:64'],
            'start' => ['required', 'date_format:Y-m-d\\TH:i'], 'end' => ['required', 'date_format:Y-m-d\\TH:i'],
            'timezone' => ['required', 'string', 'max:100'], 'confirm' => ['accepted'],
            'external_smtp' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'internal_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'external_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $human = array_intersect_key($input, array_flip(['external_smtp', 'internal_message', 'external_message']));
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
        $this->authorizeRun($run);
        $row = DB::table('scheduled_authorizations')->where('run_id', $run->id)->orderByDesc('revision')->first();
        abort_unless($row && (int) $row->approver_user_id === (int) auth()->id(), 403);
        $ok = $coordinator->cancel($row->id, (int) auth()->id());

        return redirect()->route('cockpit.index')->with($ok ? 'success' : 'error', $ok
            ? 'Scheduled approval cancelled. Nothing will be submitted for this authorization.'
            : 'Cancellation lost the dispatch race or this authorization is already terminal. Inspect its result; do not retry the action.');
    }
}
