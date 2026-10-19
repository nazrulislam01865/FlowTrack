<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Department;
use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Support\Filters\FilterOptionPage;
use Illuminate\Support\Collection;

class FilterOptionService
{
    public const MIN_SEARCH_LENGTH = 2;
    public const MAX_PER_PAGE = 20;
    public const DEFAULT_PER_PAGE = 20;
    public const COMPACT_PER_PAGE = 5;
    public const MAX_SELECTED = 100;

    public const TYPES = [
        'clients', 'jobs', 'inquiries', 'users', 'product-categories', 'products', 'workflows',
        'priorities', 'task-statuses', 'document-categories', 'document-category-records',
        'department-records', 'departments', 'suppliers', 'countries', 'phone-country-codes',
        'job-statuses', 'phases',
    ];

    public function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Compatibility adapter used by existing Livewire screens while Phase 4
     * migrates selectors to the paged API. The returned collection stays
     * bounded and keeps the selected item visible when it is outside page one.
     */
    public function options(
        User $user,
        string $type,
        string $context = '',
        string $search = '',
        int|string|null $selectedId = null,
        int $limit = self::DEFAULT_PER_PAGE,
        array $constraints = [],
    ): Collection {
        $selectedIds = filled($selectedId) ? [(string) $selectedId] : [];
        $page = $this->searchPage(
            $user,
            $type,
            $context,
            $search,
            1,
            $limit,
            $selectedIds,
            $constraints,
        );

        return $page->selectedItems
            ->concat($page->items)
            ->unique(fn ($item) => (string) ($item['id'] ?? ''))
            ->take(max(1, min(self::MAX_PER_PAGE, $limit)))
            ->values();
    }

    public function searchPage(
        User $user,
        string $type,
        string $context = '',
        string $search = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        array $selectedIds = [],
        array $constraints = [],
    ): FilterOptionPage {
        abort_unless($this->supports($type), 404);
        if ($type === 'suppliers' && $context === 'product-list') {
            abort_unless($user->canModule('catalog_products', 'view'), 403);
        }

        $page = max(1, min(10000, $page));
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $search = trim($search);

        // This specific Create Order picker must never fall back to the whole
        // Supplier directory. Resolve its small linked list only when opened.
        if ($type === 'suppliers' && $context === 'create-order-product-supplier') {
            abort_unless($user->canModule('jobs', 'create') && $user->canModule('catalog_products', 'view'), 403);
            $productId = (int) ($constraints['product_id'] ?? 0);
            abort_unless($productId > 0, 422, 'Select a product first.');

            $catalog = app(ProductCatalogService::class);
            $product = $catalog->findActiveProductOrFail($productId);
            $linked = $catalog->allSuppliersForProducts(collect([$product]))->get($productId, collect());
            $options = $linked->map(fn (MasterRecord $supplier): array => [
                'id' => (string) $supplier->id,
                'label' => $supplier->supplierShortCode() ?: (string) $supplier->name,
                'meta' => (string) $supplier->name,
            ])->values();
            $selectedItems = $options->filter(fn (array $option): bool => in_array($option['id'], array_map('strval', $selectedIds), true))->values();

            if ($search !== '' && mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
                $options = collect();
            } elseif ($search !== '') {
                $options = $options->filter(fn (array $option): bool =>
                    mb_stripos($option['label'], $search) !== false
                    || mb_stripos($option['meta'], $search) !== false
                )->values();
            }

            $offset = ($page - 1) * $perPage;
            $hasMore = $options->count() > $offset + $perPage;

            return new FilterOptionPage(
                items: $options->slice($offset, $perPage)->values(),
                selectedItems: $selectedItems,
                page: $page,
                perPage: $perPage,
                hasMore: $hasMore,
                nextPage: $hasMore ? $page + 1 : null,
                minSearchLength: self::MIN_SEARCH_LENGTH,
            );
        }

        $selectedItems = $this->selectedOptions($user, $type, $context, $selectedIds, $constraints);

        // An incomplete search must never fall back to unrelated "recent"
        // rows. The client can keep its existing page-one choices visible,
        // but the server response for a 1-character query is deliberately empty.
        if ($search !== '' && mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
            return new FilterOptionPage(
                items: collect(),
                selectedItems: $selectedItems,
                page: 1,
                perPage: $perPage,
                hasMore: false,
                nextPage: null,
                minSearchLength: self::MIN_SEARCH_LENGTH,
            );
        }

        $offset = ($page - 1) * $perPage;
        $window = $this->window($user, $type, $context, $search, $perPage + 1, $offset, $constraints);
        $hasMore = $window->count() > $perPage;
        $items = $window->take($perPage)->values();

        return new FilterOptionPage(
            items: $items,
            selectedItems: $selectedItems,
            page: $page,
            perPage: $perPage,
            hasMore: $hasMore,
            nextPage: $hasMore ? $page + 1 : null,
            minSearchLength: self::MIN_SEARCH_LENGTH,
        );
    }


    /**
     * Resolve only the currently selected remote-filter rows without loading a
     * page of recent options. This keeps selected labels stable on Livewire
     * renders while allowing list screens to defer the real option query until
     * the user opens the dropdown.
     */
    public function selectedOptions(
        User $user,
        string $type,
        string $context = '',
        array $selectedIds = [],
        array $constraints = [],
    ): Collection {
        abort_unless($this->supports($type), 404);

        $selectedIds = collect($selectedIds)
            ->map(fn ($value) => is_scalar($value) ? trim((string) $value) : '')
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->take(self::MAX_SELECTED)
            ->values();

        // Multi-Inquiry Create Order validation can legitimately resolve many
        // selected ids at once. Resolve that set in one bounded query instead of
        // issuing one query per Inquiry; all other selector types keep their
        // existing single-row resolver semantics.
        if ($type === 'inquiries' && $context === 'create-job') {
            return $this->inquiriesByIds($user, $context, $selectedIds->all());
        }
        if ($type === 'suppliers' && $context === 'master-product' && $selectedIds->isNotEmpty()) {
            $rows = MasterRecord::query()
                ->forWorkspace(app(SetupContext::class)->workspaceId())
                ->ofType('supplier')->active()->whereIn('id', $selectedIds->all())
                ->get(['id', 'name', 'code', 'metadata', 'type'])->keyBy('id');

            return $selectedIds->map(function ($id) use ($rows): ?array {
                $supplier = $rows->get((int) $id);
                return $supplier ? [
                    'id' => (string) $supplier->id,
                    'label' => $supplier->supplierShortCode(),
                    'meta' => (string) $supplier->name,
                ] : null;
            })->filter()->values();
        }

        return $selectedIds
            ->map(fn ($selectedId) => $this->resolveSelected($user, $type, $context, $selectedId, $constraints))
            ->filter()
            ->unique(fn ($item) => (string) ($item['id'] ?? ''))
            ->values();
    }

    private function window(
        User $user,
        string $type,
        string $context,
        string $search,
        int $limit,
        int $offset,
        array $constraints,
    ): Collection {
        return match ($type) {
            'clients' => $this->clients($user, $context, $search, $limit, $offset),
            'jobs' => $this->jobs($user, $context, $search, $limit, $offset),
            'inquiries' => $this->inquiries(
                $user,
                $context,
                $search,
                $limit,
                $offset,
                (int) ($constraints['client_id'] ?? 0) ?: null,
                array_values(array_map('intval', (array) ($constraints['exclude_ids'] ?? []))),
            ),
            'users' => $this->users($user, $context, $search, $limit, $constraints, $offset),
            'product-categories' => $this->productCategories($user, $context, $search, $limit, $offset),
            'products' => $this->products($user, $context, $search, $limit, (string) ($constraints['category'] ?? ''), $offset),
            'workflows' => $this->workflows($user, $context, $search, $limit, (int) ($constraints['client_id'] ?? 0) ?: null, $offset),
            'priorities' => $this->masterOptions('priority', $search, $limit, $offset),
            'task-statuses' => $this->masterOptions('order_task_status', $search, $limit, $offset),
            'document-categories' => $this->masterOptions('document_category', $search, $limit, $offset),
            'document-category-records' => $this->masterRecordOptions('document_category', $search, $limit, $offset),
            'department-records' => $this->masterRecordOptions('department', $search, $limit, $offset),
            'departments' => $this->departments($user, $context, $search, $limit, $offset),
            'suppliers' => $this->supplierOptions($context, $search, $limit, $offset),
            'countries' => $this->countries($user, $context, $search, $limit, $offset),
            'phone-country-codes' => $this->phoneCountryCodes($search, $limit, $offset),
            'job-statuses' => $this->jobStatuses($user, $search, $limit, $offset),
            'phases' => $this->phases($context, $search, $limit, $offset),
        };
    }

    private function resolveSelected(
        User $user,
        string $type,
        string $context,
        int|string $selectedId,
        array $constraints,
    ): ?array {
        return match ($type) {
            'clients' => $this->clientById($user, $context, $selectedId),
            'jobs' => $this->jobById($user, $context, $selectedId),
            'inquiries' => $this->inquiryById($user, $context, $selectedId, (int) ($constraints['client_id'] ?? 0) ?: null),
            'users' => $this->userById($user, $context, $selectedId, $constraints),
            'product-categories' => $this->productCategoryByName($user, $context, (string) $selectedId),
            'products' => $this->productByName($user, $context, (string) $selectedId, (string) ($constraints['category'] ?? '')),
            'workflows' => $this->workflowById($user, $context, $selectedId, (int) ($constraints['client_id'] ?? 0) ?: null),
            'priorities' => $this->masterByName('priority', (string) $selectedId),
            'task-statuses' => $this->masterByName('order_task_status', (string) $selectedId),
            'document-categories' => $this->masterByName('document_category', (string) $selectedId),
            'document-category-records' => $this->masterRecordById('document_category', $selectedId),
            'department-records' => $this->masterRecordById('department', $selectedId),
            'departments' => $this->departmentById($user, $context, $selectedId),
            'suppliers' => $this->supplierById($context, $selectedId),
            'countries' => $this->countryByName($user, $context, (string) $selectedId),
            'phone-country-codes' => $this->phoneCountryCodeByValue((string) $selectedId),
            'job-statuses' => $this->jobStatusByName($user, (string) $selectedId),
            'phases' => $this->phaseById($context, $selectedId),
        };
    }

    private function clients(User $user, string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $query = $this->clientQueryForContext($user, $context);

        return $query
            ->where('is_active', true)
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->when(strlen($search) < 2, fn ($q) => $q->orderByDesc('updated_at'))
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'country'])
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'label' => (string) $client->name,
                'meta' => (string) ($client->country ?: ''),
            ]);
    }

    private function clientById(User $user, string $context, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;
        $row = $this->clientQueryForContext($user, $context)
            ->where('is_active', true)
            ->find((int) $id, ['id', 'name', 'country']);
        return $row ? ['id'=>(int)$row->id, 'label'=>(string)$row->name, 'meta'=>(string)($row->country ?: '')] : null;
    }

    private function clientQueryForContext(User $user, string $context)
    {
        if ($context === 'board-task-pack') {
            return Client::query()
                ->whereNull('clients.purged_at')
                ->whereIn(
                    'clients.id',
                    app(BoardTaskPackService::class)->visibleJobQuery($user)->reorder()->select('flow_jobs.client_id'),
                );
        }

        if ($context === 'documents') {
            $orderClientIds = app(DocumentService::class)
                ->query($user, [], 'document_archive')
                ->whereNotNull('documents.client_id')
                ->select('documents.client_id');
            $inquiryClientIds = app(AccessControlService::class)
                ->applyInquiryDocumentArchiveScope(InquiryDocument::query(), $user)
                ->join('inquiries', 'inquiries.id', '=', 'inquiry_documents.inquiry_id')
                ->whereNotNull('inquiries.client_id')
                ->select('inquiries.client_id');

            return Client::query()
                ->whereNull('clients.purged_at')
                ->where(function ($query) use ($orderClientIds, $inquiryClientIds) {
                    $query->whereIn('clients.id', $orderClientIds)
                        ->orWhereIn('clients.id', $inquiryClientIds);
                });
        }

        if (in_array($context, ['create-job', 'jobs', 'create-inquiry', 'inquiries'], true)) {
            return app(ClientService::class)->referenceQuery($user, $context);
        }

        return app(ClientService::class)->visibleQuery($user);
    }

    /**
     * Create Order Inquiry-link options.
     *
     * Keep this selector remote and bounded: only currently linkable Inquiries
     * are returned, with same-client records ranked first for convenience.
     * The selected item is resolved through the exact same scope, preventing a
     * stale/linked Inquiry from remaining silently valid in the Create Order UI.
     */
    private function inquiries(
        User $user,
        string $context,
        string $search,
        int $limit,
        int $offset = 0,
        ?int $clientId = null,
        array $excludeIds = [],
    ): Collection {
        $query = $this->eligibleInquiryLinkQuery($user, $context);
        $excludeIds = collect($excludeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->take(self::MAX_SELECTED)
            ->values()
            ->all();
        if ($excludeIds !== []) {
            $query->whereNotIn('inquiries.id', $excludeIds);
        }
        $term = trim($search);

        if (mb_strlen($term) >= self::MIN_SEARCH_LENGTH) {
            $query->where(function ($match) use ($term): void {
                $prefix = $term.'%';
                $contains = '%'.$term.'%';
                $match->whereLike('inquiries.inquiry_number', $prefix)
                    ->orWhereLike('inquiries.reference_number', $prefix)
                    ->orWhereLike('inquiries.subject', $contains)
                    ->orWhereHas('client', fn ($client) => $client->whereLike('name', $contains));
            });
        }

        return $query
            ->with('client:id,name')
            ->when($clientId, fn ($q) => $q->orderByRaw('CASE WHEN inquiries.client_id = ? THEN 0 ELSE 1 END', [$clientId]))
            ->when($term !== '', fn ($q) => $q->orderByRaw('CASE WHEN inquiries.inquiry_number = ? THEN 0 ELSE 1 END', [$term]))
            ->orderByDesc('inquiries.updated_at')
            ->orderByDesc('inquiries.id')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'inquiries.id',
                'inquiries.inquiry_number',
                'inquiries.client_id',
                'inquiries.subject',
                'inquiries.reference_number',
                'inquiries.status',
                'inquiries.updated_at',
            ])
            ->map(fn (Inquiry $inquiry) => $this->inquiryOption($inquiry));
    }

    /** Resolve many Create Order Inquiry selections in a single query. */
    private function inquiriesByIds(User $user, string $context, array $ids): Collection
    {
        $orderedIds = collect($ids)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(self::MAX_SELECTED)
            ->values();

        if ($orderedIds->isEmpty()) return collect();

        $rows = $this->eligibleInquiryLinkQuery($user, $context)
            ->whereIn('inquiries.id', $orderedIds->all())
            ->with('client:id,name')
            ->get([
                'inquiries.id',
                'inquiries.inquiry_number',
                'inquiries.client_id',
                'inquiries.subject',
                'inquiries.reference_number',
                'inquiries.status',
            ])
            ->keyBy('id');

        return $orderedIds
            ->map(fn (int $id) => $rows->get($id))
            ->filter()
            ->map(fn (Inquiry $inquiry) => $this->inquiryOption($inquiry))
            ->values();
    }

    private function inquiryById(User $user, string $context, int|string $id, ?int $clientId = null): ?array
    {
        if (!is_numeric($id)) return null;

        $row = $this->eligibleInquiryLinkQuery($user, $context)
            ->with('client:id,name')
            ->find((int) $id, [
                'inquiries.id',
                'inquiries.inquiry_number',
                'inquiries.client_id',
                'inquiries.subject',
                'inquiries.reference_number',
                'inquiries.status',
            ]);

        return $row ? $this->inquiryOption($row) : null;
    }

    private function eligibleInquiryLinkQuery(User $user, string $context): \Illuminate\Database\Eloquent\Builder
    {
        $this->authorizeInquiryLinkOptions($user, $context);

        return app(InquiryService::class)
            ->visibleQuery($user)
            ->where(function ($query): void {
                $query->whereNull('inquiries.result')
                    ->orWhere('inquiries.result', '!=', 'dead');
            })
            ->whereNull('inquiries.converted_job_id')
            ->whereDoesntHave('linkedOrders')
            ->whereDoesntHave('sourceOrder');
    }

    private function inquiryOption(Inquiry $inquiry): array
    {
        $number = trim((string) $inquiry->inquiry_number);
        $subject = trim((string) ($inquiry->subject ?: $inquiry->reference_number));
        $label = $subject !== '' ? $number.' — '.$subject : $number;
        $meta = collect([
            trim((string) ($inquiry->client?->name ?? '')),
            trim((string) ($inquiry->status ?? '')),
        ])->filter()->implode(' · ');

        return [
            'id' => (int) $inquiry->id,
            'label' => $label,
            'meta' => $meta,
        ];
    }

    private function authorizeInquiryLinkOptions(User $user, string $context): void
    {
        abort_unless($context === 'create-job', 403);
        abort_unless($user->canModule('jobs', 'create'), 403);
        abort_unless(app(AccessControlService::class)->can($user, 'jobs', 'link'), 403);
        abort_unless(app(AccessControlService::class)->can($user, 'inquiries', 'view'), 403);
    }

    private function departments(User $user, string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $query = Department::query()->where('is_active', true);

        if ($context === 'dashboard'
            && !app(AccessControlService::class)->isAdministrator($user)
            && app(AccessControlService::class)->scope($user, 'tasks') !== 'all_records') {
            $query->whereKey($user->department_id ?: 0);
        }

        return $query
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->when(strlen($search) < 2, fn ($q) => $q->orderByDesc('updated_at'))
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'code', 'updated_at'])
            ->map(fn (Department $department) => [
                'id' => (int) $department->id,
                'label' => (string) $department->name,
                'meta' => (string) ($department->code ?: ''),
            ]);
    }

    private function departmentById(User $user, string $context, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;

        $query = Department::query();
        if (! in_array($context, ['user-editor', 'administration'], true)) {
            $query->where('is_active', true);
        }
        if ($context === 'dashboard'
            && !app(AccessControlService::class)->isAdministrator($user)
            && app(AccessControlService::class)->scope($user, 'tasks') !== 'all_records') {
            $query->whereKey($user->department_id ?: 0);
        }

        $department = $query->find((int) $id, ['id', 'name', 'code']);
        return $department ? [
            'id' => (int) $department->id,
            'label' => (string) $department->name,
            'meta' => (string) ($department->code ?: ''),
        ] : null;
    }

    private function jobs(User $user, string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $query = match ($context) {
            'documents' => app(JobService::class)->visibleQuery($user)->whereHas('client', fn ($client) => $client->where('is_active', true)),
            'board-task-pack' => app(BoardTaskPackService::class)->visibleJobQuery($user),
            default => app(JobService::class)->activeQuery($user),
        };

        return $query
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('job_number', $search.'%')
                ->orWhereLike('title', '%'.$search.'%')))
            ->with('client:id,name,logo_path')
            ->orderByDesc('updated_at')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'job_number', 'title', 'client_id', 'updated_at'])
            ->map(fn (FlowJob $job) => [
                'id' => (int) $job->id,
                'label' => trim($job->displayOrderNumber().' — '.$job->title),
                'meta' => (string) ($job->client?->name ?: ''),
            ]);
    }

    private function jobById(User $user, string $context, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;
        $query = match ($context) {
            'documents' => app(JobService::class)->visibleQuery($user),
            'board-task-pack' => app(BoardTaskPackService::class)->visibleJobQuery($user),
            default => app(JobService::class)->activeQuery($user),
        };
        $job = $query->with('client:id,name,logo_path')->find((int) $id, ['id','job_number','title','client_id']);
        return $job ? ['id'=>(int)$job->id, 'label'=>trim($job->displayOrderNumber().' — '.$job->title), 'meta'=>(string)($job->client?->name ?: '')] : null;
    }

    private function users(User $user, string $context, string $search, int $limit, array $constraints = [], int $offset = 0): Collection
    {
        return $this->visibleUsers($user, $context, $constraints)
            ->with(['department:id,name', 'role:id,name'])
            ->when(strlen($search) >= 2, fn ($q) => $q->whereLike('name', $search.'%'))
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'department_id', 'role_id', 'name', 'profile_image_path'])
            ->map(function (User $row) {
                $meta = collect([
                    $row->department?->name,
                    $row->role?->name,
                ])->filter()->unique()->implode(' · ');

                return [
                    'id' => (int) $row->id,
                    'label' => (string) $row->name,
                    'meta' => (string) $meta,
                    'avatarUrl' => $row->profileImageUrl(),
                ];
            });
    }

    private function userById(User $user, string $context, int|string $id, array $constraints = []): ?array
    {
        if (!is_numeric($id)) return null;
        $row = $this->visibleUsers($user, $context, $constraints)
            ->with(['department:id,name', 'role:id,name'])
            ->find((int) $id, ['id', 'department_id', 'role_id', 'name', 'profile_image_path']);

        if (! $row) return null;

        $meta = collect([
            $row->department?->name,
            $row->role?->name,
        ])->filter()->unique()->implode(' · ');

        return [
            'id' => (int) $row->id,
            'label' => (string) $row->name,
            'meta' => (string) $meta,
            'avatarUrl' => $row->profileImageUrl(),
        ];
    }

    private function productCategories(User $user, string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $this->authorizeCatalogAccess($user, $context);
        abort_unless($user->canModule('product_categories', 'view'), 403);

        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('product_category')
            ->active()
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (MasterRecord $category) => [
                'id' => (string) $category->name,
                'label' => (string) $category->name,
                'meta' => (string) ($category->code ?: ''),
            ]);
    }

    private function productCategoryByName(User $user, string $context, string $name): ?array
    {
        if ($name === '') return null;
        $this->authorizeCatalogAccess($user, $context);
        abort_unless($user->canModule('product_categories', 'view'), 403);

        $row = MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('product_category')
            ->active()
            ->where('name', $name)
            ->first(['id', 'name', 'code']);

        return $row ? [
            'id' => (string) $row->name,
            'label' => (string) $row->name,
            'meta' => (string) ($row->code ?: ''),
        ] : null;
    }

    private function products(User $user, string $context, string $search, int $limit, string $category, int $offset = 0): Collection
    {
        $this->authorizeCatalogAccess($user, $context);
        if (trim($category) !== '') {
            abort_unless($user->canModule('product_categories', 'view'), 403);
        }

        $workspaceId = app(SetupContext::class)->workspaceId();
        $categoryId = $this->productCategoryId($workspaceId, $category);

        return MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->active()
            ->with('parent:id,name')
            ->when($category !== '', function ($query) use ($category, $categoryId) {
                // New Product records are linked by parent_id. Some older/demo
                // records were imported before that relationship was enforced
                // and only carry the category in their description. Keep that
                // small compatibility fallback so Add Job works immediately
                // while the backfill migration repairs the stored relationship.
                $query->where(function ($scope) use ($category, $categoryId) {
                    if ($categoryId) {
                        $scope->where('parent_id', $categoryId);
                    }

                    $scope->orWhere(function ($legacy) use ($category) {
                        $legacy->whereNull('parent_id')
                            ->where(function ($description) use ($category) {
                                $description->where('description', $category)
                                    ->orWhereLike('description', $category.' ·%');
                            });
                    });
                });
            })
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'parent_id', 'name', 'code', 'description'])
            ->map(fn (MasterRecord $product) => [
                // Job items currently store the product name, so use that as
                // the selection value while keeping the database id internal.
                'id' => (string) $product->name,
                'label' => (string) $product->name,
                'meta' => (string) ($product->parent?->name ?: $this->legacyProductCategory($product->description) ?: $product->code ?: ''),
            ]);
    }

    private function productByName(User $user, string $context, string $name, string $category): ?array
    {
        if ($name === '') return null;
        $this->authorizeCatalogAccess($user, $context);
        if (trim($category) !== '') {
            abort_unless($user->canModule('product_categories', 'view'), 403);
        }

        $workspaceId = app(SetupContext::class)->workspaceId();
        $categoryId = $this->productCategoryId($workspaceId, $category);

        $row = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->active()
            ->with('parent:id,name')
            ->where('name', $name)
            ->when($category !== '', function ($query) use ($category, $categoryId) {
                $query->where(function ($scope) use ($category, $categoryId) {
                    if ($categoryId) {
                        $scope->where('parent_id', $categoryId);
                    }

                    $scope->orWhere(function ($legacy) use ($category) {
                        $legacy->whereNull('parent_id')
                            ->where(function ($description) use ($category) {
                                $description->where('description', $category)
                                    ->orWhereLike('description', $category.' ·%');
                            });
                    });
                });
            })
            ->first(['id', 'parent_id', 'name', 'code', 'description']);

        return $row ? [
            'id' => (string) $row->name,
            'label' => (string) $row->name,
            'meta' => (string) ($row->parent?->name ?: $this->legacyProductCategory($row->description) ?: $row->code ?: ''),
        ] : null;
    }

    private function productCategoryId(int $workspaceId, string $category): ?int
    {
        if ($category === '') return null;

        $id = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->active()
            ->where('name', $category)
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function legacyProductCategory(?string $description): string
    {
        $description = trim((string) $description);
        if ($description === '') return '';

        return trim(explode(' ·', $description, 2)[0]);
    }

    private function authorizeCatalogAccess(User $user, string $context): void
    {
        // Products is the single authority for Product catalogue visibility and
        // Product-row operations. Inquiry/Order permissions still control access
        // to the parent record, but there is no separate Product Lines module.
        abort_unless($user->canModule('catalog_products', 'view'), 403);

        $allowed = match ($context) {
            'create-job' => $user->canModule('jobs', 'create'),
            'create-inquiry' => $user->canModule('inquiries', 'create'),
            'job-detail' => $user->canModule('jobs', 'view') && $user->canModule('catalog_products', 'edit'),
            'inquiry-detail' => $user->canModule('inquiries', 'view') && $user->canModule('catalog_products', 'edit'),
            default => false,
        };

        abort_unless($allowed, 403);
    }

    private function workflows(User $user, string $context, string $search, int $limit, ?int $clientId = null, int $offset = 0): Collection
    {
        $this->authorizeWorkflowOptions($user, $context);
        $workspaceId = app(SetupContext::class)->workspaceId();
        $appliesTo = match ($context) {
            'create-inquiry' => 'inquiries',
            default => null,
        };

        return WorkflowTemplate::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->when(
                $context === 'create-job',
                fn ($query) => $query->availableFor('orders', $clientId),
                fn ($query) => $query->when($appliesTo, fn ($scope) => $scope->availableFor($appliesTo, $clientId)),
            )
            ->when(strlen($search) >= 2, fn ($q) => $q->whereLike('name', $search.'%'))
            ->withCount(['phases' => fn ($q) => $q->where('is_active', true)])
            ->orderByRaw("CASE WHEN client_availability = 'specific' THEN 0 ELSE 1 END")
            ->when($context !== 'create-job', fn ($query) => $query->orderByRaw("CASE WHEN applies_to = 'inquiries' THEN 0 ELSE 1 END"))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (WorkflowTemplate $workflow) => [
                'id' => (int) $workflow->id,
                'label' => (string) $workflow->name,
                'meta' => $workflow->phases_count.' '.str('phase')->plural($workflow->phases_count),
            ]);
    }

    private function workflowById(User $user, string $context, int|string $id, ?int $clientId = null): ?array
    {
        if (!is_numeric($id)) return null;
        $this->authorizeWorkflowOptions($user, $context);
        $appliesTo = match ($context) {
            'create-inquiry' => 'inquiries',
            default => null,
        };

        $row = WorkflowTemplate::query()
            ->where('workspace_id', app(SetupContext::class)->workspaceId())
            ->where('is_active', true)
            ->when(
                $context === 'create-job',
                fn ($query) => $query->availableFor('orders', $clientId),
                fn ($query) => $query->when($appliesTo, fn ($scope) => $scope->availableFor($appliesTo, $clientId)),
            )
            ->withCount(['phases' => fn ($q) => $q->where('is_active', true)])
            ->find((int) $id, ['id', 'name']);

        return $row ? [
            'id' => (int) $row->id,
            'label' => (string) $row->name,
            'meta' => $row->phases_count.' '.str('phase')->plural($row->phases_count),
        ] : null;
    }


    private function masterOptions(string $type, string $search, int $limit, int $offset = 0): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType($type)
            ->active()
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (MasterRecord $record) => [
                'id' => (string) $record->name,
                'label' => (string) $record->name,
                'meta' => '',
            ]);
    }

    private function supplierOptions(string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $showShortCode = $this->supplierShortCodeContext($context);

        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('supplier')
            ->when($context !== 'product-list', fn ($q) => $q->active())
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')
                ->when($context === 'product-list', fn ($x) => $x->orWhereLike('metadata->short_code', $search.'%'))))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'code', 'metadata', 'type'])
            ->map(fn (MasterRecord $record) => [
                'id' => (string) $record->id,
                'label' => $showShortCode ? $record->supplierShortCode() : (string) $record->name,
                'meta' => $context === 'product-list' ? '' : ($showShortCode ? (string) $record->name : ''),
            ]);
    }

    private function supplierById(string $context, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;

        $record = MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('supplier')
            ->when($context !== 'product-list', fn ($q) => $q->active())
            ->find((int) $id, ['id', 'name', 'code', 'metadata', 'type']);

        if (! $record) return null;

        $showShortCode = $this->supplierShortCodeContext($context);

        return [
            'id' => (string) $record->id,
            'label' => $showShortCode ? $record->supplierShortCode() : (string) $record->name,
            'meta' => $context === 'product-list' ? '' : ($showShortCode ? (string) $record->name : ''),
        ];
    }

    private function supplierShortCodeContext(string $context): bool
    {
        return in_array($context, [
            'create-job',
            'create-inquiry',
            'job-detail',
            'order-detail-product-edit',
            'master-product',
            'product-list',
        ], true);
    }

    private function masterRecordOptions(string $type, string $search, int $limit, int $offset = 0): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType($type)
            ->active()
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('code', $search.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (MasterRecord $record) => [
                'id' => (string) $record->id,
                'label' => (string) $record->name,
                'meta' => '',
            ]);
    }

    private function masterByName(string $type, string $name): ?array
    {
        if ($name === '') return null;

        $record = MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType($type)
            ->active()
            ->where('name', $name)
            ->first(['id', 'name', 'code']);

        return $record ? [
            'id' => (string) $record->name,
            'label' => (string) $record->name,
            'meta' => '',
        ] : null;
    }

    private function masterRecordById(string $type, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;

        $record = MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType($type)
            ->active()
            ->find((int) $id, ['id', 'name', 'code']);

        return $record ? [
            'id' => (string) $record->id,
            'label' => (string) $record->name,
            'meta' => '',
        ] : null;
    }

    private function phoneCountryCodes(string $search, int $limit, int $offset = 0): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('phone_country_code')
            ->active()
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('description', '%'.$search.'%')
                ->orWhereLike('code', $search.'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'name', 'description', 'code'])
            ->map(fn (MasterRecord $record) => [
                'id' => (string) $record->name,
                'label' => (string) $record->name,
                'meta' => (string) ($record->description ?: ''),
            ]);
    }

    private function phoneCountryCodeByValue(string $value): ?array
    {
        if ($value === '') return null;

        $record = MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('phone_country_code')
            ->active()
            ->where('name', $value)
            ->first(['id', 'name', 'description', 'code']);

        return $record ? [
            'id' => (string) $record->name,
            'label' => (string) $record->name,
            'meta' => (string) ($record->description ?: ''),
        ] : null;
    }

    private function countries(User $user, string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $active = $context !== 'clients-archived';

        return app(ClientService::class)->visibleQuery($user)
            ->where('is_active', $active)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->when(strlen($search) >= 2, fn ($q) => $q->whereLike('country', $search.'%'))
            ->distinct()
            ->orderBy('country')
            ->offset($offset)
            ->limit($limit)
            ->pluck('country')
            ->map(fn ($country) => ['id' => (string) $country, 'label' => (string) $country, 'meta' => '']);
    }

    private function countryByName(User $user, string $context, string $country): ?array
    {
        if ($country === '') return null;
        $active = $context !== 'clients-archived';
        $exists = app(ClientService::class)->visibleQuery($user)
            ->where('is_active', $active)
            ->where('country', $country)
            ->exists();

        return $exists ? ['id' => $country, 'label' => $country, 'meta' => ''] : null;
    }

    private function jobStatuses(User $user, string $search, int $limit, int $offset = 0): Collection
    {
        return app(JobService::class)->visibleQuery($user)
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->when(strlen($search) >= 2, fn ($q) => $q->whereLike('status', $search.'%'))
            ->distinct()
            ->orderBy('status')
            ->offset($offset)
            ->limit($limit)
            ->pluck('status')
            ->map(fn ($status) => ['id' => (string) $status, 'label' => (string) $status, 'meta' => '']);
    }

    private function jobStatusByName(User $user, string $status): ?array
    {
        if ($status === '') return null;
        $exists = app(JobService::class)->visibleQuery($user)->where('status', $status)->exists();
        return $exists ? ['id' => $status, 'label' => $status, 'meta' => ''] : null;
    }

    private function phases(string $context, string $search, int $limit, int $offset = 0): Collection
    {
        $workspaceId = app(SetupContext::class)->workspaceId();

        return WorkflowPhase::query()
            ->whereNotNull('workflow_template_id')
            ->where('is_active', true)
            ->whereHas('workflowTemplate', fn ($workflow) => $workflow
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->when($context === 'order-list', fn ($query) => $query->where('applies_to', 'orders')))
            ->with('workflowTemplate:id,name')
            ->when(strlen($search) >= 2, fn ($q) => $q->where(fn ($x) => $x
                ->whereLike('name', $search.'%')
                ->orWhereLike('short_name', $search.'%')))
            ->orderBy('sequence')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'workflow_template_id', 'name', 'short_name', 'sequence'])
            ->map(fn (WorkflowPhase $phase) => [
                'id' => (int) $phase->id,
                'label' => (string) $phase->name,
                'meta' => (string) ($phase->workflowTemplate?->name ?: ''),
            ]);
    }

    private function phaseById(string $context, int|string $id): ?array
    {
        if (!is_numeric($id)) return null;
        $workspaceId = app(SetupContext::class)->workspaceId();
        $phase = WorkflowPhase::query()
            ->whereNotNull('workflow_template_id')
            ->where('is_active', true)
            ->whereHas('workflowTemplate', fn ($workflow) => $workflow
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->when($context === 'order-list', fn ($query) => $query->where('applies_to', 'orders')))
            ->with('workflowTemplate:id,name')
            ->find((int) $id, ['id', 'workflow_template_id', 'name']);

        return $phase ? [
            'id' => (int) $phase->id,
            'label' => (string) $phase->name,
            'meta' => (string) ($phase->workflowTemplate?->name ?: ''),
        ] : null;
    }

    private function authorizeWorkflowOptions(User $user, string $context): void
    {
        if ($context === 'board') {
            abort_unless($user->canAccess('tasks.view'), 403);
            return;
        }

        if ($context === 'create-inquiry') {
            abort_unless($user->canModule('inquiries', 'create'), 403);
            return;
        }

        abort_unless($user->canAccess('jobs.create'), 403);
    }

    private function visibleUsers(User $user, string $context, array $constraints = [])
    {
        $parentType = (string) ($constraints['parent_type'] ?? '');
        $parentId = (int) ($constraints['parent_id'] ?? 0);
        $isParentCreator = false;
        if ($parentId > 0 && $parentType === 'job') {
            $isParentCreator = FlowJob::query()->whereKey($parentId)->where('created_by', $user->id)->exists();
        } elseif ($parentId > 0 && $parentType === 'inquiry') {
            $isParentCreator = \App\Models\Inquiry::query()->whereKey($parentId)->where('created_by', $user->id)->exists();
        }

        if ($context === 'task-pack-setup') {
            abort_unless($user->canModule('taskpacks', 'create') || $user->canModule('taskpacks', 'edit'), 403);
            return User::query()->where('is_active', true);
        }

        if ($context === 'client-account-manager') {
            return ($user->canModule('clients', 'assign') || $user->canModule('clients', 'edit_all'))
                ? User::query()->where('is_active', true)
                : User::query()->where('is_active', true)->whereKey($user->id);
        }

        if ($context === 'create-job') {
            if ($user->canModule('jobs', 'assign') || $user->canModule('tasks', 'assign')) {
                return User::query()->where('is_active', true);
            }

            return User::query()->where('is_active', true)->whereKey($user->id);
        }

        if (in_array($context, ['create-inquiry', 'inquiry-owner'], true)) {
            return ($user->canModule('inquiries', 'assign') || ($context === 'inquiry-owner' && $isParentCreator))
                ? User::query()->where('is_active', true)
                : User::query()->where('is_active', true)->whereKey($user->id);
        }

        if ($context === 'task-assignee') {
            return ($user->canModule('tasks', 'assign') || $isParentCreator)
                ? User::query()->where('is_active', true)
                : User::query()->where('is_active', true)->whereKey($user->id);
        }

        if ($context === 'job-owner') {
            return ($user->canModule('jobs', 'assign') || $isParentCreator)
                ? User::query()->where('is_active', true)
                : User::query()->where('is_active', true)->whereKey($user->id);
        }

        if ($context === 'board-task-pack') {
            $assigneeIds = app(AccessControlService::class)
                ->applyTaskScope(Task::query(), $user)
                ->whereNotNull('assignee_id')
                ->select('assignee_id')
                ->distinct();

            return User::query()
                ->where('is_active', true)
                ->whereIn('id', $assigneeIds);
        }

        if ($context === 'order-list-user-filter') {
            // List filters are read-only selectors, not assignment controls.
            // Show the complete active FlowTrack user directory so Owner /
            // stage-assignee filters are not limited to users who already
            // happen to be assigned to one of the currently visible Orders.
            return User::query()->where('is_active', true);
        }

        if ($context === 'order-list') {
            $visibleOrderIds = app(JobService::class)
                ->visibleQuery($user)
                ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                ->select('flow_jobs.id');

            $assigneeIds = app(TaskService::class)
                ->visibleQuery($user)
                ->whereNotNull('tasks.assignee_id')
                ->whereIn('tasks.flow_job_id', $visibleOrderIds)
                ->select('tasks.assignee_id')
                ->distinct();

            return User::query()
                ->where('is_active', true)
                ->whereIn('id', $assigneeIds);
        }

        if ($context === 'order-list-owner') {
            $ownerIds = app(JobService::class)
                ->visibleQuery($user)
                ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                ->whereNotNull('flow_jobs.owner_id')
                ->select('flow_jobs.owner_id')
                ->distinct();

            return User::query()
                ->where('is_active', true)
                ->whereIn('id', $ownerIds);
        }

        if ($context === 'client-orders') {
            $clientId = (int) ($constraints['client_id'] ?? 0);
            if ($clientId <= 0) {
                return User::query()->whereRaw('1 = 0');
            }

            $ownerIds = app(JobService::class)
                ->visibleQuery($user)
                ->where('flow_jobs.client_id', $clientId)
                ->whereNotNull('flow_jobs.owner_id')
                ->select('flow_jobs.owner_id')
                ->distinct();

            return User::query()
                ->where('is_active', true)
                ->whereIn('id', $ownerIds);
        }

        if ($context === 'documents') {
            $orderUploaderIds = app(DocumentService::class)
                ->query($user, [], 'document_archive')
                ->whereNotNull('documents.uploaded_by')
                ->select('documents.uploaded_by');
            $inquiryUploaderIds = app(AccessControlService::class)
                ->applyInquiryDocumentArchiveScope(InquiryDocument::query(), $user)
                ->whereNotNull('uploaded_by')
                ->select('uploaded_by');

            return User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($orderUploaderIds, $inquiryUploaderIds) {
                    $query->whereIn('id', $orderUploaderIds)
                        ->orWhereIn('id', $inquiryUploaderIds);
                });
        }

        $access = app(AccessControlService::class);
        $module = $context === 'clients' ? 'clients' : ($context === 'jobs' ? 'jobs' : 'tasks');
        if ($access->scope($user, $module) === 'all_records') {
            return User::query()->where('is_active', true);
        }

        $ids = match ($context) {
            'clients' => app(ClientService::class)->visibleQuery($user)
                ->whereNotNull('account_manager_id')
                ->select('account_manager_id')
                ->distinct(),
            'jobs' => app(JobService::class)->activeQuery($user)
                ->whereNotNull('owner_id')
                ->select('owner_id')
                ->distinct(),
            default => app(TaskService::class)->visibleQuery($user)
                ->whereNotNull('assignee_id')
                ->whereHas('job', fn ($job) => $job->whereHas('client', fn ($client) => $client->where('is_active', true)))
                ->select('assignee_id')
                ->distinct(),
        };

        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereIn('id', $ids)->orWhereKey($user->id));
    }
}
