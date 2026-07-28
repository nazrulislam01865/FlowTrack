<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasFlowAction('jobs', 'create') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'workflow_template_id' => ['required', 'integer', 'exists:workflow_templates,id'],
            'start_phase_id' => ['required', 'integer', 'exists:workflow_phases,id'],
            'product_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'product_category_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'currency_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'priority_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_date' => ['nullable', 'date'],
            'start_reason' => ['nullable', 'string', 'max:2000'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
            'draft' => ['sometimes', 'boolean'],
        ];
    }
}
