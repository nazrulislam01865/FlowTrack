<?php

namespace App\Http\Middleware;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlowRouteAccessMiddleware
{
    public function __construct(private readonly FlowAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $requirements = $this->requirements((string) $request->route()?->getName());
        if ($requirements === null) {
            return $next($request);
        }

        foreach ($requirements as $group) {
            $matched = false;
            foreach ($group as [$module, $action]) {
                if ($this->authorization->allows($user, $module, $action)) {
                    $matched = true;
                    break;
                }
            }
            abort_unless($matched, 403);
        }

        return $next($request);
    }

    /**
     * Each outer entry is mandatory (AND); entries inside it are alternatives (OR).
     * Record-level scope is still enforced by repositories/controllers after this
     * route-level Role Matrix gate.
     *
     * @return array<int, array<int, array{0:string,1:string}>>|null
     */
    private function requirements(string $name): ?array
    {
        if (str_starts_with($name, 'flowtrack.template-api.clients.')) {
            return $this->crudRequirement($name, 'clients');
        }
        if (str_starts_with($name, 'flowtrack.template-api.jobs.')) {
            if (str_contains($name, '.documents.') || str_contains($name, '.attachments.')) {
                return [[['documents', 'create']], [['documents', 'link']]];
            }
            if (str_contains($name, '.tasks.')) {
                return (str_ends_with($name, '.store') || str_ends_with($name, '.sync'))
                    ? [[['tasks', 'create']]]
                    : [[['tasks', 'view']]];
            }
            if (str_contains($name, '.comments.')) {
                return [[['jobs', 'view']]];
            }
            return $this->crudRequirement($name, 'jobs');
        }
        if (str_starts_with($name, 'flowtrack.template-api.tasks.')) {
            return $this->crudRequirement($name, 'tasks');
        }
        if (str_starts_with($name, 'flowtrack.template-api.documents.')) {
            if (str_ends_with($name, '.store') || str_contains($name, 'link-existing')) {
                return [[['documents', 'create']], [['documents', 'link']]];
            }
            return $this->crudRequirement($name, 'documents');
        }
        if (str_starts_with($name, 'flowtrack.template-api.master-data.')) {
            return $this->crudRequirement($name, 'masterdata');
        }
        if (str_starts_with($name, 'flowtrack.template-api.task-packs.')
            || str_starts_with($name, 'flowtrack.template-api.workflows.')
            || str_starts_with($name, 'flowtrack.template-api.workflow-phases.')) {
            return $this->crudRequirement($name, 'workflow');
        }
        if (str_starts_with($name, 'flowtrack.template-api.access.')) {
            if (str_ends_with($name, '.store')) return [[['users', 'create']]];
            if (str_ends_with($name, '.destroy')) return [[['users', 'delete']]];
            return [[['users', 'edit_all'], ['users', 'manage']]];
        }
        if (str_contains($name, 'bootstrap.segment') || str_contains($name, 'lists.index')) {
            return null; // Segment is dynamic; repository visibility performs the record-safe gate.
        }

        return null;
    }

    /** @return array<int, array<int, array{0:string,1:string}>> */
    private function crudRequirement(string $name, string $module): array
    {
        if (str_contains($name, 'next-code')) return [[[$module, 'create']]];
        if (str_ends_with($name, '.store')) return [[[$module, 'create']]];
        if (str_ends_with($name, '.destroy')) return [[[$module, 'delete']]];
        if (str_ends_with($name, '.show') || str_ends_with($name, '.index')) return [[[$module, 'view']]];
        if (str_ends_with($name, '.phase')) return [[[$module, 'edit_own'], [$module, 'edit_all'], [$module, 'override']]];
        if (str_contains($name, 'bulk')) return [[[$module, 'edit_own'], [$module, 'edit_all'], [$module, 'manage'], [$module, 'override']]];
        return [[[$module, 'edit_own'], [$module, 'edit_all'], [$module, 'manage']]];
    }
}
