<div class="ft-order-workflow-page" data-ft-feedback-scope="form">
    <header class="ft-order-workflow-page-head">
        <div>
            <div class="ft-order-workflow-breadcrumb">Administration / Order Workflow Setup</div>
            <h1>Order Workflow Setup</h1>
            <p>Manage the seven order stages and the tasks inside each stage from one simple screen. Task dependencies are handled automatically by the backend.</p>
        </div>
        <div class="ft-order-workflow-top-actions">
            <button
                type="button"
                class="ft-order-workflow-btn"
                wire:click="resetWorkflow"
                wire:confirm="Reset the Order workflow to the prototype default stages and tasks? The reset is not published until you save the workflow."
            >Reset demo</button>
            <button type="button" class="ft-order-workflow-btn ft-order-workflow-btn--primary" wire:click="saveWorkflow" wire:loading.attr="disabled" wire:target="saveWorkflow">
                <span wire:loading.remove wire:target="saveWorkflow">Save workflow</span>
                <span wire:loading wire:target="saveWorkflow">Saving…</span>
            </button>
        </div>
    </header>

    @error('stages')<div class="ft-order-workflow-error">{{ $message }}</div>@enderror

    <section class="ft-order-workflow-stats">
        <x-order-workflow.stat-card label="Workflow" :value="$workflowName" />
        <x-order-workflow.stat-card label="Stages" value="7" />
        <x-order-workflow.stat-card label="Total tasks" :value="collect($stages)->sum(fn ($stage) => count($stage['tasks'] ?? []))" />
        <x-order-workflow.stat-card label="Dependency mode" value="Automatic" />
    </section>

    <x-order-workflow.notice icon="⚙" title="No dependency setup required.">
        The backend opens the first task of a stage, then unlocks the next applicable task when the previous required task is completed. Special branches such as artwork revision, sample approval, production/QC issues and payment are handled by workflow rules in code/service configuration.
    </x-order-workflow.notice>

    <x-order-workflow.notice icon="📎" title="Documents are configured per task." tone="document">
        Turn on Document upload only for tasks that need a file. Choose the document type and whether the task must be blocked until the document is uploaded.
    </x-order-workflow.notice>

    <section class="ft-order-workflow-card">
        <div class="ft-order-workflow-card-head">
            <div>
                <h2>7-stage order workflow</h2>
                <p>Stage order stays fixed. You can change colors, task names, default team, due offset and whether a task is required.</p>
            </div>
            <span class="ft-order-workflow-state">Active</span>
        </div>

        <div class="ft-order-workflow-stage-strip">
            @foreach($stages as $index => $stage)
                <x-order-workflow.stage-tile :stage="$stage" :index="$index" />
            @endforeach
        </div>

        <div class="ft-order-workflow-stage-list">
            @foreach($stages as $index => $stage)
                <x-order-workflow.stage-row :stage="$stage" :index="$index" />
            @endforeach
        </div>

        <div class="ft-order-workflow-save-bar">
            <span>
                <b>{{ $dirty ? 'Unsaved changes' : 'No unsaved changes' }}</b>
                <small> Active Orders use this saved workflow; completed/cancelled Orders keep their historical workflow.</small>
            </span>
            <button type="button" class="ft-order-workflow-btn ft-order-workflow-btn--primary" wire:click="saveWorkflow" wire:loading.attr="disabled" wire:target="saveWorkflow">
                <span wire:loading.remove wire:target="saveWorkflow">Save workflow</span>
                <span wire:loading wire:target="saveWorkflow">Saving…</span>
            </button>
        </div>
    </section>

    @if($showStageModal && $editingStageIndex !== null && isset($stages[$editingStageIndex]))
        <x-ui.modal id="order-workflow-stage-editor" :title="'Edit '.$stages[$editingStageIndex]['name']" size="lg" :open="true" class="ft-order-workflow-modal">
            <x-slot:close>
                <button type="button" class="ft-order-workflow-modal-close" wire:click="closeStageEditor" aria-label="Close">×</button>
            </x-slot:close>

            <div class="ft-order-workflow-modal-grid">
                <div class="ft-order-workflow-editor-field">
                    <label>Stage name</label>
                    <input type="text" value="{{ $stages[$editingStageIndex]['name'] }}" disabled>
                    <small>The seven stage names and order remain fixed.</small>
                </div>
                <div class="ft-order-workflow-editor-field">
                    <label>Stage color</label>
                    <x-setup.color-picker model="editingStageColor" label="Choose stage color" input-class="" container-class="ft-order-workflow-color-control" :show-text="true" />
                    @error('editingStageColor')<span class="validation-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="ft-order-workflow-task-editor-head">
                <div>
                    <b>Tasks in this stage</b>
                    <p>Put tasks in the normal working order. Turn on Document upload only where a file is needed.</p>
                </div>
                <button type="button" class="ft-order-workflow-btn ft-order-workflow-btn--small" wire:click="addTask">＋ Add task</button>
            </div>

            @error('editingTasks')<div class="ft-order-workflow-error ft-order-workflow-error--compact">{{ $message }}</div>@enderror

            <div class="ft-order-workflow-task-edit-list">
                @foreach($editingTasks as $taskIndex => $task)
                    <x-order-workflow.task-edit-row
                        :task="$task"
                        :index="$taskIndex"
                        :count="count($editingTasks)"
                        :department-options="$departmentOptions"
                        :document-category-options="$documentCategoryOptions"
                    />
                @endforeach
            </div>

            <div class="ft-order-workflow-doc-help">
                <b>📎 Document behavior</b>
                <p>If “Must upload before completion” is enabled, FlowTrack blocks task completion until at least one valid document or task link in the selected category is attached. Files remain available in Order document history.</p>
            </div>

            <div class="ft-order-workflow-auto-dependency">
                <b>Automatic dependency rule</b>
                <p>When this stage opens, the first applicable required task becomes Ready. When it completes, the backend unlocks the next applicable required task. Users do not configure dependency links here.</p>
            </div>

            <x-slot:footer>
                <button type="button" class="ft-order-workflow-btn" wire:click="closeStageEditor">Cancel</button>
                <button type="button" class="ft-order-workflow-btn ft-order-workflow-btn--primary" wire:click="saveStageEditor">Save stage</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
