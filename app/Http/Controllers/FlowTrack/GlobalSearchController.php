<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, FlowAuthorizationService $authorization): Response
    {
        $query = trim((string) $request->query('q', ''));
        $user = $request->user();

        if (mb_strlen($query) < 2) {
            return $this->response($query);
        }

        $jobs = $this->jobs($query, $user, $authorization);
        $tasks = $this->tasks($query, $user, $authorization);
        $clients = $this->clients($query, $user, $authorization);
        $documents = $this->documents($query, $user, $authorization);

        return $this->response($query, compact('jobs', 'tasks', 'clients', 'documents'));
    }

    private function jobs(string $search, User $user, FlowAuthorizationService $authorization): Collection
    {
        if (! $authorization->allows($user, 'jobs', 'view')) {
            return collect();
        }

        return $authorization->applyJobVisibility(FlowJob::query(), $user, 'jobs')
            ->with(['client:id,name', 'currentPhase:id,name,short_name'])
            ->where(fn (Builder $jobs) => $jobs
                ->where('code', 'like', "%{$search}%")
                ->orWhere('order_code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%")))
            ->limit(12)
            ->get(['id', 'client_id', 'current_phase_id', 'code', 'order_code', 'title', 'health', 'progress']);
    }

    private function tasks(string $search, User $user, FlowAuthorizationService $authorization): Collection
    {
        if (! $authorization->allows($user, 'tasks', 'view')) {
            return collect();
        }

        return $authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with(['job:id,code,title,client_id', 'job.client:id,name', 'assignee:id,name,initials', 'status:id,name'])
            ->where(fn (Builder $tasks) => $tasks
                ->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%"))
            ->limit(12)
            ->get(['id', 'flow_job_id', 'assignee_id', 'task_status_id', 'code', 'title', 'due_date']);
    }

    private function clients(string $search, User $user, FlowAuthorizationService $authorization): Collection
    {
        if (! $authorization->allows($user, 'clients', 'view')) {
            return collect();
        }

        $canViewContact = $authorization->canViewSensitiveField($user, 'client_contact_details');
        $clients = $authorization->applyClientVisibility(Client::query(), $user)
            ->where(function (Builder $clients) use ($search, $canViewContact): void {
                $clients->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->when($canViewContact, fn (Builder $query) => $query
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            })
            ->limit(12)
            ->get(['id', 'code', 'name', 'country', 'contact_name']);

        if (! $canViewContact) {
            $clients->each(fn (Client $client) => $client->setAttribute('contact_name', null));
        }

        return $clients;
    }

    private function documents(string $search, User $user, FlowAuthorizationService $authorization): Collection
    {
        if (! $authorization->allows($user, 'documents', 'view')) {
            return collect();
        }

        return $authorization->applyDocumentVisibility(FlowDocument::query(), $user)
            ->with(['job:id,code,title'])
            ->where(fn (Builder $documents) => $documents
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"))
            ->limit(12)
            ->get(['id', 'flow_job_id', 'code', 'name', 'is_final']);
    }

    private function response(string $query, array $results = []): Response
    {
        return Inertia::render('flowtrack/Search/Index', [
            'query' => $query,
            'results' => array_merge([
                'jobs' => [],
                'tasks' => [],
                'clients' => [],
                'documents' => [],
            ], $results),
        ]);
    }
}
