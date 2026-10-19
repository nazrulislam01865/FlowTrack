@props([
    'step' => 4,
    'title' => 'What happens next',
    'workflowOptions' => collect(),
    'selectedWorkflowId' => null,
    'selectedWorkflowName' => 'Select workflow',
    'phaseCount' => 0,
    'taskCount' => 0,
    'selectionAction' => 'setCreateSelector',
    'selectionProperty' => 'workflowId',
    'optionFallback' => 'Workflow',
    'footnote' => 'Tasks are created when you create this record.',
    'previewAllowed' => false,
    'previewDefaultOpen' => false,
    'availabilityLabel' => null,
    'icon' => 'check',
    'emptyMessage' => null,
    'errorField' => null,
    'startPhases' => collect(),
    'startPhaseId' => null,
    'startPhaseProperty' => null,
    'startPhaseErrorField' => null,
    'selectable' => true,
    'stagePreview' => collect(),
    'kindLabel' => 'Default workflow',
    'sourceLabel' => null,
    'stageNoun' => 'phase',
    'optionEmptyMessage' => 'No workflow is available.',
    'setupUrl' => null,
    'setupLabel' => 'Open workflow setup',
])

@php
    $workflowOptions = collect($workflowOptions);
    $startPhases = collect($startPhases);
    $stagePreview = collect($stagePreview);
    $workflowOptionCount = $workflowOptions->count();
    $phaseCount = (int) $phaseCount;
    $taskCount = (int) $taskCount;
    $selectedWorkflowId = $selectedWorkflowId !== null ? (int) $selectedWorkflowId : null;
    $showStartPhasePicker = filled($startPhaseProperty) && $startPhases->count() > 1;
@endphp

<section {{ $attributes->class('ft-create-workflow-next') }} x-data="{ workflowOpen: false, previewOpen: {{ $previewDefaultOpen ? 'true' : 'false' }} }">
    <div class="ft-create-workflow-heading">
        <span>{{ $step }}</span>
        <h2>{{ $title }}</h2>
        @if($workflowOptionCount > 0)
            <em>{{ filled($availabilityLabel) ? $availabilityLabel : $workflowOptionCount.' '.\Illuminate\Support\Str::plural('workflow', $workflowOptionCount).' available' }}</em>
        @endif
    </div>

    <div class="ft-create-workflow-card" :class="{ 'is-open': workflowOpen }">
        <button
            class="ft-create-workflow-selected"
            type="button"
            @if($selectable) x-on:click="workflowOpen = !workflowOpen" :aria-expanded="workflowOpen.toString()" aria-haspopup="listbox" @else aria-expanded="false" disabled @endif
        >
            <span class="ft-create-workflow-icon" aria-hidden="true">
                @if($icon === 'workflow')
                    <svg viewBox="0 0 24 24" fill="none"><path d="M7.7 18.5H6.5a4 4 0 0 1-.65-7.95A6.5 6.5 0 0 1 18.4 8.9a4.75 4.75 0 0 1-.9 9.6h-1.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M8.5 15.6h7M12 12.2v6.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                @else
                    ✓
                @endif
            </span>
            <span class="ft-create-workflow-copy">
                <small>{{ $kindLabel }}@if(filled($sourceLabel)) · {{ $sourceLabel }}@endif</small>
                <strong>{{ $selectedWorkflowName ?: 'Select workflow' }}</strong>
                <span>{{ $phaseCount }} {{ \Illuminate\Support\Str::plural($stageNoun, $phaseCount) }} · {{ $taskCount }} {{ \Illuminate\Support\Str::plural('task', $taskCount) }} will be created</span>
            </span>
            @if($stagePreview->isNotEmpty())
                <span
                    class="ft-create-workflow-preview-toggle"
                    role="button"
                    tabindex="0"
                    x-on:click.stop="previewOpen = !previewOpen"
                    x-on:keydown.enter.stop.prevent="previewOpen = !previewOpen"
                    x-on:keydown.space.stop.prevent="previewOpen = !previewOpen"
                    x-text="previewOpen ? 'Hide workflow' : 'Preview workflow'"
                >Preview workflow</span>
            @endif
            @if($selectable)<span class="ft-create-workflow-chevron" aria-hidden="true">⌄</span>@endif
        </button>

        @if($stagePreview->isNotEmpty())
            <div class="ft-create-workflow-preview" x-cloak x-show="previewOpen" x-transition.opacity.duration.120ms>
                @foreach($stagePreview as $stage)
                    @php
                        $stageColor = $stage['color'] ?? '#2d72d9';
                        $stageSequence = (int) ($stage['sequence'] ?? $loop->iteration);
                        $stageTaskCount = (int) ($stage['task_count'] ?? 0);
                    @endphp
                    <div class="ft-create-workflow-stage" style="--ft-workflow-stage: {{ $stageColor }}">
                        <small>Stage {{ $stageSequence }}</small>
                        <strong>{{ $stage['name'] ?? 'Stage' }}</strong>
                        <span>{{ $stageTaskCount }} {{ \Illuminate\Support\Str::plural('task', $stageTaskCount) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if($selectable)
        <div class="ft-create-workflow-options" x-cloak x-show="workflowOpen" role="listbox" aria-label="Available workflows">
            @forelse($workflowOptions as $workflowOption)
                @php
                    $optionId = (int) ($workflowOption['id'] ?? 0);
                    $wireClick = $selectionAction."('".$selectionProperty."', '".$optionId."')";
                @endphp
                <button
                    type="button"
                    class="ft-create-workflow-option {{ $optionId === $selectedWorkflowId ? 'is-selected' : '' }}"
                    wire:click="{{ $wireClick }}"
                    x-on:click="workflowOpen = false"
                    role="option"
                    aria-selected="{{ $optionId === $selectedWorkflowId ? 'true' : 'false' }}"
                >
                    <span class="ft-create-workflow-radio" aria-hidden="true"></span>
                    <span>
                        <strong>{{ $workflowOption['label'] ?? 'Workflow' }}</strong>
                        <small>{{ filled($workflowOption['meta'] ?? null) ? $workflowOption['meta'] : $optionFallback }}</small>
                    </span>
                </button>
            @empty
                <div class="ft-create-workflow-empty">
                    <span>{{ $optionEmptyMessage }}</span>
                    @if(filled($setupUrl))
                        <a href="{{ $setupUrl }}" wire:navigate>{{ $setupLabel }} →</a>
                    @endif
                </div>
            @endforelse

            @if($showStartPhasePicker)
                <label class="ft-create-workflow-start-phase">
                    <span>Starting phase</span>
                    <select wire:model.live="{{ $startPhaseProperty }}">
                        @foreach($startPhases as $phase)
                            <option value="{{ $phase->id }}">{{ $phase->sequence }}. {{ $phase->name }}</option>
                        @endforeach
                    </select>
                    <small>Choose where this Order should enter the selected workflow.</small>
                </label>
            @endif
        </div>
        @endif
    </div>

    @if($emptyMessage)
        <small class="field-error validation-error">{{ $emptyMessage }}</small>
    @elseif($errorField && $errors->has($errorField))
        <small class="field-error validation-error">{{ $errors->first($errorField) }}</small>
    @endif

    @if($startPhaseErrorField && $errors->has($startPhaseErrorField))
        <small class="field-error validation-error">{{ $errors->first($startPhaseErrorField) }}</small>
    @endif

    @if($footnote)
        <p class="ft-create-workflow-footnote">{{ $footnote }}</p>
    @endif
</section>
