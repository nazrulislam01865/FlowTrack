<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
final class DashboardPriorityWorkQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access) {}
    public function handle(User $actor, DashboardFilterData $f): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return [
            'priorityJobs'=>$this->dashboard->priorityJobs($actor,$f->clientId,$f->departmentId,$f->rangeDays),
            'priorityInquiries'=>$this->dashboard->priorityInquiries($actor,$f->clientId,$f->departmentId,$f->rangeDays),
            'priorityTasks'=>$this->dashboard->priorityTasks($actor,$f->clientId,$f->departmentId,$f->rangeDays),
        ];
    }

    public function handleSelected(User $actor, DashboardFilterData $f, string $tab): \Illuminate\Support\Collection {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        return match ($tab) {
            'orders' => $this->dashboard->priorityJobs($actor, $f->clientId, $f->departmentId, $f->rangeDays),
            'inquiries' => $this->dashboard->priorityInquiries($actor, $f->clientId, $f->departmentId, $f->rangeDays),
            'tasks' => $this->dashboard->priorityTasks($actor, $f->clientId, $f->departmentId, $f->rangeDays),
            default => abort(422, 'Unsupported Priority work tab.'),
        };
    }
}
