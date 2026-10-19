<?php

namespace App\Http\Controllers;

use App\Services\OrderWorkflowSetupService;
use Illuminate\Http\RedirectResponse;

/**
 * Backward-compatible entry point for old bookmarks.
 * Order workflows now live in the shared Workflow Setup screen.
 */
class OrderWorkflowSetupController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $workflowId = OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        return redirect()->route('workflow.setup', $workflowId ? ['workflow' => (int) $workflowId] : []);
    }
}
