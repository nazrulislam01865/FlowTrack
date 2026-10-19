<?php

namespace App\Http\Controllers;

use App\Services\OrderSummaryReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderSummaryExportController extends Controller
{
    public function __invoke(Request $request, OrderSummaryReportService $service): StreamedResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'warehouse' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', 'in:Y,N'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'client_ids' => ['nullable', 'array', 'max:500'],
            'client_ids.*' => ['integer', 'min:1', 'distinct'],
            'quick' => ['nullable', 'in:all,urgent,awaiting,overdue'],
        ]);

        return $service->export($request->user(), $data);
    }
}
