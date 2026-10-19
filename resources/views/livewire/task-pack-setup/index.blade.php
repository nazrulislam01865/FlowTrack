@php
    $masterData = app(\App\Services\MasterDataService::class);
    $canCreateTaskPack = auth()->user()->canModule('taskpacks', 'create');
    $canEditTaskPack = auth()->user()->canModule('taskpacks', 'edit');
    $canDeleteTaskPack = auth()->user()->canModule('taskpacks', 'delete');
@endphp
<div wire:init="loadTaskPacks" class="ft-admin-reference ft-taskpack-reference">
    <x-setup.page-header title="Task Pack Setup" description="Create reusable task sequences that activate when a Job enters a workflow phase" :wrap-actions="false">
        <x-slot:actions>@if($canCreateTaskPack)<a href="{{ route('task-pack.create') }}" wire:navigate class="ft-admin-primary">＋ Add Task Pack</a>@endif</x-slot:actions>
    </x-setup.page-header>

    @if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif
    @error('pack')<div class="flash error">{{ $message }}</div>@enderror
    @error('item')<div class="flash error">{{ $message }}</div>@enderror

    @if(!$showPackDeleteModal)
    <div class="ft-admin-stats">
        <div><span>Total Task Packs</span><b>{{ $packsReady ? $totalPacks : '…' }}</b></div>
        <div><span>Active Task Packs</span><b>{{ $packsReady ? $activePacks : '…' }}</b></div>
        <div><span>Configured Tasks</span><b>{{ $packsReady ? $configuredTasks : '…' }}</b></div>
        <div><span>Mapped Phases</span><b>{{ $packsReady ? $mappedPhases : '…' }}</b></div>
    </div>

    @if(!$packsReady)
        @include('livewire.shared.card-list-placeholder', ['cards' => 4])
    @else
    <x-setup.list class="ft-taskpack-grid">
        @forelse($packs as $pack)
            <section class="ft-taskpack-card">
                <div class="ft-taskpack-card-head">
                    <div>
                        <h2>{{ $pack->name }}</h2>
                        <p>{{ $pack->code }} · {{ $pack->items->count() }} predefined task{{ $pack->items->count() === 1 ? '' : 's' }} · {{ $pack->is_active ? 'Active' : 'Inactive' }}</p>
                    </div>
                    <div class="ft-taskpack-card-actions">
                        @if($canEditTaskPack)<a class="ft-admin-outline-small" href="{{ route('task-pack.edit', $pack->id) }}" wire:navigate>Edit</a>@endif
                        @if($canDeleteTaskPack)<button type="button" class="ft-admin-danger-small" wire:click="requestDeletePack({{ $pack->id }})" wire:loading.attr="disabled" wire:target="requestDeletePack">Delete</button>@endif
                        @if(!$canEditTaskPack && !$canDeleteTaskPack)<span class="small muted">View only</span>@endif
                    </div>
                </div>
                <p class="ft-taskpack-description">{{ $pack->description ?: 'No description' }}</p>

                <div class="ft-taskpack-items">
                    @forelse($pack->items as $item)
                        <div class="ft-taskpack-item-row ft-taskpack-item-row--colored" style="{{ \App\Support\MasterColor::style($item->color ?? '#2563EB') }}">
                            <div>
                                <b><span class="ft-task-color-dot" aria-hidden="true"></span>{{ $loop->iteration }}. {{ $item->title }}</b>
                                <small>
                                    @if(($item->default_assignee_type ?? 'user') === 'supplier')
                                        Supplier{{ $item->defaultAssignee ? ' + '.$item->defaultAssignee->name.' (internal backup)' : '' }}
                                    @else
                                        {{ $item->defaultAssignee?->name ?? 'Unassigned' }}
                                    @endif
                                    · Due set from Task details ·
                                    @if($item->priority)
                                        @php
                                            $itemPriorityColor = $masterData->displayColorFor('priority', $item->priority->name);
                                        @endphp
                                        <span class="ft-master-color-text" style="{{ \App\Support\MasterColor::style($itemPriorityColor) }}">{{ $item->priority->name }}</span>
                                    @else
                                        Use Job priority
                                    @endif
                                    @if($item->documentCategory) · Required file: {{ $item->documentCategory->name }} @endif
                                </small>
                            </div>
                            <span class="{{ $item->is_required ? 'is-required' : 'is-optional' }}">{{ $item->is_required ? 'Mandatory' : 'Optional' }}</span>
                        </div>
                    @empty
                        <div class="ft-taskpack-empty">No predefined tasks.</div>
                    @endforelse
                </div>
            </section>
        @empty
            <div class="ft-admin-empty-wide">No Task Packs configured. Use “Add Task Pack” to create the first one.</div>
        @endforelse
    </x-setup.list>
    @endif

    @endif

    @if($showPackDeleteModal)
        <x-setup.safe-delete-modal title="Delete Task Pack permanently?" close-action="closePackDelete" label="Delete Task Pack permanently">
                <div class="flash error ft-delete-impact-flush">
                    This permanently deletes this reusable Task Pack setup. Existing Job snapshots and Job Tasks are not deleted.
                </div>

                <div>
                    <b class="ft-delete-impact-title">{{ $packDeleteImpact['name'] ?? 'Task Pack' }}</b>
                    <span class="ft-delete-impact-subtitle">
                        FlowTrack checked Workflow mappings and Jobs that originated from those Workflows before allowing deletion.
                    </span>
                </div>

                @if(!($packDeleteImpact['can_delete'] ?? true))
                    <div class="flash error ft-delete-impact-flush">
                        {{ $packDeleteImpact['blocked_reason'] ?? 'This Task Pack cannot be deleted.' }}
                    </div>
                @endif

                <div class="ft-admin-stats ft-delete-impact-stats">
                    <div><span>Mapped phases</span><b>{{ $packDeleteImpact['mapped_phase_count'] ?? 0 }}</b></div>
                    <div><span>Jobs preserved</span><b>{{ $packDeleteImpact['job_count'] ?? 0 }}</b></div>
                    <div><span>Tasks preserved</span><b>{{ $packDeleteImpact['task_count'] ?? 0 }}</b></div>
                </div>

                @if(($packDeleteImpact['mapped_phase_count'] ?? 0) > 0)
                    <div class="ft-delete-impact-box ft-delete-impact-box--info">
                        <b class="ft-delete-impact-heading">Workflow phases using this Task Pack</b>
                        <div class="ft-delete-impact-list ft-delete-impact-list--compact">
                            @foreach(($packDeleteImpact['mapped_phases'] ?? []) as $phase)
                                <span class="ft-delete-impact-item"><b>{{ $phase['workflow_name'] }}</b> · Stage {{ $phase['sequence'] }} · <span class="ft-phase-color-label" style="{{ \App\Support\MasterColor::style($phase['color'] ?? null) }}">{{ $phase['name'] }}</span></span>
                            @endforeach
                        </div>
                        @if(($packDeleteImpact['mapped_phase_count'] ?? 0) > count($packDeleteImpact['mapped_phases'] ?? []))
                            <small class="ft-delete-impact-more">And {{ ($packDeleteImpact['mapped_phase_count'] ?? 0) - count($packDeleteImpact['mapped_phases'] ?? []) }} more mapped phases.</small>
                        @endif
                        <small class="ft-delete-impact-note">These Workflow phases will remain, but their Task Pack assignment will be removed.</small>
                    </div>
                @endif

                @if(($packDeleteImpact['job_count'] ?? 0) > 0)
                    <div class="ft-delete-impact-box ft-delete-impact-box--danger">
                        <b class="ft-delete-impact-heading ft-delete-impact-heading--danger">Jobs that remain independent of this Task Pack</b>
                        <div class="ft-delete-impact-list">
                            @foreach(($packDeleteImpact['jobs'] ?? []) as $job)
                                <div class="ft-delete-impact-row">
                                    <span class="ft-delete-impact-job"><b>{{ $job['job_number'] }}</b> · {{ $job['title'] }}</span>
                                    @if($job['trashed'] ?? false)<small class="ft-delete-impact-muted">Already trashed</small>@endif
                                </div>
                            @endforeach
                        </div>
                        @if(($packDeleteImpact['job_count'] ?? 0) > count($packDeleteImpact['jobs'] ?? []))
                            <small class="ft-delete-impact-more">And {{ ($packDeleteImpact['job_count'] ?? 0) - count($packDeleteImpact['jobs'] ?? []) }} more linked Jobs.</small>
                        @endif
                    </div>
                @endif

                <p class="ft-delete-impact-copy">
                    Deleting this reusable Task Pack does not delete existing Job Tasks. Older Jobs are snapshotted first when needed, and each Job keeps its own copied phase/task definitions.
                </p>
            <x-slot:footer>
                <button type="button" class="ft-admin-cancel" wire:click="closePackDelete">Cancel</button>
                @if($packDeleteImpact['can_delete'] ?? true)
                    <button type="button" class="ft-admin-danger" wire:click="confirmDeletePack" wire:loading.attr="disabled" wire:target="confirmDeletePack">
                        <span wire:loading.remove wire:target="confirmDeletePack">Delete Task Pack only</span>
                        <span wire:loading wire:target="confirmDeletePack">Deleting…</span>
                    </button>
                @endif
            </x-slot:footer>
        </x-setup.safe-delete-modal>
    @endif
</div>
