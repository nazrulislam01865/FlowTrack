<?php
namespace App\Queries\Dashboard;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardAttentionService;
final class DashboardAttentionQuery {
    public function __construct(private readonly DashboardAttentionService $dashboard, private readonly AccessControlService $access) {}
    public function handle(User $actor, DashboardFilterData $f): array {
        abort_unless($this->access->can($actor,'dashboard','view'),403);
        return [
            'attentionTasks'=>$this->dashboard->attentionTasks($actor),
            'attentionOrders'=>$this->dashboard->attentionOrders($actor,$f->clientId,$f->departmentId,$f->rangeDays),
            'attentionInquiries'=>$this->dashboard->attentionInquiries($actor,$f->clientId,$f->departmentId,$f->rangeDays),
        ];
    }
}
