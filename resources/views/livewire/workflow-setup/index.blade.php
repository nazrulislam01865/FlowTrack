@php
    $canCreateWorkflow = auth()->user()->canModule('workflow', 'create');
    $canEditWorkflow = auth()->user()->canModule('workflow', 'edit');
    $canDeleteWorkflow = auth()->user()->canModule('workflow', 'delete');
@endphp
<div class="ft-admin-reference ft-workflow-reference">
    @if(!$showWorkflowDeleteModal)
    <x-setup.page-header title="Workflow Setup" description="Configure Inquiry and Order workflows in one place. Build and edit reusable Task Packs separately.">
        <x-slot:actions>
            @if(auth()->user()->canAccess('taskpacks.view'))<a href="{{ route('task-pack.setup') }}" wire:navigate class="ft-admin-outline">Task Pack Setup</a>@endif
            @if($canCreateWorkflow && $selected)
                <a href="{{ route('workflow.create', ['source' => $selected->id]) }}" wire:navigate class="ft-admin-outline">Duplicate</a>
            @elseif($canCreateWorkflow)
                <span class="ft-admin-outline is-disabled">Duplicate</span>
            @endif
            @if($canCreateWorkflow)<a href="{{ route('workflow.create') }}" wire:navigate class="ft-admin-primary">＋ New Workflow</a>@endif
        </x-slot:actions>
    </x-setup.page-header>

    @if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif
    @error('workflow')<div class="flash error">{{ $message }}</div>@enderror
    @error('phase')<div class="flash error">{{ $message }}</div>@enderror

    <div class="ft-admin-stats">
        <div><span>Active Templates</span><b>{{ $activeTemplates }}</b></div>
        <div><span>Phases in Selected Workflow</span><b>{{ $selectedPhaseCount }}</b></div>
        <div><span>Allowed Starting Stages</span><b>{{ $allowedStartingStages }}</b></div>
        <div><span>Automatic Transitions</span><b>{{ $automaticTransitions }}</b></div>
    </div>

    <div class="ft-workflow-admin-layout">
        <x-setup.list tag="aside" class="ft-workflow-template-list">
            <div class="ft-workflow-list-label">WORKFLOW TEMPLATES</div>
            @forelse($workflows as $workflow)
                <button type="button" class="{{ $workflow->id === $selectedWorkflowId ? 'active' : '' }}" wire:click="selectWorkflow({{ $workflow->id }})">
                    <b>{{ $workflow->name }}</b>
                    <span>{{ ucfirst(rtrim((string) $workflow->applies_to, 's')) }} · {{ $workflow->phases->count() }} phases · {{ $workflow->is_active ? 'Active' : 'Inactive' }}</span>
                </button>
            @empty
                <div class="ft-workflow-list-empty">No workflows configured.</div>
            @endforelse
        </x-setup.list>

        <x-setup.editor-panel class="ft-workflow-editor-card">
            @if($selected)
                <div class="ft-workflow-editor-head">
                    <div>
                        <h2>{{ $selected->name }}</h2>
                        <p>{{ $selected->description ?: 'No description' }}</p>
                        <span class="ft-auto-pill automatic">{{ $selectedIsOrderWorkflow ? 'Order workflow' : 'Inquiry workflow' }}</span>
                    </div>
                    <div class="ft-workflow-editor-actions">
                        @if($canEditWorkflow)<a href="{{ route('workflow.edit', $selected->id) }}" wire:navigate class="ft-admin-outline">Edit Details</a>@endif
                        @if($canDeleteWorkflow)<button type="button" class="ft-admin-danger" wire:click="requestDeleteWorkflow({{ $selected->id }})" wire:loading.attr="disabled" wire:target="requestDeleteWorkflow">Delete Workflow</button>@endif
                        @if($canEditWorkflow && !$selectedIsOrderWorkflow)<button type="button" class="ft-admin-primary" wire:click="openPhase">＋ Add Phase</button>@endif
                        @if(!$canEditWorkflow && !$canDeleteWorkflow)<span class="small muted">View only</span>@endif
                    </div>
                </div>

                @if($selectedIsOrderWorkflow)
                    <div class="ft-workflow-rule-note">
                        <b>Order workflow runtime · {{ $orderWorkflowReady ? 'Ready' : 'Setup incomplete' }}</b>
                        <p>The seven Order stages are fixed: New Order, Artwork, Production, QC, Shipment, Billing and Payment. Edit stage colors and map a compatible Task Pack here. Edit the tasks themselves from <a href="{{ route('task-pack.setup') }}" wire:navigate>Task Pack Setup</a>. The existing Order branching and action logic is unchanged.</p>
                    </div>
                @else
                    <div class="ft-workflow-rule-note">
                        <b>Automatic phase controls</b>
                        <p>Active Inquiry phases automatically use the standard start, skip and auto-move settings. Task Pack requirements remain the gate for phase completion.</p>
                    </div>
                @endif

                <div class="ft-workflow-table-wrap">
                    <table class="ft-workflow-config-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Phase</th>
                                <th>Task Pack</th>
                                <th>Entry / Exit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($selected->phases as $phase)
                                <tr class="ft-phase-row-color" style="{{ \App\Support\MasterColor::style($phase->color) }}" wire:key="workflow-phase-row-{{ $phase->id }}">
                                    <td>
                                        @if($canEditWorkflow && !$selectedIsOrderWorkflow)
                                            <div class="ft-sequence-buttons">
                                                <button type="button" wire:click="move({{ $phase->id }}, -1)" @disabled($loop->first)>↑</button>
                                                <button type="button" wire:click="move({{ $phase->id }}, 1)" @disabled($loop->last)>↓</button>
                                            </div>
                                        @else
                                            <span>{{ $phase->sequence }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <b>{{ $phase->name }}</b>
                                        <span>Stage {{ $phase->sequence }}</span>
                                    </td>
                                    <td>{{ $phase->taskPack?->name ?? 'No Task Pack' }}</td>
                                    <td class="ft-entry-exit"><div><b>In:</b> {{ $phase->entry_condition ?: '—' }}</div><div><b>Out:</b> {{ $phase->exit_condition ?: '—' }}</div></td>
                                    <td><span class="ft-auto-pill {{ $phase->is_active ? 'automatic' : '' }}">{{ $phase->is_active ? 'Active' : 'Inactive' }}</span></td>
                                    <td>
                                        <div class="ft-row-action-buttons">
                                            @if($canEditWorkflow)<button type="button" wire:click="openPhase({{ $phase->id }})">Edit</button>@endif
                                            @if($canDeleteWorkflow && !$selectedIsOrderWorkflow)<button type="button" wire:click="deletePhase({{ $phase->id }})" wire:confirm="Remove this workflow phase?">Remove</button>@endif
                                            @if(!$canEditWorkflow && !$canDeleteWorkflow)<span class="small muted">View only</span>@endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="ft-workflow-empty-row">No phases configured. Add the first phase to this workflow.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="ft-admin-empty-wide">Create a Workflow to begin.</div>
            @endif
        </x-setup.editor-panel>
    </div>


    @endif

    @if($showWorkflowDeleteModal)
        <x-setup.safe-delete-modal title="Delete Workflow permanently?" close-action="closeWorkflowDelete" label="Delete Workflow permanently">
                <div class="flash error ft-delete-impact-flush">
                    This permanently deletes this reusable Workflow setup. Existing Job snapshots and Job Tasks are not deleted.
                </div>

                <div>
                    <b class="ft-delete-impact-title">{{ $workflowDeleteImpact['name'] ?? 'Workflow' }}</b>
                    <span class="ft-delete-impact-subtitle">
                        FlowTrack checked Jobs created from this Workflow. Existing Jobs use private snapshots and will not be deleted.
                    </span>
                </div>

                @if(!empty($workflowDeleteImpact['replacement_default']))
                    <div class="flash success ft-delete-impact-flush">
                        This is the current default Workflow. After deletion,
                        <b>{{ $workflowDeleteImpact['replacement_default']['name'] }}</b> will become the active default automatically.
                    </div>
                @elseif($workflowDeleteImpact['will_leave_no_default'] ?? false)
                    <div class="flash success ft-delete-impact-flush">
                        This is the last Workflow. It can be deleted; the next Workflow you create will become the default automatically.
                    </div>
                @endif

                @if(!($workflowDeleteImpact['can_delete'] ?? true))
                    <div class="flash error ft-delete-impact-flush">
                        {{ $workflowDeleteImpact['blocked_reason'] ?? 'This Workflow cannot be deleted.' }}
                    </div>
                @else
                    <div class="ft-admin-stats ft-delete-impact-stats">
                        <div><span>Workflow phases</span><b>{{ $workflowDeleteImpact['phase_count'] ?? 0 }}</b></div>
                        <div><span>Jobs preserved</span><b>{{ $workflowDeleteImpact['job_count'] ?? 0 }}</b></div>
                        <div><span>Tasks preserved</span><b>{{ $workflowDeleteImpact['task_count'] ?? 0 }}</b></div>
                    </div>

                    @if(($workflowDeleteImpact['job_count'] ?? 0) > 0)
                        <div class="ft-delete-impact-box ft-delete-impact-box--danger">
                            <b class="ft-delete-impact-heading ft-delete-impact-heading--danger">Jobs that will remain unchanged</b>
                            <div class="ft-delete-impact-list">
                                @foreach(($workflowDeleteImpact['jobs'] ?? []) as $job)
                                    <div class="ft-delete-impact-row">
                                        <span class="ft-delete-impact-job"><b>{{ $job['job_number'] }}</b> · {{ $job['title'] }}</span>
                                        @if($job['trashed'] ?? false)<small class="ft-delete-impact-muted">Already trashed</small>@endif
                                    </div>
                                @endforeach
                            </div>
                            @if(($workflowDeleteImpact['job_count'] ?? 0) > count($workflowDeleteImpact['jobs'] ?? []))
                                <small class="ft-delete-impact-more">And {{ ($workflowDeleteImpact['job_count'] ?? 0) - count($workflowDeleteImpact['jobs'] ?? []) }} more linked Jobs.</small>
                            @endif
                        </div>
                    @endif

                    @if(($workflowDeleteImpact['task_count'] ?? 0) > 0)
                        <div class="ft-delete-impact-box">
                            <b class="ft-delete-impact-heading">Tasks included in those Jobs</b>
                            <div class="ft-delete-impact-list ft-delete-impact-list--compact">
                                @foreach(($workflowDeleteImpact['tasks'] ?? []) as $task)
                                    <span class="ft-delete-impact-item"><b >{{ $task['task_number'] }}</b> · {{ $task['title'] }} @if($task['job_number']) · {{ $task['job_number'] }} @endif</span>
                                @endforeach
                            </div>
                            @if(($workflowDeleteImpact['task_count'] ?? 0) > count($workflowDeleteImpact['tasks'] ?? []))
                                <small class="ft-delete-impact-more">And {{ ($workflowDeleteImpact['task_count'] ?? 0) - count($workflowDeleteImpact['tasks'] ?? []) }} more Tasks.</small>
                            @endif
                        </div>
                    @endif

                    <p class="ft-delete-impact-copy">
                        Continuing deletes only the reusable Workflow setup and its setup phases. Any older Job that still points directly to this Workflow is first converted to its own private snapshot. No Job, Task, document, comment, or history record is deleted.
                    </p>
                @endif
            <x-slot:footer>
                <button type="button" class="ft-admin-cancel" wire:click="closeWorkflowDelete">Cancel</button>
                @if($workflowDeleteImpact['can_delete'] ?? false)
                    <button type="button" class="ft-admin-danger" wire:click="confirmDeleteWorkflow" wire:loading.attr="disabled" wire:target="confirmDeleteWorkflow">
                        <span wire:loading.remove wire:target="confirmDeleteWorkflow">Delete Workflow only</span>
                        <span wire:loading wire:target="confirmDeleteWorkflow">Deleting…</span>
                    </button>
                @endif
            </x-slot:footer>
        </x-setup.safe-delete-modal>
    @endif

    @if($showPhaseModal)
        <x-setup.editor-modal :label="$editPhaseId ? 'Edit Workflow Phase' : 'Add Workflow Phase'" close-action="closePhase">
            <div class="ft-phase-modal-head">
                <h2>{{ $selectedIsOrderWorkflow ? 'Edit Order Stage' : ($editPhaseId ? 'Edit Workflow Phase' : 'Add Workflow Phase') }}</h2>
                <button type="button" wire:click="closePhase">×</button>
            </div>
            <div class="ft-phase-modal-body">
                <div class="ft-phase-two-col">
                    <div class="ft-admin-field">
                        <label>Phase name *</label>
                        <input type="text" wire:model="phaseName" placeholder="New Phase" @disabled($selectedIsOrderWorkflow)>
                        @error('phaseName')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="ft-admin-field">
                        <label>Short label *</label>
                        <input type="text" wire:model="shortName" placeholder="New" @disabled($selectedIsOrderWorkflow)>
                        @error('shortName')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                @if($selectedIsOrderWorkflow)<div class="small muted" style="margin:-6px 0 12px">Order stage names and sequence are fixed because the runtime automation keys depend on them.</div>@endif
                <div class="ft-admin-field">
                    <label>Phase color *</label>
                    <div class="ft-master-color-picker-row" style="{{ \App\Support\MasterColor::style($phaseColor) }}">
                        <x-setup.color-picker model="phaseColor" label="Choose workflow phase color" />
                        <input type="text" maxlength="7" wire:model.blur="phaseColor" placeholder="#2563EB" aria-label="Workflow phase hex color">
                        <span class="ft-master-color-preview"><i class="ft-master-color-dot"></i><span>This color is used for this phase across FlowTrack.</span></span>
                    </div>
                    @error('phaseColor')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
                <div class="ft-admin-field">
                    <label>Task Pack {{ $selectedIsOrderWorkflow ? '*' : '' }}</label>
                    <select wire:model="taskPackId">
                        <option value="">{{ $selectedIsOrderWorkflow ? 'Select compatible Task Pack' : 'No Task Pack' }}</option>
                        @foreach($taskPacks as $taskPack)<option value="{{ $taskPack->id }}">{{ $taskPack->name }}</option>@endforeach
                    </select>
                    @if($selectedIsOrderWorkflow)
                        <small>Only Task Packs containing this stage's protected Order automation tasks are shown. Change task titles, assignees, durations, documents and extra tasks from Task Pack Setup.</small>
                    @endif
                    @error('taskPackId')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
                <div class="ft-admin-field">
                    <label>Entry rule</label>
                    <input type="text" wire:model="entryCondition" placeholder="Previous phase complete" @disabled($selectedIsOrderWorkflow)>
                </div>
                <div class="ft-admin-field">
                    <label>Exit control</label>
                    <input type="text" wire:model="exitCondition" placeholder="Required work complete" @disabled($selectedIsOrderWorkflow)>
                </div>

                <div class="ft-phase-checks">
                    <label><input type="checkbox" wire:model="phaseActive" @disabled($selectedIsOrderWorkflow)><span>Phase active</span></label>
                </div>
            </div>
            <div class="ft-phase-modal-footer">
                <button type="button" class="ft-admin-cancel" wire:click="closePhase">Cancel</button>
                <button type="button" class="ft-admin-primary" wire:click="savePhase">Save Phase</button>
            </div>
        </x-setup.editor-modal>
    @endif
</div>
