@php
    $orderId = (int) data_get($row, 'order_id', 0);
    $taskId = (int) data_get($row, 'next_task_id', 0);
    $label = (string) data_get($row, 'next_action', 'Open order');
    $useInlineListAction = (bool) ($inlineListActions ?? true);
    $isEmailHandoff = in_array((string) data_get($row, 'next_action_key', ''), ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true);
@endphp

{{-- Orders page keeps its inline workflow action modal. Other pages that reuse
     the exact Order-list table can disable the inline action while keeping the
     same visual row and route users to the Order Details page instead. --}}
@if($useInlineListAction && $orderId > 0 && $taskId > 0)
    <button
        type="button"
        class="stage-action stage-action-button"
        wire:click="openListWorkflowAction({{ $orderId }}, {{ $taskId }})"
        wire:loading.attr="disabled"
        x-on:click.stop
    >
        {{ $label }}
    </button>
    @if($isEmailHandoff && ! (bool) data_get($row, 'is_on_hold', false))
        <button
            type="button"
            class="stage-action stage-action-button"
            wire:click="completeListWorkflowHandoffWithoutEmail({{ $orderId }}, {{ $taskId }})"
            wire:loading.attr="disabled"
            x-on:click.stop
        >Complete without email</button>
    @endif
    <span class="stage-table-note">Perform action here</span>
@else
    <a class="stage-action" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ $label }}</a>
    <span class="stage-table-note">Open order to continue</span>
@endif
