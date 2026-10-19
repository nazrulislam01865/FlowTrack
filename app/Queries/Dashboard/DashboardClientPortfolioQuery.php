<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
use Illuminate\Support\Collection;
final class DashboardClientPortfolioQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access) {}
    public function handle(User $actor, DashboardFilterData $f): Collection {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return $this->dashboard->clientPortfolio($actor,$f->clientId,$f->departmentId,$f->rangeDays);
    }
}
