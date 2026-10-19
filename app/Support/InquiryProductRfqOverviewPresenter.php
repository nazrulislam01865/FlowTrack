<?php

namespace App\Support;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\InquiryRfqInvitation;
use App\Models\MasterRecord;
use Illuminate\Support\Collection;

/**
 * Query-free presenter for Inquiry Details > Overview product/RFQ summary.
 *
 * Data access stays in the page-data/services layer. This class only projects
 * already-loaded Inquiry items, Product Master supplier assignments and RFQ
 * invitation/quotation state into the reusable prototype-matched component.
 */
final class InquiryProductRfqOverviewPresenter
{
    /**
     * @param Collection<string,MasterRecord> $productMasters Product masters keyed by normalized name.
     * @param Collection<int,Collection<int,MasterRecord>> $suppliersByProduct Product id => active suppliers.
     * @param Collection<int,InquiryRfqInvitation> $invitations
     * @return array{stats:array<string,int>,rows:Collection<int,array<string,mixed>>,product_count:int,total_units:float}
     */
    public static function build(
        Inquiry $inquiry,
        Collection $productMasters,
        Collection $suppliersByProduct,
        Collection $invitations,
    ): array {
        $items = collect($inquiry->items ?? collect())
            ->filter(fn (InquiryItem $item): bool => filled($item->item_name))
            ->values();

        $invitationsBySupplier = $invitations
            ->filter(fn ($invitation): bool => $invitation instanceof InquiryRfqInvitation)
            ->keyBy(fn (InquiryRfqInvitation $invitation): int => (int) $invitation->supplier_id);

        $rows = $items->map(function (InquiryItem $item) use ($productMasters, $suppliersByProduct, $invitationsBySupplier): array {
            $product = $productMasters->get(mb_strtolower(trim((string) $item->item_name)));
            $suppliers = $product
                ? collect($suppliersByProduct->get((int) $product->id, collect()))->values()
                : collect();

            $progressCounts = [
                'sent' => 0,
                'failed' => 0,
                'queued' => 0,
                'email_not_set' => 0,
                'not_sent' => 0,
            ];

            $rowInvitations = collect();
            foreach ($suppliers as $supplier) {
                $invitation = $invitationsBySupplier->get((int) $supplier->id);
                if ($invitation) $rowInvitations->push($invitation);
                $status = self::deliveryStatus($supplier, $invitation);
                $progressCounts[$status]++;
            }

            $quotationCount = $rowInvitations
                ->filter(function (InquiryRfqInvitation $invitation) use ($item): bool {
                    if (strtolower(trim((string) $invitation->quote_status)) !== 'submitted') return false;
                    if (! $invitation->quote) return true;
                    if (! $invitation->quote->relationLoaded('items')) return true;

                    $quoteItems = collect($invitation->quote->items);
                    return $quoteItems->isEmpty()
                        || $quoteItems->contains(fn ($quoteItem): bool => (int) $quoteItem->inquiry_item_id === (int) $item->id);
                })
                ->count();

            $updatedAt = collect([
                $item->updated_at,
                ...$rowInvitations->flatMap(fn (InquiryRfqInvitation $invitation): array => [
                    $invitation->updated_at,
                    $invitation->quote?->updated_at,
                ])->all(),
            ])->filter()->sortByDesc(fn ($value): int => method_exists($value, 'getTimestamp') ? (int) $value->getTimestamp() : 0)->first();

            $category = trim((string) ($item->category ?: $product?->parent?->name));
            $code = $product?->productDisplayCode() ?: trim((string) ($product?->code ?? ''));
            $reference = trim((string) ($product?->productReferenceCode() ?? ''));

            return [
                'item_id' => (int) $item->id,
                'name' => (string) $item->item_name,
                'category' => $category,
                'code' => $code,
                'reference' => $reference,
                'image_url' => $product?->productImageUrl(),
                'quantity' => (float) $item->quantity,
                'unit' => trim((string) ($item->unit ?: 'units')) ?: 'units',
                'suppliers' => $suppliers->map(fn (MasterRecord $supplier): array => [
                    'id' => (int) $supplier->id,
                    'name' => (string) $supplier->name,
                    'short_code' => $supplier->supplierShortCode(),
                    'code' => trim((string) $supplier->code),
                    'initials' => self::initials((string) $supplier->name),
                ])->values(),
                'supplier_count' => $suppliers->count(),
                'progress' => collect([
                    self::badge($progressCounts['sent'], 'sent', 'success'),
                    self::badge($progressCounts['failed'], 'failed', 'danger'),
                    self::badge($progressCounts['queued'], 'queued', 'neutral'),
                    self::badge($progressCounts['email_not_set'], 'email not set', 'warning'),
                    self::badge($progressCounts['not_sent'], 'not sent', 'warning'),
                ])->filter()->values(),
                'sent_count' => $progressCounts['sent'],
                'quotation_count' => $quotationCount,
                'updated_at' => $updatedAt,
            ];
        })->values();

        return [
            'stats' => [
                'products' => $rows->count(),
                'supplier_assignments' => (int) $rows->sum('supplier_count'),
                'invitations_sent' => (int) $rows->sum('sent_count'),
                'quotations_received' => (int) $rows->sum('quotation_count'),
            ],
            'rows' => $rows,
            'product_count' => $rows->count(),
            'total_units' => (float) $rows->sum('quantity'),
        ];
    }

    private static function deliveryStatus(MasterRecord $supplier, ?InquiryRfqInvitation $invitation): string
    {
        $emailReady = filter_var(trim((string) data_get($supplier->metadata, 'email')), FILTER_VALIDATE_EMAIL) !== false;
        if (! $invitation) return $emailReady ? 'not_sent' : 'email_not_set';

        return match (strtolower(trim((string) $invitation->email_status))) {
            'delivered', 'sent' => 'sent',
            'failed' => 'failed',
            'sending', 'queued' => 'queued',
            'no email' => 'email_not_set',
            'draft', 'pending', 'email disabled', '' => $emailReady ? 'not_sent' : 'email_not_set',
            default => $emailReady ? 'not_sent' : 'email_not_set',
        };
    }

    /** @return array{count:int,label:string,tone:string}|null */
    private static function badge(int $count, string $label, string $tone): ?array
    {
        return $count > 0 ? ['count' => $count, 'label' => $label, 'tone' => $tone] : null;
    }

    private static function initials(string $name): string
    {
        $parts = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter();
        $initials = $parts
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : '—';
    }
}
