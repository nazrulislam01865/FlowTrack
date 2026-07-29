<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

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
