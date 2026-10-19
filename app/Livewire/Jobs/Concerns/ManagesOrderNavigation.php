<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\BulkUpdateOrders;
use App\Queries\Orders\OrderListQuery;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\OrderRedoService;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderNavigation
{
    public function updatedSearch(): void { $this->resetJobSelection(); }

    public function clearSearch(): void { $this->search = ''; $this->resetJobSelection(); }

    public function updatedPhase(): void { $this->resetJobSelection(); }


    public function updatedClient(): void { $this->resetJobSelection(); }

    public function updatedOwner(): void { $this->resetJobSelection(); }

    public function updatedAssignee(): void { $this->resetJobSelection(); }

    public function updatedDelivery(): void { $this->resetJobSelection(); }

    public function updatedInvoice(): void { $this->resetJobSelection(); }

    public function updatedPriorityFilter(): void { $this->resetJobSelection(); }

    public function updatedJobStatusFilter(): void { $this->resetJobSelection(); }

    public function updatedSort(): void { $this->resetJobSelection(); }

    public function clearFilters(): void
    {
        $this->reset(['search','phase','client','owner','assignee','delivery','invoice','priorityFilter','jobStatusFilter']);
        $this->sort = 'updated_desc';
        $this->resetJobSelection();
    }

    public function clearFilter(string $filter): void
    {
        $allowed = ['search','phase','client','owner','assignee','delivery','invoice','priorityFilter','jobStatusFilter'];
        abort_unless(in_array($filter, $allowed, true), 422);
        $this->{$filter} = '';
        $this->resetJobSelection();
    }

    public function toggleMoreFilters(): void { $this->showMoreFilters = !$this->showMoreFilters; }

    public function toggleSelectAllJobs(): void
    {
        $ids = app(OrderListQuery::class)->filteredIds(auth()->user(), $this->jobFilters());

        if ($ids->isNotEmpty() && count($this->selectedJobIds) === $ids->count()) {
            $selected = collect($this->selectedJobIds)->map(fn ($id) => (int) $id)->sort()->values();
            if ($selected->all() === $ids->sort()->values()->all()) {
                $this->selectedJobIds = [];
                return;
            }
        }

        $this->selectedJobIds = $ids->all();
    }

    public function bulkUpdateJobs(string $action): void
    {
        abort_unless(in_array($action, ['deactivate','cancel','delete'], true), 422);

        $ids = collect($this->selectedJobIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) return;

        app(BulkUpdateOrders::class)->handle(auth()->user(), $ids->all(), $action);

        $this->resetJobSelection();
        session()->flash('success', match ($action) {
            'deactivate' => 'Selected Orders deactivated.',
            'cancel' => 'Selected Orders cancelled.',
            'delete' => 'Selected Orders deleted.',
        });
    }

    public function openJob(int $id): void
    {
        $this->selectedJobId = $id;
        $this->selectedTaskId = null;
        $this->overviewPhaseId = null;
        $this->lastOverviewWorkflowPhaseId = null;
        $this->resetOrderDetailProgressiveSections();
        $this->resetTaskDetailProgressiveSections();
        $this->focusComment = null;
        $this->taskEditMode = false;
        $this->detailTab = 'overview';
        $this->inquirySearch = '';
        $this->selectedLinkInquiryId = null;
        $this->showInquiryLinkConfirm = false;
        $this->showInquiryUnlinkConfirm = false;
        $this->unlinkInquiryId = null;
        $this->jobTaskSearch = '';
        $this->jobDocumentUploads = [];
        $this->overviewTaskUploads = [];
        $this->cancelAddOrderTask(false);
        $this->resetOverviewTaskResourceUi();
        $this->jobRequiredDocumentUpload = null;
        $this->jobDocumentTaskId = null;
        $this->existingDocumentId = null;
        $this->showDocumentPicker = false;
        $this->lastJobDocumentUploadId = null;
        $this->lastJobDocumentTaskId = null;
        $this->jobComment = '';
        $this->jobActivityTab = 'all';
        $this->jobActivityPage = 1;
        $this->closeOrderAttentionReason();
        $this->closeOrderHoldModal();
        $this->closeEditOrderProductModal();
        $this->closeFinanceModals();
        $this->closeOrderWorkflowAction();
        $this->closeRedoModal();
        $this->resetShipmentPrototypeUi();
        $this->prepareSelectedJob($id);
    }

    public function openInvoiceAndPayment(int $id): void
    {
        abort_unless(app(AccessControlService::class)->can(auth()->user(), 'finance', 'view'), 403);

        // Reuse the normal Order opening flow so visibility checks, detail state,
        // modals and activity state are reset exactly as they are for View.
        $this->openJob($id);
        $this->detailTab = 'finance';
    }

    public function closeDrawer(): void
    {
        $this->selectedJobId = null;
        $this->selectedTaskId = null;
        $this->overviewPhaseId = null;
        $this->lastOverviewWorkflowPhaseId = null;
        $this->resetOrderDetailProgressiveSections();
        $this->resetTaskDetailProgressiveSections();
        $this->focusComment = null;
        $this->taskEditMode = false;
        $this->expandedPhaseIds = [];
        $this->inquirySearch = '';
        $this->selectedLinkInquiryId = null;
        $this->showInquiryLinkConfirm = false;
        $this->showInquiryUnlinkConfirm = false;
        $this->unlinkInquiryId = null;
        $this->jobTaskSearch = '';
        $this->jobDocumentUploads = [];
        $this->overviewTaskUploads = [];
        $this->cancelAddOrderTask(false);
        $this->resetOverviewTaskResourceUi();
        $this->jobRequiredDocumentUpload = null;
        $this->jobDocumentTaskId = null;
        $this->existingDocumentId = null;
        $this->showDocumentPicker = false;
        $this->lastJobDocumentUploadId = null;
        $this->lastJobDocumentTaskId = null;
        $this->closeOrderAttentionReason();
        $this->closeOrderHoldModal();
        $this->closeEditOrderProductModal();
        $this->closeFinanceModals();
        $this->closeRedoModal();
        $this->resetShipmentPrototypeUi();

        $this->redirectRoute('jobs.index', navigate: true);
    }

    public function selectOverviewPhase(int $phaseId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        // Phase selection only needs the workflow phase list/current phase. Loading
        // the full overview here duplicates products, documents, shipments, tasks,
        // and activity hydration that the progressive loaders handle separately.
        app(VisibleOrderQuery::class)->loadOverviewSummary($job, $user);

        $phase = $job->workflow?->phases?->firstWhere('id', $phaseId);
        abort_unless($phase, 404);
        abort_if((int) $phase->sequence > (int) ($job->phase?->sequence ?? 0), 422, 'Complete the current stage before opening a future stage.');

        $this->overviewPhaseId = (int) $phase->id;
    }

    public function showCurrentOverviewPhase(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $job = app(VisibleOrderQuery::class)->base(auth()->user(), $this->selectedJobId);
        $this->overviewPhaseId = (int) $job->workflow_phase_id;
        $this->lastOverviewWorkflowPhaseId = (int) $job->workflow_phase_id;
    }

    public function setDetailTab(string $tab): void
    {
        // The Order Details Documents/Workflow tabs remain intentionally muted.
        // Finance is a separate permission-controlled surface and does not change
        // any of the existing Overview or Inquiry behaviour.
        abort_unless(in_array($tab, ['overview','inquiry','finance','redo'], true), 422);
        if ($tab === 'finance') {
            abort_unless(app(AccessControlService::class)->can(auth()->user(), 'finance', 'view'), 403);
        }
        if ($tab === 'redo' && $this->selectedJobId) {
            $job = app(VisibleOrderQuery::class)->base(auth()->user(), (int) $this->selectedJobId);
            $context = app(OrderRedoService::class)->context($job, auth()->user());
            if (!(bool) ($context['hasRedo'] ?? false)) {
                $this->detailTab = 'overview';
                $this->dispatch('order-redo-notice', message: 'No redo has been created yet. Use Initiate Redo to begin.');
                return;
            }
        }
        $this->detailTab = $tab;
        if ($tab !== 'overview') {
            $this->cancelAddOrderTask(false);
            $this->resetOverviewTaskResourceUi();
            $this->closeOrderWorkflowAction();
            $this->closeOrderHoldModal();
        }
        if ($tab !== 'finance') $this->closeFinanceModals();
        if ($tab !== 'redo') $this->closeRedoModal();
        if ($tab !== 'inquiry') {
            $this->inquirySearch = '';
            $this->selectedLinkInquiryId = null;
            $this->showInquiryLinkConfirm = false;
            $this->showInquiryUnlinkConfirm = false;
            $this->unlinkInquiryId = null;
        }
        $this->resetValidation(['inquiryLink', 'invoiceForm', 'paymentForm', 'collectionForm']);
    }

}
