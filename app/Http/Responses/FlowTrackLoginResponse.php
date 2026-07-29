<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class FlowTrackLoginResponse implements LoginResponseContract
{
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $minutes = (int) config('session.flowtrack_absolute_lifetime', 30);
        $token = Str::random(64);
        $startedAt = now();
        $expiresAt = $startedAt->copy()->addMinutes($minutes);

        $request->session()->put([
            'flowtrack.active_session_token' => $token,
            'flowtrack.session_started_at' => $startedAt->timestamp,
            'flowtrack.session_expires_at' => $expiresAt->timestamp,
        ]);

        $user?->forceFill([
            'active_session_token' => $token,
            'active_session_started_at' => $startedAt,
        ])->saveQuietly();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Signed in successfully.',
                'expires_at' => $expiresAt->toIso8601String(),
                'session_minutes' => $minutes,
            ]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
