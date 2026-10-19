<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\Inquiries\InquiryRfqService;
use App\Services\MasterDataService;
use App\Services\WorkspaceSettingsService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps Create Inquiry RFQ state and delivery isolated from the main creation
 * workflow. Product selection may seed the RFQ with its Product Master default
 * Supplier, while invitation delivery still happens only after persistence.
 */
trait ManagesInquiryCreateRfq
{
    private function initialiseCreateRfqState(): void
    {
        if ($this->createRfqDueDate === '') {
            $this->createRfqDueDate = app(WorkspaceSettingsService::class)
                ->localToday()
                ->addDays(7)
                ->toDateString();
        }

        if (trim($this->createRfqMessage) === '') {
            $this->createRfqMessage = $this->defaultCreateRfqMessage();
        }
    }

    private function resetCreateRfqState(): void
    {
        $this->createRfqSupplierSearch = '';
        $this->createRfqSupplierIds = [];
        $this->createRfqDueDate = app(WorkspaceSettingsService::class)
            ->localToday()
            ->addDays(7)
            ->toDateString();
        $this->createRfqMessage = $this->defaultCreateRfqMessage();
        $this->createProductRfqRows = [];
    }

    public function removeCreateRfqSupplier(int $supplierId): void
    {
        $this->createRfqSupplierIds = collect($this->createRfqSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $supplierId)
            ->unique()
            ->values()
            ->all();

        $this->resetValidation('createRfqSupplierIds');
    }

    /**
     * Add the Product Master's default Supplier to the Create Inquiry RFQ
     * selection when a Product is added. The RFQ service remains the source of
     * truth for supplier eligibility, so inactive/deleted Supplier records are
     * never injected into the form state. Existing manual selections are kept
     * in their current order and duplicates are avoided.
     */
    private function autoSelectCreateRfqDefaultSupplier(MasterRecord $product): void
    {
        $supplierId = $product->productSupplierId();
        if (! $supplierId) return;

        $selectedIds = collect($this->createRfqSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($selectedIds->contains($supplierId)) {
            $this->createRfqSupplierIds = $selectedIds->all();
            return;
        }

        // Keep the existing RFQ participant limit intact. With at most 25
        // Product rows, normal default-supplier auto-selection stays within it.
        if ($selectedIds->count() >= 25) return;

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $isSelectable = app(InquiryRfqService::class)
            ->selectableSuppliersByIds($workspaceId, [$supplierId])
            ->contains(fn (MasterRecord $supplier): bool => (int) $supplier->id === $supplierId);

        if (! $isSelectable) return;

        $selectedIds->push($supplierId);
        $this->createRfqSupplierIds = $selectedIds->values()->all();
        $this->resetValidation('createRfqSupplierIds');
    }

    /** @return array{supplier_ids:array<int,int>,due_date:string,message:string,send_on_create:bool} */
    private function newCreateProductRfqState(?MasterRecord $product = null): array
    {
        $supplierIds = [];
        if ($product) {
            $supplierId = (int) ($product->productSupplierId() ?: 0);
            if ($supplierId > 0) {
                $workspaceId = app(MasterDataService::class)->workspaceId();
                $selectable = app(InquiryRfqService::class)
                    ->selectableSuppliersByIds($workspaceId, [$supplierId])
                    ->contains(fn (MasterRecord $supplier): bool => (int) $supplier->id === $supplierId);
                if ($selectable) $supplierIds[] = $supplierId;
            }
        }

        return [
            'supplier_ids' => $supplierIds,
            'due_date' => app(WorkspaceSettingsService::class)->localToday()->addDays(7)->toDateString(),
            'message' => $this->defaultCreateRfqMessage(),
            'send_on_create' => true,
        ];
    }

    /** @return array{supplier_ids:array<int,int>,due_date:string,message:string,send_on_create:bool} */
    private function normaliseCreateProductRfqState(array $state): array
    {
        return [
            'supplier_ids' => collect($state['supplier_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->take(25)->values()->all(),
            'due_date' => trim((string) ($state['due_date'] ?? '')) ?: app(WorkspaceSettingsService::class)->localToday()->addDays(7)->toDateString(),
            'message' => trim((string) ($state['message'] ?? '')) ?: $this->defaultCreateRfqMessage(),
            'send_on_create' => (bool) ($state['send_on_create'] ?? true),
        ];
    }

    public function addCreateProductRfqSupplierFromSelector(string $property, mixed $supplierId): array
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(preg_match('/^create-product-rfq-supplier:(\d+)$/', $property, $matches) === 1, 422, 'Invalid RFQ product selector.');
        $index = (int) $matches[1];
        abort_unless(array_key_exists($index, $this->createProductRows), 422, 'That product is no longer available.');

        $supplierId = (int) $supplierId;
        abort_if($supplierId <= 0, 422, 'Choose a supplier.');
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $supplier = app(InquiryRfqService::class)
            ->selectableSuppliersByIds($workspaceId, [$supplierId])
            ->first(fn (MasterRecord $candidate): bool => (int) $candidate->id === $supplierId);
        abort_unless($supplier, 422, 'That supplier is no longer active or available.');

        $state = $this->normaliseCreateProductRfqState($this->createProductRfqRows[$index] ?? []);
        $ids = collect($state['supplier_ids']);
        if (! $ids->contains($supplierId)) {
            abort_if($ids->count() >= 25, 422, 'A product can contain up to 25 RFQ suppliers.');
            $ids->push($supplierId);
        }
        $state['supplier_ids'] = $ids->unique()->values()->all();
        $this->createProductRfqRows[$index] = $state;
        $this->syncLegacyCreateRfqState();
        $this->resetValidation("createProductRfqRows.$index.supplier_ids");

        return ['id' => (string) $supplier->id, 'label' => $supplier->supplierShortCode()];
    }

    public function removeCreateProductRfqSupplier(int $index, int $supplierId): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(array_key_exists($index, $this->createProductRows), 422);
        $state = $this->normaliseCreateProductRfqState($this->createProductRfqRows[$index] ?? []);
        $state['supplier_ids'] = collect($state['supplier_ids'])
            ->reject(fn (int $id): bool => $id === $supplierId)
            ->values()->all();
        $this->createProductRfqRows[$index] = $state;
        $this->syncLegacyCreateRfqState();
        $this->resetValidation("createProductRfqRows.$index.supplier_ids");
    }

    public function updatedCreateProductRfqRows(mixed $value = null, ?string $key = null): void
    {
        if (! $this->showCreate) return;
        $this->syncLegacyCreateRfqState();
    }

    /**
     * Keep the original flat RFQ state synchronized for backwards compatibility
     * with code paths outside the new product-scoped composer.
     */
    private function syncLegacyCreateRfqState(): void
    {
        $states = collect($this->createProductRfqRows)
            ->map(fn ($state) => $this->normaliseCreateProductRfqState(is_array($state) ? $state : []));
        $this->createRfqSupplierIds = $states->flatMap(fn (array $state) => $state['supplier_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($first = $states->first()) {
            $this->createRfqDueDate = (string) $first['due_date'];
            $this->createRfqMessage = (string) $first['message'];
        }
    }

    /**
     * Validate the product-scoped composer in one supplier lookup and collapse it
     * to one invitation per supplier, preserving the current Inquiry RFQ schema.
     * If a supplier appears on several products, "send on create" wins and the
     * first matching product supplies the due date/message.
     *
     * @return array<int,array{supplier_id:int,due_date:string,message:string,send_on_create:bool}>|null
     */
    private function validatedCreateProductRfqPlan(): ?array
    {
        $states = collect($this->createProductRows)->map(function (array $row, int $index): array {
            $state = $this->normaliseCreateProductRfqState($this->createProductRfqRows[$index] ?? []);
            $this->createProductRfqRows[$index] = $state;
            return $state;
        })->values();

        $allIds = $states->flatMap(fn (array $state) => $state['supplier_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($allIds->isNotEmpty()) {
            $workspaceId = app(MasterDataService::class)->workspaceId();
            $available = app(InquiryRfqService::class)->selectableSuppliersByIds($workspaceId, $allIds->all())
                ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            if ($available->all() !== $allIds->sort()->values()->all()) {
                $this->addError('createProductRfqRows', 'One or more selected suppliers are no longer active or available.');
                return null;
            }
        }

        $today = app(WorkspaceSettingsService::class)->localToday()->startOfDay();
        $invalid = false;
        foreach ($states as $index => $state) {
            if ($state['supplier_ids'] === []) continue;
            if ($state['due_date'] === '') {
                $this->addError("createProductRfqRows.$index.due_date", 'Choose a quotation due date.');
                $invalid = true;
                continue;
            }
            try {
                $due = Carbon::createFromFormat('Y-m-d', $state['due_date'])->startOfDay();
                if ($due->lt($today)) {
                    $this->addError("createProductRfqRows.$index.due_date", 'Quotation due date cannot be in the past.');
                    $invalid = true;
                }
            } catch (Throwable) {
                $this->addError("createProductRfqRows.$index.due_date", 'Choose a valid quotation due date.');
                $invalid = true;
            }
            if (mb_strlen($state['message']) > 5000) {
                $this->addError("createProductRfqRows.$index.message", 'RFQ message may not exceed 5,000 characters.');
                $invalid = true;
            }
        }
        if ($invalid) return null;

        $plan = [];
        foreach ($states as $rowIndex => $state) {
            foreach ($state['supplier_ids'] as $supplierId) {
                $supplierId = (int) $supplierId;
                if (! isset($plan[$supplierId])) {
                    $plan[$supplierId] = [
                        'supplier_id' => $supplierId,
                        'due_date' => $state['due_date'],
                        'message' => $state['message'],
                        'send_on_create' => (bool) $state['send_on_create'],
                        'row_indexes' => [$rowIndex],
                    ];
                    continue;
                }
                $plan[$supplierId]['row_indexes'][] = $rowIndex;
                if ($state['send_on_create'] && ! $plan[$supplierId]['send_on_create']) {
                    $plan[$supplierId]['send_on_create'] = true;
                    $plan[$supplierId]['due_date'] = $state['due_date'];
                    $plan[$supplierId]['message'] = $state['message'];
                }
            }
        }

        return array_values($plan);
    }

    /** @param array<int,array{supplier_id:int,due_date:string,message:string,send_on_create:bool}> $plan */
    private function sendCreateProductRfqPlan(Inquiry $inquiry, User $actor, array $plan): array
    {
        $result = ['sent' => 0, 'drafted' => 0, 'added_without_email' => 0, 'failed' => 0];
        // Creation preserves the original product-row sequence as sort_order (zero based).
        $itemIdsByRow = $inquiry->items()->pluck('id', 'sort_order');
        foreach ($plan as $entry) {
            try {
                $dueAt = Carbon::createFromFormat('Y-m-d', $entry['due_date'])->endOfDay();
                $invitation = app(InquiryRfqService::class)->invite(
                    $inquiry,
                    (int) $entry['supplier_id'],
                    $actor,
                    $dueAt,
                    trim((string) $entry['message']) ?: null,
                    (bool) $entry['send_on_create'],
                    collect($entry['row_indexes'])->map(fn ($index) => (int) $itemIdsByRow->get($index))
                        ->filter(fn ($id) => $id > 0)->unique()->values()->all(),
                );
                if (! $entry['send_on_create']) $result['drafted']++;
                elseif ($invitation->email_status === 'Delivered') $result['sent']++;
                else $result['added_without_email']++;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }
        return $result;
    }

    /**
     * Validate supplier IDs against the same RFQ service used by the detail page.
     * Email availability is intentionally not an eligibility rule: active
     * suppliers may be selected and retained in the RFQ even before an email is
     * configured.
     *
     * @return array<int,int>
     */
    private function validatedCreateRfqSupplierIds(): array
    {
        $ids = collect($this->createRfqSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $available = app(InquiryRfqService::class)
            ->selectableSuppliersByIds($workspaceId, $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = collect($ids)->sort()->values()->all();
        if ($available !== $expected) {
            $this->addError('createRfqSupplierIds', 'One or more selected suppliers are no longer active or available.');
            return [];
        }

        return $ids;
    }

    /**
     * Send invitations after the Inquiry and its products/tasks/documents exist.
     * External email delivery is deliberately not wrapped in the Inquiry database
     * transaction; one provider failure must not roll back an already-created
     * Inquiry or duplicate messages that were accepted successfully.
     *
     * @param array<int,int> $supplierIds
     * @return array{sent:int,added_without_email:int,failed:int}
     */
    private function sendCreateRfqInvitations(Inquiry $inquiry, User $actor, array $supplierIds): array
    {
        if ($supplierIds === []) {
            return ['sent' => 0, 'added_without_email' => 0, 'failed' => 0];
        }

        $dueAt = $this->createRfqDueDate !== ''
            ? Carbon::createFromFormat('Y-m-d', $this->createRfqDueDate)->endOfDay()
            : null;
        $message = trim($this->createRfqMessage);
        $sent = 0;
        $addedWithoutEmail = 0;
        $failed = 0;

        foreach ($supplierIds as $supplierId) {
            try {
                $invitation = app(InquiryRfqService::class)->invite(
                    $inquiry,
                    (int) $supplierId,
                    $actor,
                    $dueAt,
                    $message !== '' ? $message : null,
                );
                if ($invitation->email_status === 'Delivered') {
                    $sent++;
                } else {
                    $addedWithoutEmail++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['sent' => $sent, 'added_without_email' => $addedWithoutEmail, 'failed' => $failed];
    }

    private function defaultCreateRfqMessage(): string
    {
        return 'Please quote your best unit price, lead time, shipping and sample options.';
    }
}
