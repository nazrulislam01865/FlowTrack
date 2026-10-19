<?php

namespace App\Queries\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryReadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Permission-scoped read boundary for Inquiry detail/task screens.
 *
 * Phase 6 deliberately delegates to the proven InquiryService scopes so
 * existing record/task visibility rules remain the source of truth.
 */
final class InquiryDetailQuery
{
    public function __construct(private readonly InquiryReadService $inquiries)
    {
    }

    public function find(User $actor, int $inquiryId, array $with = []): Inquiry
    {
        return $this->inquiries->findVisible($actor, $inquiryId, $with);
    }

    public function task(User $actor, int $taskId, array $with = []): InquiryTask
    {
        return $this->inquiries->findVisibleTask($actor, $taskId, $with);
    }

    public function taskDetail(User $actor, int $taskId): InquiryTask
    {
        return $this->inquiries->taskDetail($actor, $taskId);
    }

    public function detail(User $actor, int $inquiryId, array $with = [], array $withCount = []): Inquiry
    {
        return $this->inquiries->visibleQuery($actor)
            ->with($with)
            ->withCount($withCount)
            ->findOrFail($inquiryId);
    }

    public function canEdit(User $actor, Inquiry $inquiry): bool
    {
        return $this->inquiries->canEdit($actor, $inquiry);
    }

    public function canEditVisible(User $actor, Inquiry $inquiry): bool
    {
        return $this->inquiries->canEditVisible($actor, $inquiry);
    }

    public function taskStatusOptions(?string $currentStatus = null): \Illuminate\Support\Collection
    {
        return $this->inquiries->taskStatusOptions($currentStatus);
    }

    public function canEditTask(User $actor, InquiryTask $task): bool
    {
        return $this->inquiries->canEditTask($actor, $task);
    }

    public function activity(User $actor, Inquiry $inquiry, int $perPage = 30, string $tab = 'all'): LengthAwarePaginator
    {
        return $this->inquiries->activityPage($actor, $inquiry, $perPage, $tab);
    }

    public function documents(User $actor, Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator
    {
        return $this->inquiries->documentsPage($actor, $inquiry, $perPage);
    }

    public function statusColor(?string $autoStatus, ?string $taskStatus = null): ?string
    {
        return $this->inquiries->inquiryStatusColor($autoStatus, $taskStatus);
    }

    public function taskStatusNeedsAttention(string $status): bool
    {
        return $this->inquiries->taskStatusNeedsAttention($status);
    }
}
