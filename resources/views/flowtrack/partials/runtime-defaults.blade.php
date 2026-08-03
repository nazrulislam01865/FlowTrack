@php
    // Runtime values are supplied by the route-level page response service. The fallbacks keep
    // the view safe when rendered directly by tests, previews, or cached views.
    $flowtrackAuthenticated = $flowtrackAuthenticated ?? auth()->check();
    $flowtrackPage = $flowtrackPage ?? \App\Support\FlowTrackPage::fromRoute(request()->route()?->getName());
    $flowtrackPageRoutes = $flowtrackPageRoutes ?? \App\Support\FlowTrackPage::paths();
    $flowtrackSessionReason = $flowtrackSessionReason ?? request()->query('session');
    $flowtrackSessionMinutes = $flowtrackSessionMinutes ?? max(1, (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30)));
    $flowtrackSessionExpiresAt = $flowtrackSessionExpiresAt ?? null;
    $flowtrackLoginError = $flowtrackLoginError ?? session('error') ?? null;
    $flowtrackSessionConfig = $flowtrackSessionConfig ?? [
        'minutes' => $flowtrackSessionMinutes,
        'expiresAt' => $flowtrackSessionExpiresAt,
        'statusEndpoint' => route('flowtrack.session.status'),
        'expiredEndpoint' => route('flowtrack.session.expired'),
    ];
    $flowtrackPusherConfig = $flowtrackPusherConfig ?? [
        'enabled' => auth()->check() && filled(config('services.pusher.key')),
        'key' => config('services.pusher.key'),
        'cluster' => config('services.pusher.cluster', 'ap2'),
        'authEndpoint' => route('flowtrack.pusher.auth'),
        'userId' => auth()->id(),
        'csrfToken' => csrf_token(),
        'pollIntervalMs' => (int) config('flowtrack.realtime_poll_interval_ms', 5000),
    ];
    $flowtrackBranding = $flowtrackBranding ?? [
        'workspaceId' => null,
        'workspaceName' => 'FlowTrack',
        'logoUrl' => null,
        'faviconUrl' => asset('favicon.ico'),
        'hasCustomLogo' => false,
        'hasCustomFavicon' => false,
    ];
@endphp
