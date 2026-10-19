<?php

namespace App\Support;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\InquiryRfqInvitation;
use App\Models\MasterRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Query-free presenter for Inquiry Details > RFQ.
 *
 * Projects already-loaded Inquiry products, Product Master supplier assignments
 * and RFQ invitations into the product-first workspace contract consumed by the
 * reusable RFQ Blade components. Data access stays in services/page-data.
 */
final class InquiryRfqProductWorkspacePresenter
{
    private const PER_PAGE = 5;

    /**
     * @param Collection<string,MasterRecord> $productMasters Product masters keyed by normalized product name.
     * @param Collection<int,Collection<int,MasterRecord>> $suppliersByProduct Product id => active suppliers.
     * @param Collection<int,InquiryRfqInvitation> $invitations
     * @param array<int,string> $selectedKeys Composite `inquiryItemId:supplierId` keys.
     * @return array<string,mixed>
     */
    public static function build(
        Inquiry $inquiry,
        Collection $productMasters,
        Collection $suppliersByProduct,
        Collection $invitations,
        bool $emailEnabled,
        string $search = '',
        string $status = 'all',
        array $selectedKeys = [],
        int $page = 1,
    ): array {
        $items = collect($inquiry->items ?? collect())
            ->filter(fn ($item): bool => $item instanceof InquiryItem && filled($item->item_name))
            ->sortBy(fn (InquiryItem $item): array => [(int) ($item->sort_order ?? 0), (int) $item->id])
            ->values();

        $invitationsBySupplier = $invitations
            ->filter(fn ($invitation): bool => $invitation instanceof InquiryRfqInvitation)
            ->keyBy(fn (InquiryRfqInvitation $invitation): int => (int) $invitation->supplier_id);

        $allGroups = $items->map(function (InquiryItem $item, int $index) use ($productMasters, $suppliersByProduct, $invitationsBySupplier, $emailEnabled): array {
            $product = $productMasters->get(self::normalise((string) $item->item_name));
            $suppliers = $product
                ? collect($suppliersByProduct->get((int) $product->id, collect()))->values()
                : collect();

            $supplierRows = $suppliers->map(function (MasterRecord $supplier) use ($item, $invitationsBySupplier, $emailEnabled): array {
                $invitation = $invitationsBySupplier->get((int) $supplier->id);
                return self::supplierRow($item, $supplier, $invitation, $emailEnabled);
            })->values();

            $sentCount = $supplierRows->where('status_key', 'sent')->count();
            $failedCount = $supplierRows->where('status_key', 'failed')->count();
            $queuedCount = $supplierRows->where('status_key', 'queued')->count();
            $quoteCount = $supplierRows->where('quotation_received', true)->count();
            $readyRows = $supplierRows->filter(fn (array $row): bool => ($row['action']['type'] ?? '') === 'send' && ($row['status_key'] ?? '') === 'ready');
            $failedRows = $supplierRows->filter(fn (array $row): bool => ($row['action']['type'] ?? '') === 'send' && ($row['status_key'] ?? '') === 'failed');

            $headerAction = match (true) {
                $failedRows->isNotEmpty() => [
                    'type' => 'send',
                    'label' => 'Retry failed',
                    'tone' => 'danger',
                    'supplier_ids' => $failedRows->pluck('supplier_id')->map(fn ($id) => (int) $id)->values()->all(),
                ],
                $readyRows->isNotEmpty() => [
                    'type' => 'send',
                    'label' => 'Send '.$readyRows->count().' '.Str::plural('invitation', $readyRows->count()),
                    'tone' => 'primary',
                    'supplier_ids' => $readyRows->pluck('supplier_id')->map(fn ($id) => (int) $id)->values()->all(),
                ],
                default => [
                    'type' => 'view',
                    'label' => 'View suppliers',
                    'tone' => 'secondary',
                    'supplier_ids' => [],
                ],
            };

            return [
                'item_id' => (int) $item->id,
                'product_id' => $product ? (int) $product->id : null,
                'index' => $index + 1,
                'name' => (string) $item->item_name,
                'code' => $product?->productDisplayCode() ?: trim((string) ($product?->code ?? '')),
                'category' => trim((string) ($item->category ?: $product?->parent?->name)),
                'quantity' => (float) ($item->quantity ?? 0),
                'unit' => trim((string) ($item->unit ?: 'units')) ?: 'units',
                'supplier_rows' => $supplierRows,
                'supplier_count' => $supplierRows->count(),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'queued_count' => $queuedCount,
                'quotation_count' => $quoteCount,
                'header_action' => $headerAction,
                'expanded_default' => $index < 2,
                'product_search_haystack' => self::normalise(implode(' ', [
                    (string) $item->item_name,
                    (string) ($product?->code ?? ''),
                    (string) ($item->category ?? ''),
                ])),
            ];
        })->values();

        $stats = [
            'products' => $allGroups->count(),
            'supplier_assignments' => (int) $allGroups->sum('supplier_count'),
            'invitations_sent' => (int) $allGroups->sum('sent_count'),
            'quotations_received' => (int) $allGroups->sum('quotation_count'),
        ];

        $search = self::normalise($search);
        $status = self::normaliseStatus($status);

        $filteredGroups = $allGroups->map(function (array $group) use ($search, $status): ?array {
            $rows = collect($group['supplier_rows'] ?? []);
            $productMatches = $search === '' || str_contains((string) ($group['product_search_haystack'] ?? ''), $search);

            if ($search !== '' && ! $productMatches) {
                $rows = $rows
                    ->filter(fn (array $row): bool => str_contains((string) ($row['search_haystack'] ?? ''), $search))
                    ->values();
                if ($rows->isEmpty()) return null;
            }

            if ($status !== 'all') {
                $rows = $rows->where('status_key', $status)->values();
                if ($rows->isEmpty()) return null;
            }

            $group['supplier_rows'] = $rows;
            return $group;
        })->filter()->values();

        $validKeys = $allGroups
            ->flatMap(fn (array $group): Collection => collect($group['supplier_rows'] ?? [])->where('selectable', true)->pluck('selection_key'))
            ->filter()
            ->unique()
            ->values();

        $selected = collect($selectedKeys)
            ->map(fn ($key) => trim((string) $key))
            ->filter(fn (string $key): bool => $key !== '' && $validKeys->contains($key))
            ->unique()
            ->values();

        $filteredGroups = $filteredGroups->map(function (array $group) use ($selected): array {
            $selectableKeys = collect($group['supplier_rows'] ?? [])
                ->where('selectable', true)
                ->pluck('selection_key')
                ->filter()
                ->values();

            $group['selectable_keys'] = $selectableKeys->all();
            $group['selected_keys'] = $selectableKeys->filter(fn (string $key): bool => $selected->contains($key))->values()->all();
            $group['all_selectable_selected'] = $selectableKeys->isNotEmpty()
                && $selectableKeys->every(fn (string $key): bool => $selected->contains($key));
            return $group;
        })->values();

        $totalProducts = $filteredGroups->count();
        $lastPage = max(1, (int) ceil($totalProducts / self::PER_PAGE));
        $currentPage = max(1, min($page, $lastPage));
        $visibleGroups = $filteredGroups
            ->slice(($currentPage - 1) * self::PER_PAGE, self::PER_PAGE)
            ->values();

        $selectedProducts = $selected
            ->map(fn (string $key): int => (int) Str::before($key, ':'))
            ->filter()
            ->unique()
            ->count();

        return [
            'stats' => $stats,
            'groups' => $visibleGroups,
            'product_count' => $allGroups->count(),
            'filtered_product_count' => $totalProducts,
            'selected_keys' => $selected->all(),
            'selected_count' => $selected->count(),
            'selected_product_count' => $selectedProducts,
            'status_filter' => $status,
            'status_filter_options' => self::statusFilters(),
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $lastPage,
            'email_enabled' => $emailEnabled,
        ];
    }

    /** @return array<string,string> */
    public static function statusFilters(): array
    {
        return [
            'all' => 'All invitation statuses',
            'ready' => 'Ready to send',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'missing' => 'Email not set',
            'queued' => 'Queued',
            'disabled' => 'Email disabled',
        ];
    }

    /** @return array<string,mixed> */
    private static function supplierRow(
        InquiryItem $item,
        MasterRecord $supplier,
        ?InquiryRfqInvitation $invitation,
        bool $emailEnabled,
    ): array {
        $email = trim((string) data_get($supplier->metadata, 'email'));
        $emailReady = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $rawStatus = strtolower(trim((string) ($invitation?->email_status ?? '')));

        [$statusKey, $statusLabel, $tone, $detail] = match (true) {
            ! $invitation && ! $emailReady => ['missing', 'Email not set', 'warning', null],
            ! $invitation && ! $emailEnabled => ['disabled', 'Email disabled', 'neutral', null],
            ! $invitation => ['ready', 'Ready to send', 'success', null],
            $rawStatus === 'delivered' => ['sent', 'Sent', 'success', null],
            $rawStatus === 'failed' => ['failed', 'Failed', 'danger', null],
            in_array($rawStatus, ['sending', 'queued'], true) => ['queued', 'Queued', 'neutral', null],
            $rawStatus === 'no email' => ['missing', 'Email not set', 'warning', null],
            $rawStatus === 'email disabled' => $emailReady && $emailEnabled
                ? ['ready', 'Ready to send', 'success', null]
                : ($emailReady ? ['disabled', 'Email disabled', 'neutral', null] : ['missing', 'Email not set', 'warning', null]),
            in_array($rawStatus, ['draft', 'pending', ''], true) => $emailReady && $emailEnabled
                ? ['ready', 'Ready to send', 'success', null]
                : ($emailReady ? ['disabled', 'Email disabled', 'neutral', null] : ['missing', 'Email not set', 'warning', null]),
            default => ['neutral', Str::headline((string) ($invitation?->email_status ?: 'Not sent')), 'neutral', null],
        };

        $quotationReceived = self::quotationCoversItem($item, $invitation);
        $closed = (bool) ($invitation?->awarded_at || $invitation?->rejected_at);
        $lastActivity = collect([
            $invitation?->quote_submitted_at,
            $invitation?->invited_at,
            $invitation?->quote?->updated_at,
            $invitation?->updated_at,
        ])
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->sortDesc()
            ->first();

        $action = match (true) {
            $closed => ['type' => 'disabled', 'label' => 'Completed', 'tone' => 'neutral'],
            $statusKey === 'missing' => ['type' => 'setup_email', 'label' => 'Set up email', 'tone' => 'warning'],
            $statusKey === 'queued' => ['type' => 'disabled', 'label' => 'Sending…', 'tone' => 'neutral'],
            $statusKey === 'disabled' => ['type' => 'disabled', 'label' => 'Email disabled', 'tone' => 'neutral'],
            $statusKey === 'failed' => ['type' => 'send', 'label' => 'Retry', 'tone' => 'danger'],
            $statusKey === 'sent' => ['type' => 'send', 'label' => 'Resend', 'tone' => 'success'],
            default => ['type' => 'send', 'label' => 'Send invitation', 'tone' => 'success'],
        };

        return [
            'selection_key' => ((int) $item->id).':'.((int) $supplier->id),
            'item_id' => (int) $item->id,
            'supplier_id' => (int) $supplier->id,
            'invitation_id' => $invitation ? (int) $invitation->id : null,
            'supplier_name' => (string) $supplier->name,
            'supplier_short_code' => $supplier->supplierShortCode(),
            'supplier_code' => trim((string) $supplier->code),
            'email' => $email,
            'status_key' => $statusKey,
            'status_label' => $statusLabel,
            'status_tone' => $tone,
            'status_detail' => $detail,
            'quotation_received' => $quotationReceived,
            'last_activity_at' => $lastActivity,
            'action' => $action,
            'selectable' => ! $closed && $emailReady && $emailEnabled && $statusKey !== 'queued',
            'search_haystack' => self::normalise(implode(' ', [
                (string) $supplier->name,
                $supplier->supplierShortCode(),
                (string) $supplier->code,
                $email,
                $statusLabel,
                $quotationReceived ? 'quotation received' : '',
            ])),
        ];
    }

    private static function quotationCoversItem(InquiryItem $item, ?InquiryRfqInvitation $invitation): bool
    {
        if (! $invitation || strtolower(trim((string) $invitation->quote_status)) !== 'submitted') return false;
        if (! $invitation->quote) return true;
        if (! $invitation->quote->relationLoaded('items')) return true;

        $quoteItems = collect($invitation->quote->items);
        return $quoteItems->isEmpty()
            || $quoteItems->contains(fn ($quoteItem): bool => (int) $quoteItem->inquiry_item_id === (int) $item->id);
    }

    private static function normaliseStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return array_key_exists($status, self::statusFilters()) ? $status : 'all';
    }

    private static function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
