@props(['stage', 'index'])
@php
    $tasks = collect($stage['tasks'] ?? []);
    $requiredCount = $tasks->where('is_required', true)->count();
    $documentCount = $tasks->where('document_enabled', true)->count();
@endphp
<div class="ft-order-workflow-stage-row" style="--stage: {{ $stage['color'] }}">
    <div class="ft-order-workflow-stage-number">{{ $index + 1 }}</div>
    <div class="ft-order-workflow-stage-copy">
        <b>{{ $stage['name'] }}</b>
        <small>{{ $stage['short'] }} · stage color {{ $stage['color'] }}</small>
    </div>
    <div class="ft-order-workflow-task-summary">
        <b>
            {{ $tasks->count() }} tasks · {{ $requiredCount }} required
            @if($documentCount)<span class="ft-order-workflow-doc-count">📎 {{ $documentCount }} document</span>@endif
        </b>
        <div class="ft-order-workflow-task-preview">
            @foreach($tasks->take(4) as $task)
                <span class="ft-order-workflow-task-pill {{ !empty($task['document_enabled']) ? 'has-doc' : '' }}">
                    @if(!empty($task['document_enabled']))📎 @endif{{ $task['title'] }}
                </span>
            @endforeach
            @if($tasks->count() > 4)<span class="ft-order-workflow-task-pill">+{{ $tasks->count() - 4 }} more</span>@endif
        </div>
        <small>Tasks unlock automatically. Document validation is applied only where configured.</small>
    </div>
    <span class="ft-order-workflow-state">Active</span>
    <div class="ft-order-workflow-stage-actions">
        <button type="button" class="ft-order-workflow-btn ft-order-workflow-btn--small" wire:click="openStageEditor({{ $index }})">Edit stage &amp; tasks</button>
    </div>
</div>
