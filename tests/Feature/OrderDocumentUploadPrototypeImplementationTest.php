<?php

namespace Tests\Feature;

use Tests\Support\OrderPhase5Source;
use Tests\TestCase;

class OrderDocumentUploadPrototypeImplementationTest extends TestCase
{
    public function test_documents_tab_uses_single_file_prototype_uploader_states(): void
    {
        $view = OrderPhase5Source::detailDocumentsView();

        $this->assertStringContainsString('Choose the document type, then upload a file, select an existing file, or use the task Add link action.', $view);
        $this->assertStringContainsString('$wire.upload(', $view);
        $this->assertStringContainsString("'jobRequiredDocumentUpload'", $view);
        $this->assertStringContainsString('event?.detail?.progress', $view);
        $this->assertStringContainsString('persistJobRequiredDocumentUpload', $view);
        $this->assertStringContainsString('Retry upload', $view);
        $this->assertStringContainsString('Choose another file', $view);
        $this->assertStringContainsString('Upload complete', $view);
        $this->assertStringContainsString("'eps', 'esp'", $view);
        $this->assertStringContainsString('to replace this document.', $view);
        $this->assertStringNotContainsString('Upload &amp; link', $view);
    }

    public function test_documents_tab_auto_links_and_replaces_only_after_new_file_is_stored(): void
    {
        $component = OrderPhase5Source::livewire();

        $this->assertStringContainsString('public function updatedJobRequiredDocumentUpload(): void', $component);
        $this->assertStringContainsString("'jobRequiredDocumentUpload' => AttachmentUpload::requiredRules(AttachmentUpload::ORDER_REQUIRED, 20480)", $component);
        $this->assertStringContainsString("'require_task_pack_requirement' => true", $component);

        $storePosition = strpos($component, '$document = app(DocumentService::class)->store');
        $deletePosition = strpos($component, 'app(DocumentService::class)->delete($replace');
        $this->assertNotFalse($storePosition);
        $this->assertNotFalse($deletePosition);
        $this->assertLessThan($deletePosition, $storePosition);
    }

    public function test_order_workflow_upload_modal_supports_multi_file_artwork_without_resizing(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/document-modal.blade.php'));
        $css = $this->orderDetailCss();

        $this->assertStringContainsString("ft-order-task-document-modal ft-order-attachment-upload-modal {{ \$prototypeUpload ? 'ft-order-prototype-upload-modal' : '' }}", $view);
        $this->assertStringContainsString('ft-prototype-selected-file-name', $view);
        $this->assertStringContainsString('ft-order-attachment-selected-file', $view);
        $this->assertStringContainsString("\$chunkedArtworkUpload = in_array(\$automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true)", $view);
        $this->assertStringContainsString('@if($inputAllowsMultiple) multiple @endif', $view);
        $this->assertStringContainsString('$selectedUploadCount', $view);
        $this->assertStringContainsString('removeOverviewTaskDocumentUpload({{ $index }})', $view);
        $this->assertStringContainsString('One artwork version', $view);
        $this->assertStringContainsString('Uploaded · Ready to save', $view);
        $this->assertStringContainsString("'Choose files' : 'Choose file'", $view);
        $this->assertMatchesRegularExpression('/height:\s*500px;/', $css);
        $this->assertMatchesRegularExpression('/grid-template-rows:\s*auto minmax\(0,\s*1fr\) auto;/', $css);
        $this->assertMatchesRegularExpression('/overflow-y:\s*auto;/', $css);
        $this->assertMatchesRegularExpression('/text-overflow:\s*ellipsis;/', $css);
        $this->assertMatchesRegularExpression('/white-space:\s*nowrap;/', $css);
    }
}
