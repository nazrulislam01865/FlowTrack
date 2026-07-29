<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskDueDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $user = $this->user();

        return $task && $user
            ? app(FlowAuthorizationService::class)->canChangeTaskDueDate($user, $task)
            : false;
    }

    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
        ];
    }
}
