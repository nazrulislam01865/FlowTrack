<?php

namespace App\Http\Middleware;

use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'membership' => fn () => $request->user()?->currentWorkspaceMembership(),
                'permissions' => fn () => $request->user()?->currentWorkspaceMembership()?->role?->permissions?->pluck('slug')->values() ?? [],
                'unreadNotifications' => fn () => $request->user()
                    ? app(NotificationFeedService::class)->unreadCount($request->user())
                    : 0,
                'notificationStatusEndpoint' => fn () => $request->user()
                    ? route('flowtrack.notifications.status')
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'session' => [
                'minutes' => (int) config('session.flowtrack_absolute_lifetime', 30),
                'expiresAt' => fn () => $request->session()->get('flowtrack.session_expires_at')
                    ? \Illuminate\Support\Carbon::createFromTimestamp((int) $request->session()->get('flowtrack.session_expires_at'))->toIso8601String()
                    : null,
                'statusEndpoint' => route('flowtrack.session.status'),
                'expiredEndpoint' => route('flowtrack.session.expired'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
