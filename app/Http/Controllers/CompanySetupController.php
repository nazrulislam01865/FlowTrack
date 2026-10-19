<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CompanySetupController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.company-setup');
    }
}
