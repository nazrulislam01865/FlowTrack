<?php

namespace App\Support\Filters;

use App\Models\User;
use App\Services\FilterOptionService;
use Illuminate\Support\Collection;

final class ProductClientOptions
{
    public function __construct(private readonly FilterOptionService $options)
    {
    }

    public function forEditor(User $user, array $selectedIds): Collection
    {
        $page = $this->options->searchPage(
            user: $user,
            type: 'clients',
            context: 'master-product',
            page: 1,
            perPage: FilterOptionService::COMPACT_PER_PAGE,
            selectedIds: $selectedIds,
        );

        return $page->selectedItems
            ->concat($page->items)
            ->unique(fn (array $item) => (string) ($item['id'] ?? ''))
            ->values();
    }
}
