<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionExpiredController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
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

        $minutes = (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30));

        return redirect()->route('login', ['session' => 'expired'])
            ->with('error', "Your session expired after {$minutes} minutes without activity. Please sign in again.");
    }
}
