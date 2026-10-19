<?php

namespace App\Support\Filters;

use Illuminate\Support\Collection;

final readonly class FilterOptionPage
{
    public function __construct(
        public Collection $items,
        public Collection $selectedItems,
        public int $page,
        public int $perPage,
        public bool $hasMore,
        public ?int $nextPage,
        public int $minSearchLength,
    ) {
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items->values()->all(),
            'selected_items' => $this->selectedItems->values()->all(),
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'has_more' => $this->hasMore,
                'next_page' => $this->nextPage,
            ],
            'query' => [
                'min_length' => $this->minSearchLength,
            ],
        ];
    }
}
