<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveJobPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($job = $this->route('job'))
            ? (($this->user()?->hasFlowAction('jobs', 'edit_all', $job) ?? false)
                || ($this->user()?->hasFlowAction('jobs', 'edit_own', $job) ?? false))
            : false;
    }

    public function rules(): array
    {
        /** @var FlowJob $job */
        $job = $this->route('job');

        return [
            'phase_id' => ['required', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query->where('workflow_template_id', $job->workflow_template_id)->where('is_active', true))],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
