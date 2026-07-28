<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($task = $this->route('task'))
            ? (($this->user()?->hasFlowAction('tasks', 'edit_all', $task) ?? false) || ($this->user()?->hasFlowAction('tasks', 'edit_own', $task) ?? false))
            : false;
    }

    public function rules(): array
    {
        return [
            'task_status_id' => ['required', 'integer', 'exists:master_records,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'attention_required' => ['sometimes', 'boolean'],
            'blocker_reason' => ['nullable', 'string', 'max:3000'],
            'completed_checklist_ids' => ['nullable', 'array'],
            'completed_checklist_ids.*' => ['integer', 'exists:flow_task_checklist_items,id'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
