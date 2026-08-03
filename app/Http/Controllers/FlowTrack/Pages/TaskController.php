<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TaskController extends AbstractPageController
{
    public function show(Request $request, string $record): Response
    {
        return $this->pages->render($request, 'board', ['tasks', 'view']);
    }
}
