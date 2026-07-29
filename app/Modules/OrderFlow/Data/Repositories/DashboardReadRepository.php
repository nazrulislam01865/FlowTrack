<?php

namespace App\Modules\OrderFlow\Data\Repositories;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowActivity;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Database\Eloquent\Builder;

class DashboardReadRepository
{
    public function __construct(private readonly FlowAuthorizationService $authorization)
    {
    }

    public function get(User $user): array
    {
        abort_unless($this->authorization->allows($user, 'dashboard', 'view'), 403);

        $jobs = app(JobRepository::class)->visibleQuery($user);
        $tasks = $this->authorization->applyTaskVisibility(FlowTask::query(), $user);
        $canViewFinancials = $this->authorization->allows($user, 'invoice', 'view')
            && $this->authorization->canViewSensitiveField($user, 'invoice_amount');
        $financialJobs = FlowJob::query()->visibleTo($user);
        if (! $canViewFinancials) {
            $financialJobs->whereRaw('1 = 0');
        }

        $metrics = [
            'activeJobs' => (clone $jobs)->where('status', '!=', 'completed')->count(),
            'needsAttention' => (clone $jobs)->whereIn('health', ['needs_attention', 'at_risk', 'delayed', 'blocked'])->count(),
            'overdueTasks' => (clone $tasks)->whereDate('due_date', '<', today())->whereNull('completed_at')->count(),
            'blockedTasks' => (clone $tasks)->where(function (Builder $query) {
                $query->where('attention_required', true)
                    ->orWhereNotNull('blocker_reason')
                    ->orWhereHas('status', fn (Builder $status) => $status->where('code', 'BLK'));
            })->whereNull('completed_at')->count(),
            'shippingNow' => (clone $jobs)->whereHas('currentPhase', fn (Builder $phase) => $phase->where('short_name', 'Shipment'))->count(),
            'outstanding' => $canViewFinancials
                ? Client::query()->whereIn('id', (clone $financialJobs)->select('client_id'))->sum('outstanding_balance')
                : null,
            'canViewFinancials' => $canViewFinancials,
        ];

        $attentionJobs = (clone $jobs)->with(['client', 'currentPhase', 'coordinator'])->where('attention_required', true)->orderBy('delivery_date')->limit(6)->get();
        $upcomingDeliveries = (clone $jobs)->with('client')->where('status', '!=', 'completed')->whereNotNull('delivery_date')->orderBy('delivery_date')->limit(6)->get();

        if (! $canViewFinancials) {
            $attentionJobs->each->makeHidden(['quoted_amount', 'currency_id']);
            $upcomingDeliveries->each->makeHidden(['quoted_amount', 'currency_id']);
        }

        return [
            'metrics' => $metrics,
            'attentionJobs' => $attentionJobs,
            'phaseCounts' => (clone $jobs)->join('workflow_phases', 'flow_jobs.current_phase_id', '=', 'workflow_phases.id')->selectRaw('workflow_phases.short_name as phase, count(*) as total')->groupBy('workflow_phases.short_name')->pluck('total', 'phase'),
            'upcomingDeliveries' => $upcomingDeliveries,
            'workload' => (clone $tasks)->whereNull('completed_at')->join('users', 'flow_tasks.assignee_id', '=', 'users.id')->selectRaw('users.id, users.name, users.initials, count(*) as total')->groupBy('users.id', 'users.name', 'users.initials')->orderByDesc('total')->limit(6)->get(),
            'activities' => $this->visibleActivities($user, $jobs),
        ];
    }

    private function visibleActivities(User $user, Builder $visibleJobs)
    {
        $query = FlowActivity::query()
            ->with('user')
            ->where('workspace_id', $user->currentWorkspaceId());

        if ($this->authorization->scope($user, 'jobs') !== 'all_records') {
            $jobIds = (clone $visibleJobs)->select('flow_jobs.id');
            $taskIds = FlowTask::query()->whereIn('flow_job_id', (clone $jobIds))->select('flow_tasks.id');
            $documentIds = FlowDocument::query()->whereIn('flow_job_id', (clone $jobIds))->select('flow_documents.id');

            $query->where(function (Builder $activity) use ($jobIds, $taskIds, $documentIds) {
                $activity->where(fn (Builder $subject) => $subject->where('subject_type', FlowJob::class)->whereIn('subject_id', (clone $jobIds)))
                    ->orWhere(fn (Builder $subject) => $subject->where('subject_type', FlowTask::class)->whereIn('subject_id', $taskIds))
                    ->orWhere(fn (Builder $subject) => $subject->where('subject_type', FlowDocument::class)->whereIn('subject_id', $documentIds));
            });
        }

        return $query->latest()->limit(6)->get();
    }
}
