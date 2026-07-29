<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SessionStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expiresAt = (int) $request->session()->get('flowtrack.session_expires_at', 0);

        return response()->json([
            'active' => true,
            'expires_at' => $expiresAt > 0 ? Carbon::createFromTimestamp($expiresAt)->toIso8601String() : null,
            'remaining_seconds' => $expiresAt > 0 ? max(0, $expiresAt - now()->timestamp) : null,
        ]);
    }
}
