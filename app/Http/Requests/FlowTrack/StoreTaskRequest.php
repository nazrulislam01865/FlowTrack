<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        /** @var FlowJob $job */
        $job = $this->route('job');
        $workspaceId = $this->user()->currentWorkspaceId();
        $workspaceMember = Rule::exists('workspace_memberships', 'user_id')
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));

        return [
            'workflow_phase_id' => ['nullable', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query->where('workflow_template_id', $job->workflow_template_id)->where('is_active', true))],
            'parent_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query->where('flow_job_id', $job->id)->whereNull('deleted_at'))],
            'dependency_id' => ['nullable', 'integer', 'different:parent_id', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query->where('flow_job_id', $job->id)->whereNull('deleted_at'))],
            'assignee_id' => ['nullable', 'integer', $workspaceMember],
            'priority_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->whereNull('deleted_at'))],
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
