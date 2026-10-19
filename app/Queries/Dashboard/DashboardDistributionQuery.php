<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
use App\Services\Dashboard\DashboardReadModelCache;
final class DashboardDistributionQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access, private readonly DashboardReadModelCache $cache) {}
    public function handle(User $actor, DashboardFilterData $f): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        $id=['client'=>$f->clientId,'department'=>$f->departmentId,'range'=>$f->rangeDays];
        return [
            'flowDistribution'=>$this->cache->rememberArray($actor,'flow-distribution',$id,fn()=> $this->dashboard->flowDistribution($actor,$f->clientId,$f->departmentId,$f->rangeDays)),
            'taskStatusDistribution'=>$this->cache->rememberArray($actor,'task-status-distribution',$id,fn()=> $this->dashboard->taskStatusDistribution($actor,$f->clientId,$f->departmentId,$f->rangeDays)),
        ];
    }
}
