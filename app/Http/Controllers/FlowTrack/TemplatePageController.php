<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TemplatePageController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->routeIs('login') && $request->user()) {
            return redirect()->route('dashboard');
        }

        if (! $request->user() && $request->routeIs('dashboard', 'flowtrack.prototype', 'flowtrack.dashboard')) {
            return redirect()->route('login');
        }

        return response()
            ->view('flowtrack-template')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
