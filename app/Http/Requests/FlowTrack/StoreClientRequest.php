<?php

namespace App\Http\Requests\FlowTrack;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('client')
            ? ($this->user()?->hasFlowAction('clients', 'edit_all', $this->route('client')) || $this->user()?->hasFlowAction('clients', 'edit_own', $this->route('client')))
            : ($this->user()?->hasFlowAction('clients', 'create') ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                $this->route('client') ? 'required' : 'nullable',
                'string',
                'max:30',
                Rule::unique('clients', 'code')
                    ->where(fn ($query) => $query->where('workspace_id', $this->user()->currentWorkspaceId()))
                    ->ignore($this->route('client')),
            ],
            'country' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'account_manager_id' => ['nullable', 'integer', Rule::exists('workspace_memberships', 'user_id')->where(fn ($query) => $query->where('workspace_id', $this->user()->currentWorkspaceId())->where('status', 'active'))],
            'preferred_language' => ['nullable', 'string', 'max:20'],
            'outstanding_balance' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
