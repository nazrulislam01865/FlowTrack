<?php

namespace App\Http\Controllers\Telemetry;

use App\Services\Observability\OperationsMetrics;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RealtimeTelemetryController
{
    public function __invoke(Request $request, OperationsMetrics $metrics): Response
    {
        abort_unless((bool) config('observability.enabled', false) && (bool) config('observability.realtime_client_endpoint', true), 404);

        $validated = $request->validate([
            'event' => ['required', 'string', 'in:connected,reconnect,error,unavailable,disconnected'],
        ]);

        $metrics->recordRealtimeClient((string) $validated['event']);

        return response()->noContent();
    }
}
