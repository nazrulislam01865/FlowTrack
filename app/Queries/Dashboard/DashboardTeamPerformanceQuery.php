<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardReportingService;
use Illuminate\Support\Collection;
final class DashboardTeamPerformanceQuery {
    public function __construct(private readonly DashboardReportingService $dashboard, private readonly AccessControlService $access) {}
    public function rows(User $actor, DashboardFilterData $f, string $period='custom', ?string $from=null, ?string $to=null, string $sort='performance'): Collection {
        // Team Performance is a report capability. DashboardPrimaryQuery separately
        // authorizes dashboard.view before it composes this section.
        abort_unless($this->access->can($actor,'reports','view'),403);
        $rows=$this->dashboard->assigneePerformance($actor,$f->clientId,$f->departmentId,$period,$from,$to);
        return $this->dashboard->sortTeamPerformance($this->dashboard->decorateTeamPerformance($rows),$sort)->values();
    }
    public function reportingPeriod(User $actor, string $period='this_week', ?string $from=null, ?string $to=null): array {
        abort_unless($this->access->can($actor,'reports','view'),403);
        return $this->dashboard->teamReportingPeriod($period,$from,$to);
    }
    public function dashboardPeriod(User $actor,int $rangeDays): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return $this->dashboard->dashboardReportingPeriod($rangeDays);
    }
}
