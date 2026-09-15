<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Email\EmailResolutionService;
use Illuminate\Http\Request;

class EmailResolutionController extends Controller
{
    public function approve(Request $request, int $proposal, EmailResolutionService $service)
    {
        abort_unless($service->canApprove($request->user()), 403);
        $result = $service->approve($proposal, $request->user());
        if ($request->expectsJson()) {
            return response()->json($result, isset($result['error']) ? 409 : 200);
        }

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? $result['message']);
    }

    public function deny(Request $request, int $proposal, EmailResolutionService $service)
    {
        abort_unless($service->canApprove($request->user()), 403);
        $result = $service->deny($proposal, $request->user());
        if ($request->expectsJson()) {
            return response()->json($result, isset($result['error']) ? 409 : 200);
        }

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? $result['message']);
    }
}
