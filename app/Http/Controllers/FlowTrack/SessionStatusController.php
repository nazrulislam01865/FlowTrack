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

        $expiresAtIso = $expiresAt > 0 ? Carbon::createFromTimestamp($expiresAt)->toIso8601String() : null;

        return response()->json([
            'authenticated' => true,
            'active' => true,
            'expiresAt' => $expiresAtIso,
            'expires_at' => $expiresAtIso,
            'remaining_seconds' => $expiresAt > 0 ? max(0, $expiresAt - now()->timestamp) : null,
        ]);
    }
}
