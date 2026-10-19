<?php

namespace App\Queries\Inquiries;

use App\Models\User;
use App\Services\Inquiries\InquiryReadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Permission-scoped read boundary for the operational Inquiry list. */
final class InquiryListQuery
{
    public function __construct(private readonly InquiryReadService $inquiries)
    {
    }

    public function metrics(User $actor, array $filters = []): array
    {
        return $this->inquiries->metrics($actor, $filters);
    }

    public function paginate(User $actor, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return $this->inquiries->paginate($actor, $filters, $perPage);
    }

    public function rows(LengthAwarePaginator $paginator, User $actor): Collection
    {
        return $this->inquiries->listRows($paginator, $actor);
    }

    public function taskStatusOptions(): Collection
    {
        return $this->inquiries->taskStatusFilterOptions();
    }
}
