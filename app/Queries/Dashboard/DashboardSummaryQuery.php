<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
use App\Services\Dashboard\DashboardReadModelCache;
final class DashboardSummaryQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access, private readonly DashboardReadModelCache $cache) {}
    public function handle(User $actor, DashboardFilterData $filters): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return $this->cache->rememberArray($actor,'summary',$this->identity($filters),fn()=> $this->dashboard->summaryForFilters($actor,$filters->clientId,$filters->departmentId,$filters->rangeDays));
    }
    private function identity(DashboardFilterData $f): array { return ['client'=>$f->clientId,'department'=>$f->departmentId,'range'=>$f->rangeDays]; }
}
