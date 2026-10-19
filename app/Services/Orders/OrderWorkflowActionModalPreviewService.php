<?php

namespace App\Services\Orders;

use App\Models\Task;
use App\Services\OrderWorkflowActionService;

/**
 * Builds the expensive email/invoice preview data for the Order workflow modal.
 *
 * The modal Blade must stay presentation-only. The initial modal request uses a
 * metadata-only snapshot so the dialog can render before the nested email Blade
 * is generated; Livewire then loads the exact message body through wire:init.
 */
final class OrderWorkflowActionModalPreviewService
{
    public function __construct(
        private readonly OrderWorkflowActionService $actions,
        private readonly OrderWorkflowEmailService $workflowEmail,
        private readonly OrderInvoiceWorkflowEmailService $invoiceEmail,
    ) {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function snapshot(Task $task, array $payload = [], bool $includeHtml = false): array
    {
        $key = $this->actions->automationKey($task);
        $emailPreview = [];
        $invoicePreview = [];
        $requiresAsyncPreview = false;

        if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $emailPreview = $this->workflowEmail->preview($task, null, $payload, $includeHtml);
            $requiresAsyncPreview = true;
        } elseif ($key === 'BILL_SEND') {
            $invoicePreview = $this->invoiceEmail->preview($task, null, $payload, $includeHtml);
            $requiresAsyncPreview = true;
        }

        return [
            'automation_key' => $key,
            'email_handoff_preview' => $emailPreview,
            'invoice_email_preview' => $invoicePreview,
            'workflow_invoice' => (array) ($invoicePreview['invoice'] ?? []),
            'preview_loaded' => ! $requiresAsyncPreview || $includeHtml,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public function requiresEmailPreview(array $snapshot): bool
    {
        return in_array(
            (string) ($snapshot['automation_key'] ?? ''),
            ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM', 'BILL_SEND'],
            true,
        );
    }
}
