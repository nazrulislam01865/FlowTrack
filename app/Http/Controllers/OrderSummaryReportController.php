<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class OrderSummaryReportController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(auth()->user()?->canAccess('jobs.view'), 403);

        return view('pages.order-summary-report', ['title' => 'Order Summary Report']);
    }
}
