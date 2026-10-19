@props(['task', 'index', 'count', 'departmentOptions' => [], 'documentCategoryOptions' => []])
<div class="ft-order-workflow-task-editor" wire:key="order-workflow-task-{{ $index }}-{{ $task['id'] ?? 'new' }}">
    <div class="ft-order-workflow-task-editor__main">
        <div class="ft-order-workflow-task-order">
            <span>{{ $index + 1 }}</span>
            <div class="ft-order-workflow-order-buttons">
                <button type="button" wire:click="moveTask({{ $index }}, -1)" @disabled($index === 0) aria-label="Move task up">↑</button>
                <button type="button" wire:click="moveTask({{ $index }}, 1)" @disabled($index === $count - 1) aria-label="Move task down">↓</button>
            </div>
        </div>

        <div class="ft-order-workflow-editor-field ft-order-workflow-editor-field--title">
            <label>Task name</label>
            <input type="text" wire:model="editingTasks.{{ $index }}.title" maxlength="255">
            @error("editingTasks.$index.title")<span class="validation-error">{{ $message }}</span>@enderror
        </div>

        <div class="ft-order-workflow-editor-field">
            <label>Default team</label>
            <select wire:model="editingTasks.{{ $index }}.default_department_id">
                <option value="">No default team</option>
                @foreach($departmentOptions as $department)
                    <option value="{{ $department['id'] }}">{{ $department['name'] }}</option>
                @endforeach
            </select>
            @error("editingTasks.$index.default_department_id")<span class="validation-error">{{ $message }}</span>@enderror
        </div>

        <div class="ft-order-workflow-editor-field ft-order-workflow-editor-field--offset">
            <label>Due offset</label>
            <div class="ft-order-workflow-number-suffix">
                <input type="number" min="0" max="3650" wire:model="editingTasks.{{ $index }}.due_offset_days">
                <span>days</span>
            </div>
            @error("editingTasks.$index.due_offset_days")<span class="validation-error">{{ $message }}</span>@enderror
        </div>

        <label class="ft-order-workflow-check">
            <input type="checkbox" wire:model="editingTasks.{{ $index }}.is_required">
            <span>Required task</span>
        </label>

        <label class="ft-order-workflow-check ft-order-workflow-check--document">
            <input type="checkbox" wire:model.live="editingTasks.{{ $index }}.document_enabled">
            <span>📎 Document upload</span>
        </label>

        <button type="button" class="ft-order-workflow-remove-task" wire:click="removeTask({{ $index }})" @disabled($count <= 1) aria-label="Remove task">×</button>
    </div>

    @if(!empty($task['document_enabled']))
        <div class="ft-order-workflow-document-panel">
            <div class="ft-order-workflow-editor-field">
                <label>Document type</label>
                <select wire:model="editingTasks.{{ $index }}.document_category_id">
                    <option value="">Choose document type</option>
                    @foreach($documentCategoryOptions as $documentCategory)
                        <option value="{{ $documentCategory['id'] }}">{{ $documentCategory['name'] }}</option>
                    @endforeach
                </select>
                @error("editingTasks.$index.document_category_id")<span class="validation-error">{{ $message }}</span>@enderror
            </div>

            <div class="ft-order-workflow-document-checks">
                <label><input type="checkbox" wire:model="editingTasks.{{ $index }}.document_required_before_completion"> Must upload before completion</label>
                <label><input type="checkbox" wire:model="editingTasks.{{ $index }}.allow_multiple_documents"> Allow multiple files</label>
            </div>

            <div class="ft-order-workflow-editor-field ft-order-workflow-editor-field--instructions">
                <label>Instructions shown to user</label>
                <input type="text" wire:model="editingTasks.{{ $index }}.document_instructions" maxlength="1000" placeholder="e.g. Upload the signed customer PO">
                @error("editingTasks.$index.document_instructions")<span class="validation-error">{{ $message }}</span>@enderror
            </div>
        </div>
    @endif
</div>
