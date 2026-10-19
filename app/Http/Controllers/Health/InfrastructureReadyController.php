<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Infrastructure\InfrastructureHealthService;
use Illuminate\Http\JsonResponse;

final class InfrastructureReadyController extends Controller
{
    public function __invoke(InfrastructureHealthService $health): JsonResponse
    {
        $report = $health->report();
        $payload = ['ok' => $report['ok']];

        if ((bool) config('scalability.health.expose_details', false)) {
            $payload['checks'] = $report['checks'];
        }

        return response()->json($payload, $report['ok'] ? 200 : 503, [
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
