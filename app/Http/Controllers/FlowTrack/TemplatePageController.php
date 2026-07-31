<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TemplatePageController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user() && $request->routeIs('home', 'login')) {
            return redirect()->route('dashboard');
        }

        if (! $request->user() && ! $request->routeIs('home', 'login')) {
            return redirect()->route('login');
        }

        $sessionMinutes = max(1, (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30)));
        $sessionReason = (string) $request->query('session', '');
        $expiresAtTimestamp = (int) $request->session()->get('flowtrack.session_expires_at', 0);
        $expiresAt = $expiresAtTimestamp > 0
            ? Carbon::createFromTimestamp($expiresAtTimestamp)->toIso8601String()
            : null;

        $loginError = $request->session()->get('error');
        if (! $loginError && $sessionReason === 'expired') {
            $loginError = "Your session expired after {$sessionMinutes} minutes without activity. Please sign in again.";
        } elseif (! $loginError && $sessionReason === 'replaced') {
            $loginError = 'This account was signed in from another browser or device. Your previous session has been logged out.';
        }

        $viewData = [
            'flowtrackAuthenticated' => $request->user() !== null,
            'flowtrackSessionReason' => $sessionReason,
            'flowtrackSessionMinutes' => $sessionMinutes,
            'flowtrackSessionExpiresAt' => $expiresAt,
            'flowtrackLoginError' => $loginError,
            'flowtrackSessionConfig' => [
                'minutes' => $sessionMinutes,
                'expiresAt' => $expiresAt,
                'statusEndpoint' => route('flowtrack.session.status'),
                'expiredEndpoint' => route('flowtrack.session.expired'),
            ],
            'flowtrackPusherConfig' => [
                'enabled' => $request->user() !== null && filled(config('services.pusher.key')),
                'key' => config('services.pusher.key'),
                'cluster' => config('services.pusher.cluster', 'ap2'),
                'authEndpoint' => route('flowtrack.pusher.auth'),
                'userId' => $request->user()?->id,
                'csrfToken' => csrf_token(),
            ],
            'flowtrackInitialPath' => '/'.ltrim($request->path(), '/'),
        ];

        return response()
            ->view('flowtrack-template', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
