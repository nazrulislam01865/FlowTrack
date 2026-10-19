<?php

namespace App\Http\Controllers;

use App\Services\CancelledOrderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CancelledOrdersExportController extends Controller
{
    public function __invoke(Request $request, CancelledOrderService $service): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'phase_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:60'],
            'cancelled_by' => ['nullable', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $service->export(auth()->user(), $filters);
    }
}
