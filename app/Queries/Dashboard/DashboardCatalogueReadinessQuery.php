<?php
namespace App\Queries\Dashboard;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;
use App\Services\Dashboard\DashboardReadModelCache;
final class DashboardCatalogueReadinessQuery {
    public function __construct(private readonly DashboardOverviewService $dashboard, private readonly AccessControlService $access, private readonly DashboardReadModelCache $cache) {}
    public function handle(User $actor): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return $this->cache->rememberArray($actor,'catalogue-readiness',[],fn()=> $this->dashboard->catalogueReadiness($actor));
    }
}
