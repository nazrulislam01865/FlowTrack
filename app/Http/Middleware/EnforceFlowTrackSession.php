<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceFlowTrackSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $sessionToken = (string) $request->session()->get('flowtrack.active_session_token', '');
        $startedAt = (int) $request->session()->get('flowtrack.session_started_at', 0);
        $minutes = (int) config('session.flowtrack_absolute_lifetime', 30);

        // Safely adopt sessions that existed before this feature was deployed.
        if ($sessionToken === '' && blank($user->active_session_token)) {
            $sessionToken = Str::random(64);
            $startedAt = now()->timestamp;
            $request->session()->put([
                'flowtrack.active_session_token' => $sessionToken,
                'flowtrack.session_started_at' => $startedAt,
                'flowtrack.session_expires_at' => now()->addMinutes($minutes)->timestamp,
            ]);
            $user->forceFill([
                'active_session_token' => $sessionToken,
                'active_session_started_at' => now(),
            ])->saveQuietly();
        }

        if ($startedAt <= 0 || now()->timestamp >= ($startedAt + ($minutes * 60))) {
            return $this->logoutResponse(
                $request,
                'Your 30-minute session has expired. Please sign in again.',
                'expired',
            );
        }

        if ($sessionToken === '' || ! hash_equals((string) $user->active_session_token, $sessionToken)) {
            return $this->logoutResponse(
                $request,
                'This account was signed in from another browser or device. Your previous session has been logged out.',
                'replaced',
            );
        }

        return $next($request);
    }

    private function logoutResponse(Request $request, string $message, string $reason): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'reason' => $reason,
                'redirect' => route('login', ['session' => $reason]),
            ], 401);
        }

        return redirect()->route('login', ['session' => $reason])->with('error', $message);
    }
}
