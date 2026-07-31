<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFlowWorkspaceMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user?->currentWorkspaceMembership();

        abort_unless(
            $user
            && $user->status === 'active'
            && $membership
            && $membership->status === 'active'
            && $membership->role?->is_active
            && $membership->workspace?->is_active,
            403,
            'Your FlowTrack workspace access is inactive or has not been assigned.',
        );

        $workspaceId = (int) $membership->workspace_id;
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if (! $parameter instanceof Model) {
                continue;
            }

            $boundWorkspaceId = $this->workspaceIdFor($parameter);
            abort_if($boundWorkspaceId !== null && $boundWorkspaceId !== $workspaceId, 404);
        }

        return $next($request);
    }

    /**
     * Resolve the workspace behind any FlowTrack route-bound model. This is a
     * final safety net in addition to request rules and action authorization.
     */
    private function workspaceIdFor(Model $model): ?int
    {
        $direct = $model->getAttribute('workspace_id');
        if ($direct !== null) {
            return (int) $direct;
        }

        if (method_exists($model, 'job')) {
            $job = $model->relationLoaded('job') ? $model->getRelation('job') : $model->job()->first(['id', 'workspace_id']);
            if ($job?->workspace_id !== null) {
                return (int) $job->workspace_id;
            }
        }

        if (method_exists($model, 'task')) {
            $task = $model->relationLoaded('task')
                ? $model->getRelation('task')
                : $model->task()->with('job:id,workspace_id')->first();
            if ($task?->job?->workspace_id !== null) {
                return (int) $task->job->workspace_id;
            }
        }

        if (method_exists($model, 'document')) {
            $document = $model->relationLoaded('document')
                ? $model->getRelation('document')
                : $model->document()->first(['id', 'workspace_id']);
            if ($document?->workspace_id !== null) {
                return (int) $document->workspace_id;
            }
        }

        if (method_exists($model, 'workflow')) {
            $workflow = $model->relationLoaded('workflow')
                ? $model->getRelation('workflow')
                : $model->workflow()->first(['id', 'workspace_id']);
            if ($workflow?->workspace_id !== null) {
                return (int) $workflow->workspace_id;
            }
        }

        if (method_exists($model, 'pack')) {
            $pack = $model->relationLoaded('pack')
                ? $model->getRelation('pack')
                : $model->pack()->first(['id', 'workspace_id']);
            if ($pack?->workspace_id !== null) {
                return (int) $pack->workspace_id;
            }
        }

        return null;
    }
}
