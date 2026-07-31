<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $user = $this->user();

        return $task && $user
            ? app(FlowAuthorizationService::class)->canUpdateTask($user, $task)
            : false;
    }

    public function rules(): array
    {
        /** @var FlowTask $task */
        $task = $this->route('task');
        $workspaceId = $this->user()->currentWorkspaceId();
        $workspaceMember = Rule::exists('workspace_memberships', 'user_id')
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'task_status_id' => ['required', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::TaskStatus->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
            'assignee_id' => ['nullable', 'integer', $workspaceMember],
            'due_date' => ['nullable', 'date'],
            'priority_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::Priority->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'attention_required' => ['sometimes', 'boolean'],
            'blocker_reason' => ['nullable', 'string', 'max:3000'],
            'completed_checklist_ids' => ['nullable', 'array'],
            'completed_checklist_ids.*' => ['integer', Rule::exists('flow_task_checklist_items', 'id')
                ->where(fn ($query) => $query->where('flow_task_id', $task->id))],
            'comment' => ['nullable', 'string', 'max:5000'],
            'mentioned_user_ids' => ['nullable', 'array', 'max:25'],
            'mentioned_user_ids.*' => ['integer', 'distinct', $workspaceMember],
        ];
    }
}
