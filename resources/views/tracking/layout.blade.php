<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Order tracking — STEP PROMO')</title>
    <link rel="icon" href="{{ asset('images/step-promo/step-promo-icon.webp') }}">
    <script
        src="{{ asset('js/flowtrack-image-fallback.js') }}?v={{ \App\Support\FrontendBuildVersion::current() }}"
        data-fallback-src="{{ asset('images/flowtrack-image-fallback.svg') }}"
    ></script>
    @vite(['resources/css/tracking.css', 'resources/js/order-tracking.js'])
</head>
<body class="ft-tracking-page">
    @yield('content')
</body>
</html>
