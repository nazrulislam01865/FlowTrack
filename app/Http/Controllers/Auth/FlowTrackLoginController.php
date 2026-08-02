<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Business\Services\FlowLandingPageResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FlowTrackLoginController extends Controller
{
    public function __invoke(Request $request, FlowLandingPageResolver $landing): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'replace_session' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower((string) $credentials['email']);
        $throttleKey = Str::transliterate($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ])->status(429);
        }

        $authCredentials = [
            'email' => $email,
            'password' => $credentials['password'],
        ];

        // Validate the password before exposing whether this account currently
        // has another live FlowTrack session. This keeps the session warning
        // useful without leaking account state to unauthenticated callers.
        if (! Auth::guard('web')->validate($authCredentials)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user || $user->status !== 'active') {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => 'This FlowTrack account is inactive or does not have active workspace access.',
            ]);
        }

        $membership = $user->currentWorkspaceMembership();
        if (! $membership
            || $membership->status !== 'active'
            || ! $membership->role?->is_active
            || ! $membership->workspace?->is_active) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => 'This FlowTrack account is inactive or does not have active workspace access.',
            ]);
        }

        $minutes = max(1, (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30)));
        $activeSessionIsFresh = $user?->active_session_token
            && $user->active_session_started_at
            && $user->active_session_started_at->greaterThan(now()->subMinutes($minutes));

        if ($activeSessionIsFresh && ! (bool) ($credentials['replace_session'] ?? false)) {
            RateLimiter::clear($throttleKey);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Another active session was found.',
                    'active_session' => true,
                    'requires_session_replacement' => true,
                ], 409);
            }

            return redirect()->route('login')->with([
                'flowtrack_replace_session' => true,
                'error' => 'Your password was verified. Sign in once more to replace the other active session.',
            ]);
        }

        // A stale token should never force the user through the replacement
        // confirmation. Clear it before creating the new authoritative session.
        if ($user && filled($user->active_session_token) && ! $activeSessionIsFresh) {
            $user->forceFill([
                'active_session_token' => null,
                'active_session_started_at' => null,
            ])->saveQuietly();
        }

        if (! Auth::guard('web')->attempt($authCredentials, (bool) ($credentials['remember'] ?? false))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
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

        $destination = $landing->resolve($user);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Signed in successfully.',
                'expires_at' => $expiresAt->toIso8601String(),
                'session_minutes' => $minutes,
                'replaced_existing_session' => (bool) ($activeSessionIsFresh && ($credentials['replace_session'] ?? false)),
                'redirect_page' => $destination['page'],
                'redirect_to' => $destination['url'],
            ]);
        }

        return redirect($destination['url']);
    }
}
