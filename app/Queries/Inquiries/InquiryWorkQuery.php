<?php

namespace App\Queries\Inquiries;

use App\Models\User;
use App\Services\Inquiries\InquiryReadService;
use Illuminate\Support\Collection;

final class InquiryWorkQuery
{
    public function __construct(private readonly InquiryReadService $inquiries)
    {
    }

    public function groups(User $actor, array $filters, int $limit = 80): Collection
    {
        return $this->inquiries->myTaskGroups($actor, $filters, $limit);
    }
}
