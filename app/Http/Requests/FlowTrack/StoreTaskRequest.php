<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($job = $this->route('job'))
            ? ($this->user()?->hasFlowAction('tasks', 'create', $job) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
            'workflow_phase_id' => ['nullable', 'integer', 'exists:workflow_phases,id'],
            'parent_id' => ['nullable', 'integer', 'exists:flow_tasks,id'],
            'dependency_id' => ['nullable', 'integer', 'exists:flow_tasks,id', 'different:parent_id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_rule' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'attention_required' => ['sometimes', 'boolean'],
            'blocker_reason' => ['nullable', 'string', 'max:3000'],
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*' => ['required', 'string', 'max:255'],
        ];
    }
}
