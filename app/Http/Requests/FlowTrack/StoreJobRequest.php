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

            // New multi-product payload. Legacy single-product fields remain
            // accepted so older clients can continue creating Jobs safely.
            'products' => ['nullable', 'array', 'min:1', 'max:25', 'required_without:product_id'],
            'products.*.product_id' => ['required', 'integer', 'distinct', 'exists:master_records,id'],
            'products.*.product_category_id' => ['required', 'integer', 'exists:master_records,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'product_id' => ['nullable', 'integer', 'exists:master_records,id', 'required_without:products'],
            'product_category_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_without:products'],

            'currency_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'priority_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'request_description' => ['nullable', 'string', 'max:10000'],
            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_date' => ['nullable', 'date'],
            'start_reason' => ['nullable', 'string', 'max:2000'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
            'draft' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required_without' => 'Add at least one product to the Job.',
            'products.*.product_category_id.required' => 'Select a category for every product row.',
            'products.*.product_id.required' => 'Select a product for every product row.',
            'products.*.product_id.distinct' => 'The same product cannot be added more than once.',
            'products.*.quantity.required' => 'Enter a quantity for every product row.',
            'products.*.quantity.min' => 'Every product quantity must be at least 1.',
        ];
    }
}
