<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasFlowAction('jobs', 'create') ?? false;
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->currentWorkspaceId();
        $workspaceRecord = fn (string $table, string $column = 'id') => Rule::exists($table, $column)
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->whereNull('deleted_at'));
        $workspaceMember = Rule::exists('workspace_memberships', 'user_id')
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));

        return [
            'client_id' => ['required', 'integer', $workspaceRecord('clients')],
            'workflow_template_id' => ['required', 'integer', Rule::exists('workflow_templates', 'id')
                ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('is_active', true))],
            'start_phase_id' => ['required', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query
                    ->where('workflow_template_id', (int) $this->input('workflow_template_id'))
                    ->where('is_active', true)
                    ->where('allow_job_start', true))],

            'products' => ['nullable', 'array', 'min:1', 'max:25', 'required_without:product_id'],
            'products.*.product_id' => ['required', 'integer', 'distinct', $workspaceRecord('master_records')],
            'products.*.product_category_id' => ['required', 'integer', $workspaceRecord('master_records')],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'product_id' => ['nullable', 'integer', $workspaceRecord('master_records'), 'required_without:products'],
            'product_category_id' => ['nullable', 'integer', $workspaceRecord('master_records')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_without:products'],

            'currency_id' => ['nullable', 'integer', $workspaceRecord('master_records')],
            'priority_id' => ['nullable', 'integer', $workspaceRecord('master_records')],
            'owner_id' => ['nullable', 'integer', $workspaceMember],
            'coordinator_id' => ['nullable', 'integer', $workspaceMember],
            'title' => ['required', 'string', 'max:255'],
            'request_description' => ['nullable', 'string', 'max:10000'],
            'quoted_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_date' => ['nullable', 'date'],
            'start_reason' => ['nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,webp,zip,txt,csv'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'distinct', $workspaceMember],
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
            'attachments.max' => 'You can attach up to 10 files to a Job.',
            'attachments.*.max' => 'Each attachment must be 20 MB or smaller.',
            'attachments.*.mimes' => 'Attachments must be a supported document, image, spreadsheet, presentation, archive, text, or CSV file.',
        ];
    }
}
