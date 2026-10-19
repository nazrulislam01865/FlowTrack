<?php

namespace App\Services\Inquiries;

use App\Models\InquiryItem;
use App\Models\InquiryRfqInvitation;
use App\Models\InquiryRfqQuote;
use App\Models\InquiryRfqQuoteDocument;
use App\Models\MasterRecord;
use App\Services\SecureDocumentStorage;
use App\Support\StoredFileResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PublicRfqPortalService
{
    public const STEPS = ['details', 'pricing', 'documents', 'review'];
    public const DOCUMENT_TYPES = [
        'formal_quotation' => 'Formal quotation',
        'price_breakdown' => 'Price breakdown',
        'product_specification' => 'Product specification',
        'product_certification' => 'Product certification',
        'factory_audit_report' => 'Factory audit report',
        'sample_images' => 'Sample images',
        'other' => 'Other document',
    ];
    public const REQUIRED_DOCUMENT_TYPES = ['formal_quotation', 'price_breakdown'];
    public const SUPPORTING_INFORMATION = [
        'product_certification' => 'Product certification',
        'factory_audit_report' => 'Factory audit report',
        'sample_images' => 'Sample images',
    ];

    public function normalizeStep(?string $step, InquiryRfqInvitation $invitation): string
    {
        if ($invitation->quote_status === 'submitted' || $invitation->awarded_at || $invitation->rejected_at || $invitation->interest_status === 'declined') {
            return 'review';
        }

        $step = strtolower(trim((string) $step));
        return in_array($step, self::STEPS, true) ? $step : 'details';
    }

    /** @return array<string,mixed> */
    public function viewData(InquiryRfqInvitation $invitation, string $token, string $step): array
    {
        $invitation->loadMissing([
            'supplier:id,name,code,metadata,status',
            'inviter:id,name,email',
            'inquiry.client:id,name',
            'inquiry.items:id,inquiry_id,item_name,category,quantity,unit,unit_price,notes,sort_order',
            'quote.items',
            'quote.documents',
        ]);

        $inquiry = $invitation->inquiry;
        $quote = $invitation->quote;
        $products = $this->products($invitation, $token);
        $quoteItems = collect($quote?->items ?? [])->keyBy('inquiry_item_id');
        $documents = collect($quote?->documents ?? []);

        $productSubtotal = $products->sum(function (array $product) use ($quoteItems): float {
            $quoteItem = $quoteItems->get($product['item_id']);
            return (float) $product['quantity'] * (float) ($quoteItem?->unit_price ?? 0);
        });
        $sampleCost = (float) ($quote?->sample_cost ?? 0);
        $toolingCost = (float) ($quote?->tooling_cost ?? 0);
        $freight = (float) ($quote?->freight ?? 0);
        $discount = (float) ($quote?->discount ?? 0);
        $otherCosts = $toolingCost + $freight - $discount;
        $total = round($productSubtotal + $sampleCost + $otherCosts, 2);

        $detailsComplete = (bool) ($quote && filled($quote->supplier_contact_name) && filled($quote->supplier_contact_email));
        $pricingComplete = (bool) ($quote && $products->isNotEmpty() && $products->every(
            fn (array $product): bool => $quoteItems->has((int) $product['item_id']),
        ));
        $requiredDocumentCount = collect(self::REQUIRED_DOCUMENT_TYPES)
            ->filter(fn (string $type): bool => $documents->contains('document_type', $type))
            ->count();
        $documentsComplete = $requiredDocumentCount === count(self::REQUIRED_DOCUMENT_TYPES);
        $readyToSubmit = $detailsComplete && $pricingComplete && $documentsComplete;
        $submitted = $invitation->quote_status === 'submitted' && (bool) $quote;
        $revisionWindowOpen = ! $this->linkExpired($invitation);
        $canRevise = $submitted
            && ! $invitation->awarded_at
            && ! $invitation->rejected_at
            && $invitation->interest_status !== 'declined'
            && (bool) ($invitation->allow_revision ?? true)
            && $revisionWindowOpen;
        $locked = $submitted
            || (bool) $invitation->awarded_at
            || (bool) $invitation->rejected_at
            || $invitation->interest_status === 'declined';

        $supplierMetadata = (array) ($invitation->supplier?->metadata ?? []);
        $contactName = trim((string) ($quote?->supplier_contact_name ?: data_get($supplierMetadata, 'contact_person')))
            ?: (string) ($invitation->supplier?->name ?: 'Supplier');
        $contactEmail = trim((string) ($quote?->supplier_contact_email ?: data_get($supplierMetadata, 'email')));
        $contactPhone = trim((string) ($quote?->supplier_contact_phone
            ?: data_get($supplierMetadata, 'phone')
            ?: data_get($supplierMetadata, 'telephone')
            ?: data_get($supplierMetadata, 'mobile')));

        $firstProduct = $products->first();
        $currency = strtoupper(trim((string) ($quote?->currency ?: $inquiry?->currency ?: 'USD'))) ?: 'USD';
        return [
            'step' => $step,
            'steps' => collect(self::STEPS)->map(fn (string $key, int $index): array => [
                'key' => $key,
                'number' => $index + 1,
                'label' => match ($key) {
                    'details' => 'Product details',
                    'pricing' => 'Pricing & terms',
                    'documents' => 'Documents',
                    'review' => 'Review & submit',
                },
                'complete' => match ($key) {
                    'details' => $detailsComplete,
                    'pricing' => $pricingComplete,
                    'documents' => $documentsComplete,
                    'review' => false,
                },
                'active' => $key === $step,
            ])->values(),
            'locked' => $locked,
            'submitted' => $submitted,
            'canRevise' => $canRevise,
            'quote' => $quote,
            'products' => $products,
            'firstProduct' => $firstProduct,
            'currency' => $currency,
            'totalQuantity' => (float) $products->sum('quantity'),
            'contact' => ['name' => $contactName, 'email' => $contactEmail, 'phone' => $contactPhone],
            'rfqReference' => 'RFQ-'.str_pad((string) $invitation->id, 6, '0', STR_PAD_LEFT),
            'productSubtotal' => round($productSubtotal, 2),
            'sampleCost' => round($sampleCost, 2),
            'otherCosts' => round($otherCosts, 2),
            'totalQuotedValue' => $total,
            'detailsComplete' => $detailsComplete,
            'pricingComplete' => $pricingComplete,
            'documentsComplete' => $documentsComplete,
            'readyToSubmit' => $readyToSubmit,
            'requiredDocumentCount' => $requiredDocumentCount,
            'requiredDocumentTotal' => count(self::REQUIRED_DOCUMENT_TYPES),
            'documents' => $documents,
            'documentTypes' => self::DOCUMENT_TYPES,
            'requiredDocumentTypes' => self::REQUIRED_DOCUMENT_TYPES,
            'supportingInformationOptions' => self::SUPPORTING_INFORMATION,
            'supportingInformation' => collect($quote?->supporting_information ?? [])->map(fn ($value) => (string) $value)->all(),
            'savedAt' => $quote?->updated_at,
            'clientName' => trim((string) ($inquiry?->client?->name ?? '')) ?: 'the buyer',
            'buyerEmail' => trim((string) ($invitation->inviter?->email ?? '')),
        ];
    }

    public function touchDraft(InquiryRfqInvitation $invitation): InquiryRfqQuote
    {
        $this->assertEditable($invitation);
        $quote = $this->ensureDraftQuote($invitation);
        $quote->touch();
        return $quote->fresh(['items', 'documents']);
    }

    /** @param array<string,mixed> $data */
    public function saveDetails(InquiryRfqInvitation $invitation, array $data): InquiryRfqQuote
    {
        $this->assertEditable($invitation);
        $quote = $this->ensureDraftQuote($invitation);
        $quote->update([
            'supplier_contact_name' => trim((string) ($data['supplier_contact_name'] ?? '')),
            'supplier_contact_email' => trim((string) ($data['supplier_contact_email'] ?? '')),
            'supplier_contact_phone' => trim((string) ($data['supplier_contact_phone'] ?? '')) ?: null,
        ]);

        return $quote->fresh(['items', 'documents']);
    }

    /** @param array<string,mixed> $data */
    public function savePricing(InquiryRfqInvitation $invitation, array $data): InquiryRfqQuote
    {
        $this->assertEditable($invitation);
        $inquiry = $invitation->inquiry()->with('items:id,inquiry_id,item_name,quantity,sort_order')->firstOrFail();
        $scopedIds = $invitation->scopedItemIds();
        $sourceItems = ($scopedIds === [] ? $inquiry->items : $inquiry->items->whereIn('id', $scopedIds))->keyBy('id');
        $prices = collect($data['prices'] ?? [])->mapWithKeys(fn ($value, $key) => [(int) $key => (float) $value]);
        $moqs = collect($data['moqs'] ?? [])->mapWithKeys(fn ($value, $key) => [(int) $key => $value === null || $value === '' ? null : (float) $value]);

        abort_unless($sourceItems->keys()->every(fn ($id): bool => $prices->has((int) $id)), 422, 'Enter a unit price for every product.');

        return DB::transaction(function () use ($invitation, $data, $sourceItems, $prices, $moqs): InquiryRfqQuote {
            $quote = $this->ensureDraftQuote($invitation);
            $quote->update([
                'currency' => strtoupper(trim((string) ($data['currency'] ?? 'USD'))) ?: 'USD',
                'freight' => $this->money($data['freight'] ?? 0),
                'tooling_cost' => $this->money($data['tooling_cost'] ?? 0),
                'sample_cost' => $this->money($data['sample_cost'] ?? 0),
                'discount' => $this->money($data['discount'] ?? 0),
                'tax_status' => trim((string) ($data['tax_status'] ?? 'excluded')) ?: 'excluded',
                'lead_time_days' => filled($data['lead_time_days'] ?? null) ? max(0, (int) $data['lead_time_days']) : null,
                'sample_lead_time_days' => filled($data['sample_lead_time_days'] ?? null) ? max(0, (int) $data['sample_lead_time_days']) : null,
                'incoterm' => trim((string) ($data['incoterm'] ?? '')) ?: null,
                'shipping_port' => trim((string) ($data['shipping_port'] ?? '')) ?: null,
                'estimated_delivery_date' => filled($data['estimated_delivery_date'] ?? null) ? $data['estimated_delivery_date'] : null,
                'validity_days' => filled($data['validity_days'] ?? null) ? max(0, (int) $data['validity_days']) : null,
                'specification_compliance' => trim((string) ($data['specification_compliance'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'document_notes' => array_key_exists('document_notes', $data)
                    ? (trim((string) $data['document_notes']) ?: null)
                    : $quote->document_notes,
            ]);

            $quote->items()->delete();
            $now = now();
            $rows = $sourceItems->values()->map(function (InquiryItem $source) use ($quote, $prices, $moqs, $now): array {
                return [
                    'quote_id' => $quote->id,
                    'inquiry_item_id' => (int) $source->id,
                    'product_name' => (string) $source->item_name,
                    'quantity' => (float) $source->quantity,
                    'unit_price' => round((float) $prices->get((int) $source->id), 4),
                    'moq' => $moqs->get((int) $source->id),
                    'sort_order' => (int) $source->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();
            $quote->items()->insert($rows);

            $subtotal = collect($rows)->sum(fn (array $row): float => (float) $row['quantity'] * (float) $row['unit_price']);
            $submittedTotal = round(
                $subtotal
                + (float) $quote->sample_cost
                + (float) $quote->tooling_cost
                + (float) $quote->freight
                - (float) $quote->discount,
                2,
            );
            $quote->update(['submitted_total' => $submittedTotal]);

            return $quote->fresh(['items', 'documents']);
        });
    }

    /** @param array<string,mixed> $data */
    public function saveDocumentStep(InquiryRfqInvitation $invitation, array $data): InquiryRfqQuote
    {
        $this->assertEditable($invitation);
        $quote = $this->ensureDraftQuote($invitation);

        $types = collect($data['document_types'] ?? []);
        if ($types->isNotEmpty()) {
            $quote->documents->each(function (InquiryRfqQuoteDocument $document) use ($types): void {
                $type = (string) $types->get((string) $document->id, $types->get($document->id, $document->document_type));
                if (array_key_exists($type, self::DOCUMENT_TYPES)) {
                    $document->update(['document_type' => $type]);
                }
            });
        }

        $supporting = collect($data['supporting_information'] ?? [])
            ->map(fn ($value) => (string) $value)
            ->filter(fn (string $value): bool => array_key_exists($value, self::SUPPORTING_INFORMATION))
            ->unique()
            ->values()
            ->all();

        $quote->update([
            'supporting_information' => $supporting === [] ? null : $supporting,
            'document_notes' => trim((string) ($data['document_notes'] ?? '')) ?: null,
        ]);

        return $quote->fresh(['items', 'documents']);
    }

    /** @param array<int,UploadedFile> $files */
    public function uploadDocuments(InquiryRfqInvitation $invitation, array $files): Collection
    {
        $this->assertEditable($invitation);
        $quote = $this->ensureDraftQuote($invitation);
        $existingTypes = $quote->documents()->pluck('document_type')->all();
        $sortOrder = (int) $quote->documents()->max('sort_order');
        $created = collect();
        $storedPaths = collect();

        try {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) continue;

                $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/rfq/'.$invitation->id.'/'.$quote->id);
                $storedPaths->push((string) $stored['path']);
                $type = $this->nextDocumentType($existingTypes);
                $sortOrder++;
                $document = $quote->documents()->create([
                    'document_type' => $type,
                    'name' => basename($file->getClientOriginalName()),
                    'path' => $stored['path'],
                    'mime_type' => StoredFileResponse::mimeType($file->getClientOriginalName(), $stored['mime']),
                    'size' => $stored['size'],
                    'sort_order' => $sortOrder,
                ]);
                $existingTypes[] = $type;
                $created->push($document);
            }
        } catch (\Throwable $exception) {
            $created->each(fn (InquiryRfqQuoteDocument $document) => $document->delete());
            $storedPaths->each(fn (string $path) => app(SecureDocumentStorage::class)->delete($path));
            throw $exception;
        }

        return $created;
    }

    public function removeDocument(InquiryRfqInvitation $invitation, int $documentId): void
    {
        $this->assertEditable($invitation);
        $document = $this->documentForInvitation($invitation, $documentId);
        app(SecureDocumentStorage::class)->delete((string) $document->path);
        $document->delete();
    }

    public function documentForInvitation(InquiryRfqInvitation $invitation, int $documentId): InquiryRfqQuoteDocument
    {
        return InquiryRfqQuoteDocument::query()
            ->whereKey($documentId)
            ->whereHas('quote', fn ($query) => $query->where('invitation_id', $invitation->id))
            ->firstOrFail();
    }

    public function productImagePath(InquiryRfqInvitation $invitation, int $itemId): ?string
    {
        $item = $invitation->inquiry?->items?->firstWhere('id', $itemId);
        if (! $item || (($scopedIds = $invitation->scopedItemIds()) !== [] && ! in_array($itemId, $scopedIds, true))) return null;

        $query = MasterRecord::query()
            ->forWorkspace((int) $invitation->workspace_id)
            ->ofType('product')
            ->where('name', (string) $item->item_name)
            ->with('parent:id,name');
        $products = $query->get(['id', 'workspace_id', 'parent_id', 'type', 'name', 'metadata']);
        $category = mb_strtolower(trim((string) $item->category));
        $product = $category !== ''
            ? $products->first(fn (MasterRecord $candidate): bool => mb_strtolower(trim((string) $candidate->parent?->name)) === $category)
            : $products->first();
        if (! $product) $product = $products->first();
        if (! $product) return null;

        $path = trim((string) data_get($product->metadata, 'product_image_path'));
        $expectedPrefix = 'product-images/'.$product->workspace_id.'/'.$product->id.'/';
        return $path !== '' && str_starts_with($path, $expectedPrefix) ? $path : null;
    }

    public function ensureDraftQuote(InquiryRfqInvitation $invitation): InquiryRfqQuote
    {
        $supplier = $invitation->supplier;
        $metadata = (array) ($supplier?->metadata ?? []);
        $quote = InquiryRfqQuote::query()->firstOrCreate(
            ['invitation_id' => $invitation->id],
            [
                'supplier_contact_name' => trim((string) data_get($metadata, 'contact_person')) ?: (string) ($supplier?->name ?: 'Supplier'),
                'supplier_contact_email' => trim((string) data_get($metadata, 'email')) ?: null,
                'supplier_contact_phone' => trim((string) (data_get($metadata, 'phone') ?: data_get($metadata, 'telephone') ?: data_get($metadata, 'mobile'))) ?: null,
                'currency' => strtoupper(trim((string) ($invitation->inquiry?->currency ?: 'USD'))) ?: 'USD',
                'freight' => 0,
                'tooling_cost' => 0,
                'sample_cost' => 0,
                'discount' => 0,
                'tax_status' => 'excluded',
                'submitted_total' => 0,
            ],
        );
        $invitation->setRelation('quote', $quote);

        return $quote;
    }

    private function assertEditable(InquiryRfqInvitation $invitation): void
    {
        abort_if($invitation->awarded_at || $invitation->rejected_at, 422, 'This RFQ is already closed.');
        abort_if($invitation->quote_status === 'submitted', 422, 'This quotation has already been submitted and can no longer be edited.');
        abort_if($invitation->interest_status === 'declined', 422, 'This quotation request has been declined.');
        abort_if($this->linkExpired($invitation), 422, 'This quotation link has expired.');
    }

    /** @return Collection<int,array<string,mixed>> */
    private function products(InquiryRfqInvitation $invitation, string $token): Collection
    {
        $scopedIds = $invitation->scopedItemIds();
        $items = collect($invitation->inquiry?->items ?? [])->when(
            $scopedIds !== [], fn ($rows) => $rows->whereIn('id', $scopedIds),
        )->values();
        if ($items->isEmpty()) return collect();

        $names = $items->pluck('item_name')->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
        $masters = MasterRecord::query()
            ->forWorkspace((int) $invitation->workspace_id)
            ->ofType('product')
            ->whereIn('name', $names->all())
            ->with('parent:id,name')
            ->get(['id', 'workspace_id', 'parent_id', 'type', 'name', 'code', 'metadata'])
            ->groupBy(fn (MasterRecord $record): string => mb_strtolower(trim((string) $record->name)));

        return $items->map(function (InquiryItem $item) use ($masters, $token): array {
            $candidates = collect($masters->get(mb_strtolower(trim((string) $item->item_name)), collect()));
            $category = mb_strtolower(trim((string) $item->category));
            $product = $category !== ''
                ? $candidates->first(fn (MasterRecord $candidate): bool => mb_strtolower(trim((string) $candidate->parent?->name)) === $category)
                : $candidates->first();
            if (! $product) $product = $candidates->first();
            $imagePath = trim((string) data_get($product?->metadata, 'product_image_path'));
            $expectedImagePrefix = $product ? 'product-images/'.$product->workspace_id.'/'.$product->id.'/' : '';
            $hasSecureImage = $product && $imagePath !== '' && str_starts_with($imagePath, $expectedImagePrefix);

            return [
                'item_id' => (int) $item->id,
                'name' => (string) $item->item_name,
                'category' => trim((string) ($item->category ?: $product?->parent?->name)),
                'quantity' => (float) $item->quantity,
                'unit' => trim((string) ($item->unit ?: 'units')) ?: 'units',
                'code' => $product?->productDisplayCode() ?: trim((string) ($product?->code ?? '')),
                'reference' => $product?->productReferenceCode() ?: '',
                'image_url' => $hasSecureImage
                    ? route('rfq.public.product-image', ['token' => $token, 'item' => $item->id], false)
                    : null,
                // Reference-document access is intentionally opt-in. General Inquiry attachments
                // are not exposed through a supplier token because they can contain internal material.
                'reference_documents' => [],
            ];
        })->values();
    }

    private function linkExpired(InquiryRfqInvitation $invitation): bool
    {
        if ($invitation->link_expires_at) {
            return now()->greaterThan($invitation->link_expires_at);
        }

        return (bool) ($invitation->due_at && now()->greaterThan($invitation->due_at->copy()->addDays(30)));
    }

    /** @param array<int,string> $existingTypes */
    private function nextDocumentType(array $existingTypes): string
    {
        foreach (['formal_quotation', 'price_breakdown', 'product_specification'] as $type) {
            if (! in_array($type, $existingTypes, true)) return $type;
        }
        return 'other';
    }

    private function money(mixed $value): float
    {
        return max(0, round((float) $value, 2));
    }
}
