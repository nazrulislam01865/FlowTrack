<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleLoginSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (!$user) return $next($request);

        $sessionToken = (string) $request->session()->get('flowtrack_login_token', '');
        $cacheKey = 'flowtrack:active-login:'.$user->id;
        $activeToken = (string) Cache::get($cacheKey, '');

        if ($sessionToken === '') {
            $sessionToken = Str::random(64);
            $request->session()->put('flowtrack_login_token', $sessionToken);
            Cache::put($cacheKey, $sessionToken, now()->addDay());
            return $next($request);
        }

        if ($activeToken === '') {
            Cache::put($cacheKey, $sessionToken, now()->addDay());
            return $next($request);
        }

        if (!hash_equals($activeToken, $sessionToken)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Another device logged in with the same user ID and password. This session has been logged out.';
            if ($request->expectsJson() || $request->ajax() || $request->header('X-Livewire')) {
                return response()->json(['message' => $message, 'redirect' => route('login', ['reason' => 'other-device'])], 409);
            }

            return redirect()->route('login', ['reason' => 'other-device']);
        }

        return $next($request);
    }
}
