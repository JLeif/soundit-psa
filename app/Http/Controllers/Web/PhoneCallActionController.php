<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PhoneCallActionService;
use Illuminate\Http\Request;

/**
 * Cockpit approval for held call-log actions (billability, block/allow caller).
 * Mirrors PhoneCallResolutionController: the service owns every guard, and
 * approval revalidates the target rather than applying a stale payload.
 */
class PhoneCallActionController extends Controller
{
    public function approve(Request $request, int $proposal, PhoneCallActionService $service)
    {
        abort_unless($service->canApprove($request->user()), 403);
        $result = $service->approve($proposal, $request->user());
        if ($request->expectsJson()) {
            return response()->json($result, isset($result['error']) ? 409 : 200);
        }

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? $result['message'] ?? 'Call action applied.');
    }

    public function deny(Request $request, int $proposal, PhoneCallActionService $service)
    {
        abort_unless($service->canApprove($request->user()), 403);
        $result = $service->deny($proposal, $request->user());
        if ($request->expectsJson()) {
            return response()->json($result, isset($result['error']) ? 409 : 200);
        }

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? $result['message'] ?? 'Call action denied.');
    }
}
