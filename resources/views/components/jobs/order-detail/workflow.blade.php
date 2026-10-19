@props([
    'job',
    'overviewPhaseId' => null,
    'taskStatuses' => collect(),
    'context' => [],
    'overviewTaskLinkFormTaskId' => null,
    'showShipmentModal' => false,
    'showShipmentSavedAddressPicker' => false,
    'shipmentModalTaskId' => null,
    'shipmentEditingId' => null,
    'shipmentModalMode' => 'same_address',
    'shipmentForm' => [],
    'shipmentInlineTaskId' => null,
    'shipmentInlineEditingId' => null,
    'shipmentInlineAddressMode' => \App\Services\OrderShipmentService::MODE_SAME_ADDRESS,
    'shipmentInlineForm' => [],
    'showShipmentDetailsModal' => false,
    'shipmentDetailsId' => null,
])
@php
    /*
     * Workflow Setup is the workflow-definition source for both Inquiry and Order
     * templates. Active Orders stay attached to the Order workflow selected at
     * creation, so this view renders its saved seven-stage definition and real
     * tasks without hard-coded stage/task definitions in Blade.
     *
     * All relations used here are hydrated by JobService::loadVisibleDetailTab(),
     * so this component does not issue database queries (N+1 safe).
     */
    $phases = \App\Support\OrderDetailPresenter::phases($job);
    $selectedPhase = \App\Support\OrderDetailPresenter::selectedPhase($job, $overviewPhaseId);
    $selectedTasks = $selectedPhase
        ? \App\Support\OrderDetailPresenter::displayTasksForPhase($job, $selectedPhase)
        : collect();
    $selectedState = $selectedPhase
        ? \App\Support\OrderDetailPresenter::phaseState($job, $selectedPhase)
        : 'locked';
    $requiredSelectedTasks = $selectedTasks
        ->filter(fn ($task) => \App\Support\OrderTaskRequirement::isRequired($task))
        ->values();
    $requiredCompletedTasks = \App\Support\OrderDetailPresenter::completedCount($requiredSelectedTasks);
    $requiredTaskCount = $requiredSelectedTasks->count();
    $visibleOptionalTaskCount = $selectedTasks
        ->filter(fn ($task) => \App\Support\OrderTaskRequirement::isRegularOptional($task))
        ->count();
    $stageCount = $phases->count();
    $isShipmentPhase = \App\Support\OrderShipmentPresenter::isShipmentPhase($selectedPhase, $selectedTasks);
    $shipmentPresentation = $isShipmentPhase
        ? \App\Support\OrderShipmentPresenter::present($job, $selectedPhase, $selectedTasks, $context)
        : [];

    $archivedArtworkDocuments = ! $isShipmentPhase
        ? \App\Support\OrderDetailPresenter::archivedArtworkDocuments($job, $selectedTasks)
        : collect();

    // The generated Artwork Tracking PDF is already loaded with the workflow's
    // document collection. Reuse it here so Production gets the approved
    // reference without another query or a duplicate document record.
    $isProductionPhase = $selectedPhase
        && strcasecmp(trim((string) $selectedPhase->name), 'Production') === 0;
    $productionArtworkPdf = (bool) config('flowtrack.order_tracking_enabled', false)
        && $isProductionPhase
        && $job->relationLoaded('documents')
        ? $job->documents
            ->where('category', \App\Models\Document::CATEGORY_ARTWORK_TRACKING_PDF)
            ->sortByDesc('id')
            ->first()
        : null;

    $taskPackSub = match ($selectedState) {
        'completed' => 'This stage is complete',
        'active' => 'Complete the active task to continue the workflow',
        'cancelled' => 'Order cancelled — workflow actions are blocked',
        default => 'This stage is locked',
    };
@endphp

<section class="section-card integrated-process ft-order-section-card ft-order-workflow-card" id="workflowSection" wire:key="order-detail-workflow-{{ $job->id }}">
    <div class="process-head">
        <div>
            <h2>Order process &amp; tasks</h2>
            <div class="card-sub">
                @if($stageCount)
                    {{ $stageCount }} stages · status changes save automatically · conditional tasks appear only when required
                @else
                    No Order workflow is configured
                @endif
            </div>
        </div>
    </div>

    @if($phases->isEmpty())
        <div class="empty-stage">
            <b>No Order workflow stages are available.</b><br>
            Configure and save an Order workflow in Workflow Setup before creating Orders.
        </div>
    @else
        <section class="workflow" aria-label="Order workflow stages">
            @foreach($phases as $phase)
                <x-jobs.order-detail.stage-card
                    :job="$job"
                    :phase="$phase"
                    :selected="(int) ($selectedPhase?->id ?? 0) === (int) $phase->id"
                    :context="$context"
                />
            @endforeach
        </section>

        @if($isShipmentPhase)
            <x-jobs.order-detail.shipment.phase
                :job="$job"
                :phase="$selectedPhase"
                :presentation="$shipmentPresentation"
                :inline-editing-id="$shipmentInlineEditingId"
                :inline-task-id="$shipmentInlineTaskId"
                :inline-address-mode="$shipmentInlineAddressMode"
                :inline-form="$shipmentInlineForm"
            />

            @if($showShipmentModal && $shipmentModalTaskId)
                @php
                    $shipmentModalTask = $job->tasks->firstWhere('id', (int) $shipmentModalTaskId);
                @endphp
                @if($shipmentModalTask)
                    <x-jobs.order-detail.shipment.add-modal
                        :job="$job"
                        :task="$shipmentModalTask"
                        :presentation="$shipmentPresentation"
                        :editing-id="$shipmentEditingId"
                        :mode="$shipmentModalMode"
                        :form="$shipmentForm"
                        :show-saved-address-picker="$showShipmentSavedAddressPicker"
                    />
                @endif
            @endif

            @if($showShipmentDetailsModal && $shipmentDetailsId)
                @php
                    $shipmentDetailsRow = collect($shipmentPresentation['shipments'] ?? [])->firstWhere('id', (int) $shipmentDetailsId);
                @endphp
                @if($shipmentDetailsRow)
                    <x-jobs.order-detail.shipment.details-modal :shipment="$shipmentDetailsRow" />
                @endif
            @endif
        @else
            <section class="grid ft-order-workflow-layout ft-order-workflow-layout--full">
              <div class="card ft-order-task-panel">
                <div class="card-head">
                    <div>
                        <div class="card-title">{{ $selectedPhase?->name ?? 'Workflow' }} tasks</div>
                        <div class="card-sub">{{ $taskPackSub }}</div>
                    </div>
                    <div class="completion">
                        {{ $requiredCompletedTasks }} of {{ $requiredTaskCount }} required complete
                        @if($visibleOptionalTaskCount > 0)
                            <span class="ft-order-optional-count">· {{ $visibleOptionalTaskCount }} optional</span>
                        @endif
                    </div>
                </div>

                <div class="task-columns ft-order-task-columns">
                    <span></span><span>Task</span><span>Assignee</span><span>Due date</span><span>Status / files</span><span>Action</span>
                </div>

                @if($productionArtworkPdf)
                    <x-jobs.order-detail.production-artwork-pdf
                        :document="$productionArtworkPdf"
                        :can-export="(bool) ($context['canExportDocument'] ?? false)"
                    />
                @endif

                <div>
                    @forelse($selectedTasks as $index => $task)
                        @php
                            $mode = \App\Support\OrderDetailPresenter::taskMode($job, $task);
                            if ($selectedState === 'completed' && $mode !== 'done') $mode = 'locked';
                            $displayCode = \App\Support\OrderDetailPresenter::taskDisplayCode($selectedPhase, $task, $index);
                        @endphp
                        <x-jobs.order-detail.task-row
                            :job="$job"
                            :task="$task"
                            :mode="$mode"
                            :display-code="$displayCode"
                            :task-statuses="$taskStatuses"
                            :context="$context"
                            :overview-task-link-form-task-id="$overviewTaskLinkFormTaskId"
                        />
                    @empty
                        <div class="empty-stage">No tasks are configured for this stage.</div>
                    @endforelse
                </div>
              </div>
            </section>

            <x-jobs.order-detail.archived-artwork
                :documents="$archivedArtworkDocuments"
                :can-export-document="(bool) ($context['canExportDocument'] ?? false)"
            />
        @endif
    @endif
</section>
