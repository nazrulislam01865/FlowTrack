<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function create(): Response
    {
        return response()->view('auth.login')->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        $key = 'flowtrack-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['email' => "Too many sign-in attempts. Try again in {$seconds} seconds."])->onlyInput('email');
        }

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['email'=>'The provided credentials do not match our records.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        if (!$request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return back()->withErrors(['email'=>'This account is inactive.']);
        }

        $token = Str::random(64);
        $request->session()->put('flowtrack_login_token', $token);
        Cache::put('flowtrack:active-login:'.$request->user()->id, $token, now()->addDay());

        // Product-only roles cannot open the management dashboard. Send them
        // directly to the catalogue instead of a 403 after successful sign-in.
        // Users who can open Dashboard retain the existing intended redirect.
        if (! $request->user()->canAccess('dashboard.view')
            && $request->user()->canModule('catalog_products', 'view')) {
            return redirect()->route('master-data', ['group' => 'product']);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = Auth::id();
        $sessionToken = (string) $request->session()->get('flowtrack_login_token', '');
        if ($userId && $sessionToken !== '') {
            $key = 'flowtrack:active-login:'.$userId;
            $active = (string) Cache::get($key, '');
            if ($active !== '' && hash_equals($active, $sessionToken)) Cache::forget($key);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
