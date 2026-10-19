<?php

namespace App\Http\Controllers;

use App\Services\FilterOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilterOptionController
{
    public function __invoke(Request $request, string $type, FilterOptionService $service): JsonResponse
    {
        abort_unless($service->supports($type), 404);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'context' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.FilterOptionService::MAX_PER_PAGE],
            'category' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'parent_type' => ['nullable', 'string', 'in:job,inquiry'],
            'parent_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            // Comma-separated ids used by lightweight add-one-at-a-time remote
            // pickers to omit values already selected in the current form.
            'exclude_ids' => ['nullable', 'string', 'max:1200'],
        ]);

        $context = trim((string) ($data['context'] ?? ''));
        $search = trim((string) ($data['q'] ?? ''));
        $page = max(1, (int) ($data['page'] ?? 1));

        $requestedPerPage = $request->integer('per_page');
        $compactInitialList = $page === 1
            && $search === ''
            && $requestedPerPage <= 0
            && (
                in_array($type, ['clients', 'jobs', 'inquiries', 'users', 'workflows', 'priorities', 'task-statuses', 'document-categories', 'document-category-records', 'department-records', 'departments', 'suppliers', 'countries', 'phone-country-codes', 'job-statuses', 'phases'], true)
                || (in_array($context, ['job-detail', 'create-inquiry'], true) && in_array($type, ['product-categories', 'products'], true))
            );
        $perPage = $requestedPerPage > 0
            ? min(FilterOptionService::MAX_PER_PAGE, $requestedPerPage)
            : ($compactInitialList ? FilterOptionService::COMPACT_PER_PAGE : FilterOptionService::DEFAULT_PER_PAGE);

        $rawSelected = $request->query('selected', []);
        $selected = is_array($rawSelected) ? $rawSelected : [$rawSelected];
        $selected = collect($selected)
            ->map(fn ($value) => is_scalar($value) ? trim((string) $value) : '')
            ->filter(fn ($value) => $value !== '' && mb_strlen($value) <= 255)
            ->unique()
            ->take(FilterOptionService::MAX_SELECTED)
            ->values()
            ->all();

        $result = $service->searchPage(
            $request->user(),
            $type,
            $context,
            $search,
            $page,
            $perPage,
            $selected,
            [
                'category' => (string) ($data['category'] ?? ''),
                'client_id' => (int) ($data['client_id'] ?? 0) ?: null,
                'parent_type' => (string) ($data['parent_type'] ?? ''),
                'parent_id' => (int) ($data['parent_id'] ?? 0) ?: null,
                'product_id' => (int) ($data['product_id'] ?? 0) ?: null,
                'exclude_ids' => collect(explode(',', (string) ($data['exclude_ids'] ?? '')))
                    ->map(fn ($value) => trim($value))
                    ->filter(fn ($value) => ctype_digit($value) && (int) $value > 0)
                    ->map(fn ($value) => (int) $value)
                    ->unique()
                    ->take(FilterOptionService::MAX_SELECTED)
                    ->values()
                    ->all(),
            ],
        );

        return response()->json($result->toArray());
    }
}
