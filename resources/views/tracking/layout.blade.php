<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Order tracking — '.($branding['name'] ?? 'FlowTrack'))</title>
    <link rel="icon" href="{{ ($branding['favicon_url'] ?? asset('images/flowtrack-favicon.svg')) }}">
    <script
        src="{{ asset('js/flowtrack-image-fallback.js') }}?v={{ \App\Support\FrontendBuildVersion::current() }}"
        data-fallback-src="{{ asset('images/flowtrack-image-fallback.svg') }}"
    ></script>
    @vite(['resources/css/tracking.css', 'resources/js/order-tracking.js'])
    <link rel="stylesheet" href="{{ asset('css/flowtrack-system-branding.css') }}?v=20261019">
</head>
<body class="ft-tracking-page">
    @yield('content')
    <x-system-footer :branding="$branding" />
</body>
</html>
