@include('flowtrack.partials.runtime-defaults')
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>FlowTrack — Promotional Products Workflow Prototype</title>
<link id="flowtrackFavicon" rel="icon" href="{{ $flowtrackBranding['faviconUrl'] ?? asset('favicon.ico') }}" />
@livewireStyles
@vite('resources/css/flowtrack/app.css')
</head>
<body>
@include('flowtrack.auth.login')
@include('flowtrack.auth.loading')
@include('flowtrack.layouts.workspace')
@include('flowtrack.partials.overlays')
@include('flowtrack.partials.assets')
</body>
</html>
