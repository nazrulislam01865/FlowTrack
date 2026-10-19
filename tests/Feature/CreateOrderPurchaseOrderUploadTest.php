<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderPurchaseOrderUploadTest extends TestCase
{
    public function test_create_order_uses_the_combined_documents_prototype_before_workflow(): void
    {
        $createView = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $documentsView = file_get_contents(resource_path('views/components/jobs/create/documents.blade.php'));
        $indexView = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));

        $this->assertStringContainsString('<x-jobs.create.documents', $createView);
        $this->assertStringContainsString('step="6"', $createView);
        $this->assertStringContainsString('number="6" title="What happens next"', $createView);
        $this->assertLessThan(
            strpos($createView, 'title="What happens next"'),
            strpos($createView, '<x-jobs.create.documents'),
            'Documents must appear before What happens next on Create Order.'
        );

        $this->assertStringContainsString('<h2>Documents</h2>', $documentsView);
        $this->assertStringContainsString('<strong>Purchase order</strong>', $documentsView);
        $this->assertStringContainsString('<span class="ft-order-document-optional">Optional</span>', $documentsView);
        $this->assertStringNotContainsString('<span class="ft-order-document-required">Required</span>', $documentsView);
        $this->assertStringContainsString('<strong>Other documents</strong>', $documentsView);
        $this->assertStringContainsString('model="purchaseOrderUpload"', $documentsView);
        $this->assertStringContainsString('model="jobAttachments"', $documentsView);
        $this->assertStringContainsString(':multiple="true"', $documentsView);
        $this->assertStringContainsString('Purchase order attached and ready.', $documentsView);
        $this->assertStringContainsString("saved to the order's Document Archive", $documentsView);

        $this->assertStringContainsString(':purchase-order-upload="$purchaseOrderUpload"', $indexView);
        $this->assertStringContainsString('public $purchaseOrderUpload = null;', $index);
        $this->assertStringContainsString("'purchaseOrderUpload' => AttachmentUpload::nullableRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480)", $creation);
        $this->assertStringContainsString("'jobAttachments.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480)", $creation);
        $this->assertStringContainsString('public function removeCreatePurchaseOrder(): void', $creation);
        $this->assertStringContainsString('public function removeCreateAttachment(int $index): void', $creation);
    }

    public function test_create_order_purchase_order_is_linked_to_po_task_and_completes_normal_workflow_action(): void
    {
        $action = file_get_contents(app_path('Actions/Orders/CreateOrder.php'));

        $this->assertStringContainsString("=== 'NEW_UPLOAD_PO'", $action);
        $this->assertStringContainsString("'task_id' => \$purchaseOrderTask->id", $action);
        $this->assertStringContainsString("'require_task_pack_requirement' => true", $action);
        $this->assertStringContainsString('afterDocumentAdded($purchaseOrderTask->refresh(), $actor)', $action);
        $this->assertStringContainsString('if (! $draft)', $action);
    }

    public function test_other_document_card_excludes_task_specific_documents(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/attachments.blade.php'));

        $this->assertStringContainsString('<h2>Other document', $view);
        $this->assertStringContainsString('filter(fn ($document) => blank($document->task_id))', $view);
        $this->assertStringContainsString('@forelse($otherDocuments as $document)', $view);
        $this->assertStringNotContainsString('@forelse($job->documents->sortByDesc', $view);
    }

    public function test_purchase_order_task_accepts_multiple_files_and_keeps_add_document_action_after_completion(): void
    {
        $workflow = file_get_contents(app_path('Services/OrderWorkflowSetupService.php'));
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $jobUploads = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTaskResources.php'));
        $orderUploads = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/document-modal.blade.php'));
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        $this->assertStringContainsString("self::task('NEW_UPLOAD_PO', 'Upload Purchase Order', 'Order Team', 0, true, 'Purchase Order', true, true", $workflow);
        $this->assertStringContainsString("'NEW_UPLOAD_PO' => \$hasEvidence ? 'Add other documents' : 'Upload Purchase Order'", $actions);
        $this->assertStringContainsString("\$automationKey === 'NEW_UPLOAD_PO'", $modal);
        $this->assertStringContainsString("'hint' => \\App\\Support\\AttachmentUpload::helperText(20).' · Up to 10 files'", $modal);
        $this->assertStringContainsString('@if($inputAllowsMultiple) multiple @endif', $modal);
        $this->assertStringContainsString('Add other documents</button>', $taskRow);

        foreach ([$jobUploads, $orderUploads] as $component) {
            $this->assertStringContainsString("\$isPurchaseOrderUpload = \$automationKey === 'NEW_UPLOAD_PO';", $component);
            $this->assertStringContainsString('$allowsMultiple = $isArtworkUpload || $isPurchaseOrderUpload', $component);
        }

        $this->assertStringContainsString("if (\$task->completed_at || strcasecmp(trim((string) \$task->status), 'Completed') === 0) return;", $actions);
    }
}
