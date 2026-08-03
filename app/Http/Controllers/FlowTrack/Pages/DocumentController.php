<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DocumentController extends AbstractPageController
{
    public function index(Request $request): Response
    {
        return $this->pages->render($request, 'documents', ['documents', 'view']);
    }
}
