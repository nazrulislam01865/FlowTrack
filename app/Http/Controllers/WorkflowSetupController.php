<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkflowSetupController extends Controller
{
    public function index(): View
    {
        return view('pages.workflow-setup');
    }

    public function create(Request $request): View
    {
        return view('pages.workflow-form', [
            'workflowId' => null,
            'sourceWorkflowId' => $request->integer('source') ?: null,
        ]);
    }

    public function edit(int $workflow): View
    {
        return view('pages.workflow-form', [
            'workflowId' => $workflow,
            'sourceWorkflowId' => null,
        ]);
    }
}
