<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Services\MentionService;
use App\Services\MasterDataService;
use Livewire\Attributes\On;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

trait ManagesInquiryDetail
{
    public function loadDetailSection(string $section, ?string $contextType = null, ?int $contextId = null): void
    {
        if (! in_array($section, ['products', 'taskflow', 'documents', 'activity'], true)) {
            return;
        }

        // IntersectionObserver callbacks may complete after the user changes tab,
        // opens another Inquiry, or navigates away. Ignore those stale requests
        // instead of returning 422 and letting an obsolete response participate
        // in the current Livewire morph.
        if ($contextType === 'inquiry') {
            if (
                ! $this->selectedInquiryId
                || (int) $this->selectedInquiryId !== (int) $contextId
                || $this->detailTab !== 'overview'
            ) {
                return;
            }

            $this->inquiryDetailSectionsReady[$section] = true;
            return;
        }

        // Backward-compatible path for callers that do not yet send context.
        if (! $this->selectedInquiryId || $this->detailTab !== 'overview') {
            return;
        }

        $this->inquiryDetailSectionsReady[$section] = true;
    }

    private function resetInquiryDetailProgressiveSections(): void
    {
        $this->inquiryDetailSectionsReady = [
            'products' => false,
            'taskflow' => false,
            'documents' => false,
            'activity' => false,
        ];
    }

    public function openInquiry(int $id): void
    {
        app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $id);
        $this->selectedInquiryId = $id;
        $this->resetInquiryDetailProgressiveSections();
        $this->userOptions = [];
        $this->showCreate = false;
        $this->detailTab = 'overview';
        $this->selectedTaskId = null;
        $this->resetInquiryRfqState();
        $this->showAddTaskForm = false;
        $this->editingInquiryProducts = false;
        $this->inquiryProductRows = [];
        $this->inquiryCategoryFilterOptions = [];
        $this->showAddInquiryProductForm = false;
        $this->inquiryProductSearch = '';
        $this->inquiryProductShowAllResults = false;
        $this->inquiryProductSelectedId = null;
        $this->inquiryProductCategory = '';
        $this->inquiryProductQuantity = '1';
        $this->inquiryProductUnitPrice = '0.00';
        $this->editInquiryProductItemId = null;
        $this->editInquiryProductCategory = '';
        $this->editInquiryProductName = '';
        $this->editInquiryProductQuantity = '1';
        $this->editInquiryProductUnitPrice = '';
        $this->editInquiryProductNotes = '';
        $this->resetPage('inquiryDocumentsPage');
        $this->resetPage('inquiryActivityPage');
    }

    public function closeInquiry(): void
    {
        $this->selectedInquiryId = null;
        $this->resetInquiryDetailProgressiveSections();
        $this->selectedTaskId = null;
        $this->resetInquiryRfqState();
        $this->showWorkflowManager = false;
        $this->showAddTaskForm = false;
        $this->editingInquiryProducts = false;
        $this->inquiryProductRows = [];
        $this->inquiryCategoryFilterOptions = [];
        $this->showAddInquiryProductForm = false;
        $this->inquiryProductSearch = '';
        $this->inquiryProductShowAllResults = false;
        $this->inquiryProductSelectedId = null;
        $this->inquiryProductCategory = '';
        $this->inquiryProductQuantity = '1';
        $this->inquiryProductUnitPrice = '0.00';
        $this->editInquiryProductItemId = null;
        $this->editInquiryProductCategory = '';
        $this->editInquiryProductName = '';
        $this->editInquiryProductQuantity = '1';
        $this->editInquiryProductUnitPrice = '';
        $this->editInquiryProductNotes = '';
        $this->showInquiryDocumentPicker = false;
        $this->inquiryExistingDocumentId = null;
        $this->showTaskAttentionModal = false;
        $this->taskAttentionTaskId = null;
        $this->taskAttentionReason = '';
    }

    public function setDetailTab(string $tab): void
    {
        // Workflow and Activity now live inside Overview. Keep stale links safe.
        if (in_array($tab, ['workflow', 'activity'], true)) $tab = 'overview';
        abort_unless(in_array($tab, ['overview', 'rfq', 'comparison'], true), 422);
        $this->detailTab = $tab;
        $this->selectedTaskId = null;
        $this->showRfqSettings = false;
        $this->resetPage('inquiryDocumentsPage');
        $this->resetPage('inquiryActivityPage');
    }

    #[Json]
    public function updateInquiryField(string $field, mixed $value): array
    {
        abort_unless(in_array($field, ['subject', 'owner_id', 'priority', 'requirement_notes'], true), 422);
        $inquiry = $this->selectedInquiry(['owner:id,name,profile_image_path']);
        $saved = app(\App\Actions\Inquiries\UpdateInquiryDetailField::class)->handle($inquiry, $field, $value, auth()->user());

        $result = [
            'ok' => true,
            'value' => match ($field) {
                'owner_id' => $saved->owner_id,
                default => $saved->{$field},
            },
            'display' => match ($field) {
                'owner_id' => $saved->owner?->name ?: 'Unassigned',
                default => (string) $saved->{$field},
            },
        ];

        if ($field === 'priority') {
            $result['color'] = app(\App\Services\MasterDataService::class)->displayColorFor('priority', (string) $saved->priority);
        }

        if ($field === 'owner_id') {
            $result['avatarUrl'] = $saved->owner?->profileImageUrl() ?? '';
        }

        if ($field === 'requirement_notes') {
            $result['displayHtml'] = app(\App\Services\MentionService::class)
                ->render((string) ($saved->requirement_notes ?? ''));
        }

        return $result;
    }

    #[Json]
    public function updateInquiryStartInline(?string $value): array
    {
        $saved = app(\App\Actions\Inquiries\UpdateInquiryStartedAt::class)->handle($this->selectedInquiry(), $value, auth()->user());
        $localized = \App\Support\UserLocalTime::localize($saved->started_at);

        return [
            'ok' => true,
            'value' => $localized?->format('Y-m-d\\TH:i') ?? '',
            'display' => $localized?->format('M j, Y · g:i A') ?? '—',
        ];
    }

    #[Json]
    public function updateInquiryStatus(string $status): array
    {
        $inquiry = $this->selectedInquiry();
        $saved = app(\App\Actions\Inquiries\UpdateInquiryStatus::class)->handle($inquiry, $status, auth()->user());
        return ['ok' => true, 'status' => $saved->status, 'tone' => $this->tone($saved->status)];
    }

    public function openInquiryAttentionReason(): void
    {
        $inquiry = $this->selectedInquiry();
        abort_if($inquiry->result, 422, 'A completed Inquiry cannot be flagged for attention.');

        $this->resetValidation('inquiryAttentionReason');
        $this->inquiryAttentionReason = (string) ($inquiry->attention_reason ?: '');
        $this->showInquiryAttentionModal = true;
    }

    public function closeInquiryAttentionReason(): void
    {
        $this->showInquiryAttentionModal = false;
        $this->inquiryAttentionReason = '';
        $this->resetValidation('inquiryAttentionReason');
    }

    public function saveInquiryAttentionReason(): void
    {
        $this->validate([
            'inquiryAttentionReason' => ['required', 'string', 'max:2000'],
        ], [
            'inquiryAttentionReason.required' => 'Write why this Inquiry needs attention.',
        ]);

        app(\App\Actions\Inquiries\SetInquiryAttention::class)->handle($this->selectedInquiry(), $this->inquiryAttentionReason, auth()->user());
        $this->closeInquiryAttentionReason();
        $this->refreshInquiryListMetrics();
        $this->resetPage('inquiryActivityPage');
        session()->flash('success', 'Attention request saved and added to comments.');
    }

    public function clearInquiryAttention(): void
    {
        app(\App\Actions\Inquiries\ClearInquiryAttention::class)->handle($this->selectedInquiry(), auth()->user());
        $this->closeInquiryAttentionReason();
        $this->refreshInquiryListMetrics();
        $this->resetPage('inquiryActivityPage');
    }

    private function selectedInquiry(array $with = []): Inquiry
    {
        abort_unless($this->selectedInquiryId, 404);
        return app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $this->selectedInquiryId, $with);
    }

    private function tone(string $status): string
    {
        return match (true) {
            str_contains($status, 'Converted'), str_contains($status, 'Completed') => 'green',
            str_contains($status, 'Dead'), str_contains($status, 'Closed') => 'red',
            str_contains($status, 'Ready'), str_contains($status, 'On Hold') => 'amber',
            str_contains($status, 'Waiting') => 'purple',
            default => 'blue',
        };
    }
}
