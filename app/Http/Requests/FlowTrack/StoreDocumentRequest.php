<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasFlowAction('documents', 'create') ?? false;
    }

    public function rules(): array
    {
        return [
            'job_id' => ['required', 'integer', 'exists:flow_jobs,id'],
            'document_id' => ['nullable', 'integer', 'exists:flow_documents,id'],
            'document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $this->user()->currentWorkspaceId())->where('type', MasterDataType::DocumentCategory->value)->where('status', 'active'))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,zip'],
            'is_final' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
