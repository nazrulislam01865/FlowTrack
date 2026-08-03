<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render($request, 'reports', ['reports', 'view']);
    }
}
