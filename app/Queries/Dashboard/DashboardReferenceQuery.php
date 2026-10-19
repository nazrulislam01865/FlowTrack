<?php
namespace App\Queries\Dashboard;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
final class DashboardReferenceQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access) {}
    public function handle(User $actor): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return ['dashboardClients'=>$this->dashboard->dashboardClients($actor),'dashboardDepartments'=>$this->dashboard->dashboardDepartments($actor)];
    }
}
