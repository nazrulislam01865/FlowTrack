<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CancelledOrdersController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(auth()->user()?->canAccess('jobs.view'), 403);

        return view('pages.cancelled-orders', [
            'title' => 'Cancelled Orders',
        ]);
    }
}
