<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TemplateSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionToken = (string) $request->session()->get('flowtrack.active_session_token', '');

        if ($user && $sessionToken !== '' && hash_equals((string) $user->active_session_token, $sessionToken)) {
            $user->forceFill([
                'active_session_token' => null,
                'active_session_started_at' => null,
            ])->saveQuietly();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out successfully.']);
    }
}
