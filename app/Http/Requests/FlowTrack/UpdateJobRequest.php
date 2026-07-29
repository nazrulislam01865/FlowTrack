<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var FlowJob|null $job */
        $job = $this->route('job');

        return $job
            && (($this->user()?->hasFlowAction('jobs', 'edit_all', $job) ?? false)
                || ($this->user()?->hasFlowAction('jobs', 'edit_own', $job) ?? false));
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'products' => ['required', 'array', 'min:1', 'max:25'],
            'products.*.product_id' => ['required', 'integer', 'distinct', 'exists:master_records,id'],
            'products.*.product_category_id' => ['required', 'integer', 'exists:master_records,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'currency_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'priority_id' => ['nullable', 'integer', 'exists:master_records,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'request_description' => ['nullable', 'string', 'max:10000'],
            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required' => 'Add at least one product to the Job.',
            'products.*.product_category_id.required' => 'Select a category for every product row.',
            'products.*.product_id.required' => 'Select a product for every product row.',
            'products.*.product_id.distinct' => 'The same product cannot be added more than once.',
            'products.*.quantity.required' => 'Enter a quantity for every product row.',
        ];
    }
}
