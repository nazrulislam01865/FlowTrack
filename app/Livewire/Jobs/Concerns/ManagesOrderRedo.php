<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\FlowJob;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\OrderRedoService;
use App\Services\RichTextService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesOrderRedo
{
    public bool $showRedoModal = false;
    public int $redoStep = 1;
    public string $redoIssueSource = 'Customer';
    public string $redoIssueCategory = 'Artwork / production mismatch';
    public string $redoAffectedQuantity = '1';
    public string $redoIssueDescription = '';
    public string $redoScope = 'artwork';
    public string $redoQuantity = '1';
    public ?int $redoSupplierId = null;
    public string $redoInstructions = '';
    public string $redoCustomerResolution = 'free';
    public string $redoCustomerAdjustmentType = 'percent';
    public string $redoCustomerDiscount = '20';
    public string $redoSupplierAdjustmentType = 'percent';
    public string $redoSupplierChargePercent = '40';
    public bool $redoDeductFreight = true;
    public string $redoSupplierFreightAmount = '';
    public string $redoCourierFreightAmount = '';
    /** @var array<int,array{id:int,label:string}> */
    public array $redoSupplierOptions = [];
    /** @var array<int,string> */
    public array $redoEvidence = [];

    public function openRedoModal(): void
    {
        // A delayed Initiate Redo click can outlive the selected Order.
        if (! $this->selectedJobId) return;
        $job = app(VisibleOrderQuery::class)->base(auth()->user(), (int) $this->selectedJobId);
        abort_unless(app(OrderRedoService::class)->canInitiate(auth()->user(), $job), 403);

        $quantity = max(1, (int) $job->quantity);
        $this->redoStep = 1;
        $this->redoIssueSource = 'Customer';
        $this->redoIssueCategory = 'Artwork / production mismatch';
        $this->redoAffectedQuantity = (string) $quantity;
        $this->redoIssueDescription = '';
        $this->redoScope = 'artwork';
        $this->redoQuantity = (string) $quantity;
        $this->redoInstructions = '';
        $this->redoCustomerResolution = 'free';
        $this->redoCustomerAdjustmentType = 'percent';
        $this->redoCustomerDiscount = '20';
        $this->redoSupplierAdjustmentType = 'percent';
        $this->redoSupplierChargePercent = '40';
        $this->redoDeductFreight = true;
        $this->redoSupplierFreightAmount = '';
        $this->redoCourierFreightAmount = '';
        $this->redoSupplierOptions = app(OrderRedoService::class)->supplierOptions($job);
        $this->redoSupplierId = $this->redoSupplierOptions[0]['id'] ?? null;
        $this->redoEvidence = app(OrderRedoService::class)->evidenceLabels($job);
        $this->showRedoModal = true;
        $this->resetValidation([
            'redoIssueSource', 'redoIssueCategory', 'redoAffectedQuantity', 'redoIssueDescription',
            'redoScope', 'redoQuantity', 'redoSupplierId', 'redoInstructions',
            'redoCustomerResolution', 'redoCustomerAdjustmentType', 'redoCustomerDiscount',
            'redoSupplierAdjustmentType', 'redoSupplierChargePercent', 'redoSupplierFreightAmount', 'redoCourierFreightAmount',
        ]);
    }

    public function closeRedoModal(): void
    {
        $this->showRedoModal = false;
        $this->redoStep = 1;
        $this->resetValidation([
            'redoIssueSource', 'redoIssueCategory', 'redoAffectedQuantity', 'redoIssueDescription',
            'redoScope', 'redoQuantity', 'redoSupplierId', 'redoInstructions',
            'redoCustomerResolution', 'redoCustomerAdjustmentType', 'redoCustomerDiscount',
            'redoSupplierAdjustmentType', 'redoSupplierChargePercent', 'redoSupplierFreightAmount', 'redoCourierFreightAmount',
        ]);
    }

    public function updatedRedoAffectedQuantity($value): void
    {
        $quantity = max(1, (int) $value);
        $this->redoAffectedQuantity = (string) $quantity;
        if ($this->redoStep <= 1) $this->redoQuantity = (string) $quantity;
    }

    public function updatedRedoQuantity($value): void
    {
        $quantity = max(1, (int) $value);
        $this->redoQuantity = (string) $quantity;
        $this->redoAffectedQuantity = (string) $quantity;
    }

    public function updatedRedoCustomerAdjustmentType($value): void
    {
        $this->redoCustomerAdjustmentType = in_array((string) $value, ['percent', 'pcs'], true)
            ? (string) $value
            : 'percent';

        if ($this->redoCustomerAdjustmentType === 'pcs') {
            $this->redoCustomerDiscount = '1';
        }
    }

    public function updatedRedoSupplierAdjustmentType($value): void
    {
        $this->redoSupplierAdjustmentType = in_array((string) $value, ['percent', 'pcs'], true)
            ? (string) $value
            : 'percent';

        if ($this->redoSupplierAdjustmentType === 'pcs') {
            $this->redoSupplierChargePercent = '1';
        }
    }

    public function updatedRedoScope($value): void
    {
        if ((string) $value === 'discount') {
            // Financial-only resolution: a percentage is a customer adjustment;
            // pcs is treated as missing quantity. Neither path restarts workflow.
            $this->redoCustomerResolution = 'discount';
            $this->redoQuantity = (string) max(1, (int) $this->redoAffectedQuantity);
            $this->redoSupplierAdjustmentType = 'percent';
            $this->redoSupplierChargePercent = '0';
            $this->redoDeductFreight = false;
            $this->redoSupplierFreightAmount = '';
            $this->redoCourierFreightAmount = '';
            return;
        }

        // Restore operational defaults after leaving the no-redo adjustment path.
        if ((float) $this->redoSupplierChargePercent === 0.0
            && !$this->redoDeductFreight
            && (float) $this->redoSupplierFreightAmount === 0.0
            && (float) $this->redoCourierFreightAmount === 0.0
            && $this->redoCustomerResolution === 'discount') {
            $this->redoCustomerResolution = 'free';
            $this->redoSupplierAdjustmentType = 'percent';
            $this->redoSupplierChargePercent = '40';
            $this->redoDeductFreight = true;
            $this->redoSupplierFreightAmount = '';
            $this->redoCourierFreightAmount = '';
            $this->redoSupplierId = $this->redoSupplierOptions[0]['id'] ?? null;
        }
    }

    public function nextRedoStep(): void
    {
        // A pending click can arrive after navigation/closing the modal.
        // Treat it as obsolete instead of turning a harmless UI race into HTTP 422.
        if (! $this->showRedoModal) return;
        if (! $this->selectedJobId) {
            $this->closeRedoModal();
            return;
        }

        if ($this->redoStep === 1) {
            $this->validateRedoIssueStep();
            $this->redoStep = 2;
            return;
        }

        if ($this->redoStep === 2) {
            $this->validateRedoScopeStep();
            $this->redoStep = 3;
            return;
        }

        if ($this->redoStep === 3) {
            $this->validateRedoCommercialStep();
            $this->redoStep = 4;
            return;
        }

        $this->createRedoOrder();
    }

    public function previousRedoStep(): void
    {
        // Back may race with closing the modal or switching to another Order.
        if (! $this->showRedoModal) return;
        if (! $this->selectedJobId) {
            $this->closeRedoModal();
            return;
        }

        $this->redoStep = max(1, $this->redoStep - 1);
    }

    public function createRedoOrder(): void
    {
        // Ignore a stale queued submit once the modal has been dismissed.
        // When it is open, normal validation and the service authorization
        // checks below still run before anything can be created.
        if (! $this->showRedoModal) return;
        if (! $this->selectedJobId) {
            $this->closeRedoModal();
            return;
        }
        $this->validateRedoIssueStep();
        $this->validateRedoScopeStep();
        $this->validateRedoCommercialStep();

        $job = app(VisibleOrderQuery::class)->base(auth()->user(), (int) $this->selectedJobId);
        $record = app(OrderRedoService::class)->createRedo($job, [
            'issue_reported_by' => $this->redoIssueSource,
            'issue_category' => $this->redoIssueCategory,
            'affected_quantity' => $this->redoAffectedQuantity,
            'issue_description' => $this->redoIssueDescription,
            'scope' => $this->redoScope,
            'redo_quantity' => $this->redoQuantity,
            'supplier_id' => $this->redoSupplierId,
            'internal_instructions' => $this->redoInstructions,
            'customer_resolution' => $this->redoCustomerResolution,
            'customer_adjustment_type' => $this->redoCustomerAdjustmentType,
            'customer_adjustment_value' => (float) $this->redoCustomerDiscount,
            // Keep legacy percentage columns populated for older reports only
            // when the selected unit is actually a percentage.
            'customer_discount_percent' => $this->redoCustomerAdjustmentType === 'percent'
                ? (float) $this->redoCustomerDiscount
                : 0,
            'supplier_adjustment_type' => $this->redoSupplierAdjustmentType,
            'supplier_adjustment_value' => (float) $this->redoSupplierChargePercent,
            'supplier_redo_charge_percent' => $this->redoSupplierAdjustmentType === 'percent'
                ? (float) $this->redoSupplierChargePercent
                : 0,
            'deduct_freight' => $this->redoDeductFreight,
            'supplier_freight_amount' => (float) ($this->redoSupplierFreightAmount ?: 0),
            'courier_freight_amount' => (float) ($this->redoCourierFreightAmount ?: 0),
        ], auth()->user());

        $redoOrderId = (int) ($record->redo_order_id ?? 0);
        $redoOrderNumber = $record->redoOrder?->displayOrderNumber() ?: 'Redo order';

        $this->showRedoModal = false;
        $this->redoStep = 1;
        $this->resetValidation();

        if ($record->scope === 'discount' || $redoOrderId <= 0) {
            // Financial-only adjustment: remain on the original Order and keep
            // workflow/tasks untouched. pcs means missing quantity.
            $this->detailTab = 'redo';
            $customerType = (string) ($record->customer_adjustment_type ?: 'percent');
            $customerValue = (float) ($record->customer_adjustment_value ?? $record->customer_discount_percent ?? 0);
            $message = $customerType === 'pcs'
                ? number_format((int) $customerValue).' pcs missing quantity deduction recorded. The Order workflow was not restarted.'
                : rtrim(rtrim(number_format($customerValue, 2), '0'), '.').'% customer adjustment recorded. The Order workflow was not restarted.';
        } else {
            // Operational redo: open the NEW Redo Order immediately so the
            // selected restart phase is visible and actionable.
            $this->openJob($redoOrderId);
            $this->detailTab = 'overview';
            $message = $redoOrderNumber.' created and restarted from the selected Redo phase.';
        }

        session()->flash('success', $message);
        $this->dispatch('order-redo-notice', message: $message);
    }

    public function openLinkedRedoOrder(int $orderId): void
    {
        $this->openJob($orderId);
    }

    private function validateRedoIssueStep(): void
    {
        $maxQuantity = $this->redoSourceMaxQuantity();
        $this->validate([
            'redoIssueSource' => ['required', Rule::in(['Customer', 'Quality Control', 'Internal Team'])],
            'redoIssueCategory' => ['required', 'string', 'max:120'],
            'redoAffectedQuantity' => ['required', 'integer', 'min:1', 'max:'.$maxQuantity],
            'redoIssueDescription' => ['required', 'string', 'max:60000'],
        ], [], [
            'redoIssueSource' => 'issue reported by',
            'redoIssueCategory' => 'issue category',
            'redoAffectedQuantity' => 'affected quantity',
            'redoIssueDescription' => 'issue description',
        ]);

        // The Redo reason uses FlowTrack's shared rich-text format. Normalize it
        // here so pasted images are whitelisted, unsafe HTML is removed, and
        // the 5,000-character limit applies to readable text instead of markup.
        $normalized = app(RichTextService::class)->normalize(
            $this->redoIssueDescription,
            5000,
            'redoIssueDescription',
        );

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'redoIssueDescription' => 'Add an issue description or pasted image.',
            ]);
        }

        $this->redoIssueDescription = $normalized;
    }

    private function validateRedoScopeStep(): void
    {
        $maxQuantity = $this->redoSourceMaxQuantity();
        $supplierIds = collect($this->redoSupplierOptions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rules = [
            'redoScope' => ['required', Rule::in(['artwork', 'production', 'discount'])],
            'redoQuantity' => ['required', 'integer', 'min:1', 'max:'.$maxQuantity],
            'redoInstructions' => ['nullable', 'string', 'max:5000'],
        ];
        if ($this->redoSupplierId !== null) {
            $rules['redoSupplierId'] = $supplierIds !== []
                ? ['nullable', 'integer', Rule::in($supplierIds)]
                : ['nullable', 'integer'];
        }

        $this->validate($rules, [], [
            'redoScope' => 'redo scope',
            'redoQuantity' => 'redo quantity',
            'redoSupplierId' => 'responsible supplier',
            'redoInstructions' => 'internal instructions',
        ]);
    }

    private function validateRedoCommercialStep(): void
    {
        if ($this->redoScope === 'discount') {
            $this->redoCustomerResolution = 'discount';
        }

        $adjustmentQuantity = min(
            $this->redoSourceMaxQuantity(),
            max(1, (int) ($this->redoScope === 'discount' ? $this->redoAffectedQuantity : $this->redoQuantity)),
        );
        $customerValueRules = $this->redoCustomerAdjustmentType === 'pcs'
            ? ['required', 'integer', 'min:0', 'max:'.$adjustmentQuantity]
            : ['required', 'numeric', 'min:0', 'max:100'];
        $supplierValueRules = $this->redoSupplierAdjustmentType === 'pcs'
            ? ['required', 'integer', 'min:0', 'max:'.$adjustmentQuantity]
            : ['required', 'numeric', 'min:0', 'max:100'];

        $this->validate([
            'redoCustomerResolution' => ['required', Rule::in(['free', 'discount'])],
            'redoCustomerAdjustmentType' => ['required', Rule::in(['percent', 'pcs'])],
            'redoCustomerDiscount' => $customerValueRules,
            'redoSupplierAdjustmentType' => ['required', Rule::in(['percent', 'pcs'])],
            'redoSupplierChargePercent' => $supplierValueRules,
            'redoDeductFreight' => ['boolean'],
            'redoSupplierFreightAmount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'redoCourierFreightAmount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ], [], [
            'redoCustomerResolution' => 'customer resolution',
            'redoCustomerAdjustmentType' => 'customer unit',
            'redoCustomerDiscount' => 'customer value',
            'redoSupplierAdjustmentType' => 'supplier unit',
            'redoSupplierChargePercent' => 'supplier value',
            'redoSupplierFreightAmount' => 'supplier freight amount',
            'redoCourierFreightAmount' => 'courier freight amount',
        ]);
    }

    private function redoSourceMaxQuantity(): int
    {
        if (!$this->selectedJobId) return 1;
        $job = FlowJob::query()->find($this->selectedJobId, ['id', 'quantity']);
        return max(1, (int) ($job?->quantity ?: 1));
    }

    /** @return array<string,mixed> */
    private function redoFormState(FlowJob $job): array
    {
        $effectiveQuantity = $this->redoScope === 'discount'
            ? max(1, (int) $this->redoAffectedQuantity)
            : max(1, (int) $this->redoQuantity);
        $effectiveResolution = $this->redoScope === 'discount'
            ? 'discount'
            : $this->redoCustomerResolution;

        $preview = $this->showRedoModal
            ? app(OrderRedoService::class)->financialPreview(
                $job,
                $effectiveQuantity,
                $effectiveResolution,
                $this->redoCustomerAdjustmentType,
                (float) $this->redoCustomerDiscount,
                $this->redoSupplierAdjustmentType,
                (float) $this->redoSupplierChargePercent,
                $this->redoDeductFreight,
                (float) ($this->redoSupplierFreightAmount ?: 0),
                (float) ($this->redoCourierFreightAmount ?: 0),
            )
            : [
                'quantity' => 0,
                'unitValue' => 0.0,
                'orderValue' => 0.0,
                'affectedValue' => 0.0,
                'customerImpact' => 0.0,
                'adjustedOrderValue' => 0.0,
                'supplierCharge' => 0.0,
                'freight' => 0.0,
                'supplierFreight' => 0.0,
                'recovery' => 0.0,
                'courierRecovery' => 0.0,
            ];

        return [
            'show' => $this->showRedoModal,
            'step' => $this->redoStep,
            'issueSource' => $this->redoIssueSource,
            'issueCategory' => $this->redoIssueCategory,
            'affectedQuantity' => $this->redoAffectedQuantity,
            'issueDescription' => $this->redoIssueDescription,
            'scope' => $this->redoScope,
            'quantity' => $this->redoQuantity,
            'supplierId' => $this->redoSupplierId,
            'instructions' => $this->redoInstructions,
            'customerResolution' => $effectiveResolution,
            'customerAdjustmentType' => $this->redoCustomerAdjustmentType,
            'customerDiscount' => $this->redoCustomerDiscount,
            'supplierAdjustmentType' => $this->redoSupplierAdjustmentType,
            'supplierChargePercent' => $this->redoSupplierChargePercent,
            'deductFreight' => $this->redoDeductFreight,
            'supplierFreightAmount' => $this->redoSupplierFreightAmount,
            'courierFreightAmount' => $this->redoCourierFreightAmount,
            'supplierOptions' => $this->redoSupplierOptions,
            'evidence' => $this->redoEvidence,
            'preview' => $preview,
        ];
    }
}
