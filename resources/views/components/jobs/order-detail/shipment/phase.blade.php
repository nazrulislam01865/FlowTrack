@props([
    'job',
    'phase',
    'presentation' => [],
    'inlineEditingId' => null,
    'inlineTaskId' => null,
    'inlineAddressMode' => \App\Services\OrderShipmentService::MODE_SAME_ADDRESS,
    'inlineForm' => [],
])

<section class="ft-shipment-phase ft-ms-phase" aria-label="Shipment tasks" wire:key="shipment-phase-{{ $job->id }}-{{ $phase->id }}">
    <div class="ft-shipment-phase__status">
        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2.5 5.5h9v8h-9zM11.5 8h3l3 3v2.5h-6z"/><circle cx="6" cy="15" r="1.5"/><circle cx="14.5" cy="15" r="1.5"/></svg>
        Order status: Ready for shipment
    </div>

    <header class="ft-shipment-phase__head">
        <div>
            <h3>Shipment tasks</h3>
            <p>Complete these steps in order to dispatch the package.</p>
        </div>
        <div class="ft-shipment-progress" aria-label="{{ $presentation['completed_count'] ?? 0 }} of {{ $presentation['total_count'] ?? 0 }} complete">
            <strong class="{{ ($presentation['completed_count'] ?? 0) === ($presentation['total_count'] ?? 0) && ($presentation['total_count'] ?? 0) > 0 ? 'is-complete' : '' }}">
                {{ $presentation['completed_count'] ?? 0 }} of {{ $presentation['total_count'] ?? 0 }} complete
            </strong>
        </div>
    </header>

    <div class="ft-shipment-phase__tasks ft-ms-tasks">
        @foreach(($presentation['tasks'] ?? []) as $row)
            <article class="ft-shipment-task ft-shipment-task--{{ $row['mode'] }} ft-ms-task" wire:key="shipment-task-{{ $row['task']->id }}-{{ $row['mode'] }}-{{ (int) $row['is_done'] }}">
                <div class="ft-shipment-task__marker-wrap" aria-hidden="true"><span class="ft-shipment-task__marker">{{ $row['is_done'] ? '✓' : ($row['mode'] === 'active' ? '●' : '⌁') }}</span></div>

                <div class="ft-shipment-task__content">
                    <div class="ft-shipment-task__top">
                        <div class="ft-shipment-task__copy">
                            <div class="ft-shipment-task__eyebrow"><span>TASK {{ $row['display_code'] }}</span></div>
                            <h4>{{ $row['title'] }}</h4>
                            <p>{{ $row['description'] }}</p>
                        </div>

                        <x-jobs.order-detail.shipment.task-meta :job="$job" :row="$row" />

                        <div class="ft-shipment-task__state">
                            @if($row['mode'] === 'active')
                                <span class="ft-shipment-state ft-shipment-state--action"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6.5v4M10 13.5h.01"/></svg>Action required</span>
                            @elseif($row['is_done'])
                                <span class="ft-shipment-state ft-shipment-state--done">Completed</span>
                            @else
                                <span class="ft-shipment-state ft-shipment-state--locked"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="5" y="9" width="10" height="8" rx="1.5"/><path d="M7.5 9V6.5a2.5 2.5 0 0 1 5 0V9"/></svg>Locked</span>
                            @endif
                        </div>
                    </div>

                    <div class="ft-ms-task__body">
                        @if($row['key'] === 'SHIP_CONFIRM_INFO')
                            <x-jobs.order-detail.shipment.plan-table
                                :row="$row"
                                :presentation="$presentation"
                                :inline-editing-id="$inlineEditingId"
                                :inline-task-id="$inlineTaskId"
                                :inline-address-mode="$inlineAddressMode"
                                :inline-form="$inlineForm"
                            />
                        @elseif($row['key'] === 'SHIP_LABEL')
                            <x-jobs.order-detail.shipment.tracking-table :row="$row" :presentation="$presentation" />
                        @elseif($row['key'] === 'SHIP_PACKAGE')
                            <x-jobs.order-detail.shipment.dispatch-table :row="$row" :presentation="$presentation" />
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</section>
