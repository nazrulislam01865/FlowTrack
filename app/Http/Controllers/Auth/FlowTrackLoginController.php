<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FlowTrackLoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower((string) $credentials['email']);
        $throttleKey = Str::transliterate($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ])->status(429);
        }

        if (! Auth::guard('web')->attempt([
            'email' => $email,
            'password' => $credentials['password'],
        ], (bool) ($credentials['remember'] ?? false))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
        $minutes = max(1, (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30)));
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

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Signed in successfully.',
                'expires_at' => $expiresAt->toIso8601String(),
                'session_minutes' => $minutes,
            ]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
