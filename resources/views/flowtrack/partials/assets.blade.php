<script src="{{ asset('flowtrack-browser-compatibility.js') }}?v={{ filemtime(public_path('flowtrack-browser-compatibility.js')) }}"></script>
<script src="{{ asset('flowtrack-prototype-fallback.js') }}?v={{ filemtime(public_path('flowtrack-prototype-fallback.js')) }}"></script>
<script>
window.FLOWTRACK_AUTHENTICATED = {{ Illuminate\Support\Js::from((bool) $flowtrackAuthenticated) }};
window.FLOWTRACK_SESSION = {{ Illuminate\Support\Js::from($flowtrackSessionConfig ?? []) }};
window.FLOWTRACK_PUSHER = {{ Illuminate\Support\Js::from($flowtrackPusherConfig ?? []) }};
window.FLOWTRACK_INITIAL_PATH = {{ Illuminate\Support\Js::from($flowtrackInitialPath ?? ('/'.ltrim(request()->path(), '/'))) }};
window.FLOWTRACK_BRANDING = {{ Illuminate\Support\Js::from($flowtrackBranding ?? []) }};
</script>
@livewireScripts
<script src="{{ asset('flowtrack-searchable-dropdown.js') }}?v={{ filemtime(public_path('flowtrack-searchable-dropdown.js')) }}"></script>
<script src="{{ asset('flowtrack-dynamic.js') }}?v={{ filemtime(public_path('flowtrack-dynamic.js')) }}"></script>
<script src="{{ asset('flowtrack-livewire.js') }}?v={{ filemtime(public_path('flowtrack-livewire.js')) }}"></script>

@if($flowtrackAuthenticated && filled(config('services.pusher.key')))
<script
    async
    src="https://js.pusher.com/8.4.0/pusher.min.js"
    onload="window.dispatchEvent(new Event('flowtrack:pusher-ready'))"
></script>
@endif
