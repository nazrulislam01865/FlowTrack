@php
    $masterData = app(\App\Services\MasterDataService::class);
    $tone = static function (string $status): string {
        return match (true) {
            str_contains($status, 'Converted'), str_contains($status, 'Completed') => 'green',
            str_contains($status, 'Dead'), str_contains($status, 'Closed') => 'red',
            str_contains($status, 'Ready'), str_contains($status, 'On Hold') => 'amber',
            str_contains($status, 'Waiting') => 'purple',
            default => 'blue',
        };
    };
    $priorityTone = static function (string $priority): string {
        return match (strtolower(trim($priority))) {
            'critical', 'urgent' => 'red',
            'high' => 'amber',
            'low' => 'green',
            default => 'blue',
        };
    };
    $initials = static function (?string $name): string {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        return strtoupper(substr(implode('', array_map(fn ($part) => substr($part, 0, 1), $parts)), 0, 2)) ?: '—';
    };
    $mentionText = static function (?string $text): string {
        $escaped = e((string) $text);
        return preg_replace('/(?<![\pL\pN._-])@([\pL\pN][\pL\pN._-]*)/u', '<span class="mention">@$1</span>', $escaped) ?? $escaped;
    };
    $inquiryToolbarIsClear = trim((string) $search) === ''
        && $quick === 'all'
        && $listStatus === ''
        && $listClient === ''
        && $dateFrom === ''
        && $dateTo === ''
        && ! $hideCompleted;
    $inquiryPendingToolbarIsClear = $pendingListStatus === ''
        && $pendingListClient === ''
        && $pendingDateFrom === ''
        && $pendingDateTo === ''
        && ! $pendingHideCompleted;
    $inquiryFilterDraftDirty = $pendingListStatus !== $listStatus
        || $pendingListClient !== $listClient
        || $pendingDateFrom !== $dateFrom
        || $pendingDateTo !== $dateTo
        || $pendingHideCompleted !== $hideCompleted;
    $inquiryAnyFilterActive = $metricFilter !== '' || ! $inquiryToolbarIsClear || ! $inquiryPendingToolbarIsClear;
    $canDeleteInquiries = auth()->user()->canModule('inquiries', 'delete');
    $inquiryExportQuery = array_filter([
        'search' => filled($search) ? $search : null,
        'quick' => $quick !== 'all' ? $quick : null,
        'metric' => filled($metricFilter) ? $metricFilter : null,
        'client' => filled($listClient) ? $listClient : null,
        'status' => filled($listStatus) ? $listStatus : null,
        'hide_completed' => $hideCompleted ? 1 : null,
        'date_from' => filled($dateFrom) ? $dateFrom : null,
        'date_to' => filled($dateTo) ? $dateTo : null,
    ], static fn ($value) => $value !== null && $value !== '');
    // RFQ delivery failures are represented in the product workspace itself.
    // Keep the generic page banner for genuine form/validation errors only.
    $pageError = collect($errors->getBag('default')->messages())
        ->except(['rfqDelivery'])
        ->flatten()
        ->first();
@endphp

<div class="ft-inquiry-prototype">
    @if(session('success'))<div class="flash-inline">{{ session('success') }}</div>@endif
    @if($mode !== 'create' && filled($pageError))<div class="error-inline">{{ $pageError }}</div>@endif

    @if($mode === 'list')
        @include('livewire.inquiries.sections.list')

    @elseif($mode === 'create')
        @include('livewire.inquiries.sections.create')

    @else
        @include('livewire.inquiries.sections.detail')
    @endif

    <x-catalog.missing-product-supplier-modal
        :show="$showMissingProductSupplierModal"
        :product-name="$missingProductSupplierName"
        :choice="$missingProductSupplierChoice"
        :existing-supplier-id="$missingProductExistingSupplierId"
        :existing-supplier-label="$missingProductExistingSupplierLabel"
        :new-supplier-name="$missingProductNewSupplierName"
        :new-supplier-email="$missingProductNewSupplierEmail"
        :allow-skip="$missingProductSupplierAllowSkip"
        :record-label="$missingProductSupplierRecordLabel"
        :submit-mode="$missingProductSupplierSubmitMode"
        :selector-context="$missingProductSupplierSelectorContext"
    />
</div>
