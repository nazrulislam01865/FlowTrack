<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;

class MoveJobPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($job = $this->route('job'))
            ? (($this->user()?->hasFlowAction('jobs', 'edit_all', $job) ?? false) || ($this->user()?->hasFlowAction('jobs', 'edit_own', $job) ?? false))
            : false;
    }

    public function rules(): array
    {
        return [
            'phase_id' => ['required', 'integer', 'exists:workflow_phases,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
