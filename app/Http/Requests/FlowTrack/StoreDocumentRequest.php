<?php

namespace App\Http\Requests\FlowTrack;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->hasFlowAction('documents', 'create') ?? false)
            && ($this->user()?->hasFlowAction('documents', 'link') ?? false);
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->currentWorkspaceId();
        $job = FlowJob::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->find($this->integer('job_id'));

        return [
            'job_id' => ['required', 'integer', Rule::exists('flow_jobs', 'id')
                ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->whereNull('deleted_at'))],
            'document_id' => ['nullable', 'integer', Rule::exists('flow_documents', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('flow_job_id', $this->integer('job_id'))
                    ->whereNull('deleted_at'))],
            'workflow_phase_id' => ['required', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query->where('workflow_template_id', $job?->workflow_template_id ?? 0))],
            'task_pack_item_id' => ['nullable', 'integer', Rule::exists('task_pack_items', 'id')
                ->where(fn ($query) => $query->whereIn('task_pack_id', function ($subquery) use ($workspaceId): void {
                    $subquery->select('id')->from('task_packs')->where('workspace_id', $workspaceId);
                }))],
            'task_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query->where('flow_job_id', $this->integer('job_id'))->whereNull('deleted_at'))],
            'document_category_id' => ['required', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::DocumentCategory->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
            'file' => ['bail', 'required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,zip'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a document before uploading.',
            'file.uploaded' => 'The server rejected the upload before FlowTrack received it. Check that PHP upload_max_filesize is at least 25M and post_max_size is at least 30M.',
            'file.max' => 'The document must be 20 MB or smaller.',
            'file.mimes' => 'Use a PDF, DOC, DOCX, XLS, XLSX, PNG, JPG, WEBP, or ZIP document.',
        ];
    }
}
