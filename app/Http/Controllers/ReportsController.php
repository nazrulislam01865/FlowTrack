<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ReportsController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.reports', ['title' => 'Inquiry Intelligence']);
    }
}
