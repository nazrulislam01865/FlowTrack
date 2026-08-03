<script>
window.FLOWTRACK_AUTHENTICATED = {{ Illuminate\Support\Js::from((bool) $flowtrackAuthenticated) }};
window.FLOWTRACK_SESSION = {{ Illuminate\Support\Js::from($flowtrackSessionConfig ?? []) }};
window.FLOWTRACK_PUSHER = {{ Illuminate\Support\Js::from($flowtrackPusherConfig ?? []) }};
window.FLOWTRACK_INITIAL_PATH = {{ Illuminate\Support\Js::from($flowtrackInitialPath ?? ('/'.ltrim(request()->path(), '/'))) }};
window.FLOWTRACK_BRANDING = {{ Illuminate\Support\Js::from($flowtrackBranding ?? []) }};
window.FLOWTRACK_ACTIVE_PAGE = {{ Illuminate\Support\Js::from($flowtrackPage ?? 'dashboard') }};
window.FLOWTRACK_PAGE_ROUTES = {{ Illuminate\Support\Js::from($flowtrackPageRoutes ?? []) }};
</script>
<script defer src="{{ asset('flowtrack/searchable-dropdown.js') }}"></script>
<script defer src="{{ asset('flowtrack/interaction-feedback.js') }}?v={{ file_exists(public_path('flowtrack/interaction-feedback.js')) ? filemtime(public_path('flowtrack/interaction-feedback.js')) : 1 }}"></script>
<script defer src="{{ asset('flowtrack/loader.js') }}"></script>

@if($flowtrackAuthenticated && filled(config('services.pusher.key')))
<script
    async
    src="https://js.pusher.com/8.4.0/pusher.min.js"
    onload="window.dispatchEvent(new Event('flowtrack:pusher-ready'))"
></script>
@endif
