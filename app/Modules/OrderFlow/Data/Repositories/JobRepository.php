<?php

namespace App\Modules\OrderFlow\Data\Repositories;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class JobRepository
{
    public function __construct(
        private readonly FlowAuthorizationService $authorization,
    ) {}
    public function paginateVisible(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $paginator = $this->visibleQuery($user)
            ->with(['client', 'product', 'productCategory', 'items.product', 'items.productCategory', 'currency', 'priority', 'owner', 'coordinator', 'creator', 'workflow', 'currentPhase'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('code', 'like', "%{$search}%")
                        ->orWhere('order_code', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['phase'] ?? null, fn (Builder $query, $phase) => $query->where('current_phase_id', $phase))
            ->when($filters['health'] ?? null, fn (Builder $query, $health) => $query->where('health', $health))
            ->when($filters['client'] ?? null, fn (Builder $query, $client) => $query->where('client_id', $client))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        if (! $this->canViewFinancials($user)) {
            $paginator->getCollection()->each->makeHidden(['quoted_amount', 'currency_id', 'currency']);
        }

        return $paginator;
    }

    public function visibleQuery(User $user): Builder
    {
        return FlowJob::query()->visibleTo($user);
    }

    public function board(User $user): Collection
    {
        $jobs = $this->authorization->applyJobBoardVisibility(FlowJob::query(), $user)
            ->with(['client', 'currentPhase', 'coordinator', 'priority', 'workflow.phases', 'members', 'excludedPhases'])
            ->where('status', '!=', 'completed')
            ->orderBy('delivery_date')
            ->get();

        if (! $this->canViewFinancials($user)) {
            $jobs->each->makeHidden(['quoted_amount', 'currency_id']);
        }

        return $jobs;
    }


    public function findBoardVisible(User $user, int $id): FlowJob
    {
        return $this->authorization->applyJobBoardVisibility(FlowJob::query(), $user)
            ->with(['client', 'workflow.phases', 'currentPhase', 'tasks'])
            ->findOrFail($id);
    }

    public function findVisible(User $user, int $id): FlowJob
    {
        $job = $this->visibleQuery($user)
            ->with([
                'client.accountManager', 'workflow.phases.taskPack', 'currentPhase.taskPack.items.documentCategory', 'currentPhase.documentCategory', 'startedPhase',
                'product', 'productCategory', 'items.product', 'items.productCategory', 'currency', 'priority', 'owner', 'coordinator', 'creator',
                'members.user', 'tasks.creator', 'tasks.assignee', 'tasks.status', 'tasks.priority', 'tasks.packItem.documentCategory', 'tasks.documentCategory', 'tasks.checklistItems', 'tasks.comments.user', 'tasks.transitionEvents.actor',
                'phaseHistory.phase', 'phaseHistory.changedByUser', 'documents.category', 'documents.phase', 'documents.taskPackItem', 'documents.uploader', 'documents.linker', 'documents.versions.uploader',
                'excludedPhases.phase',
                'transitionEvents.actor', 'transitionEvents.phase', 'transitionEvents.task',
            ])
            ->findOrFail($id);

        return $job;
    }

    private function canViewFinancials(User $user): bool
    {
        return $this->authorization->allows($user, 'invoice', 'view')
            && $this->authorization->canViewSensitiveField($user, 'invoice_amount');
    }

}
