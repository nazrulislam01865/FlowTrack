<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Http\UploadedFile;

class ProductDocumentService
{
    public function sync(
        MasterRecord $product,
        ?UploadedFile $certificate,
        ?UploadedFile $template,
        bool $removeCertificate = false,
        bool $removeTemplate = false,
    ): MasterRecord {
        abort_unless($product->type === 'product', 404);

        $metadata = (array) ($product->metadata ?? []);
        $changed = false;

        if ($removeCertificate) {
            $this->removeDocument($metadata, 'certificate_test_report', 'certificate_test_report_path', 'certificate_test_report_url');
            $changed = true;
        }

        if ($removeTemplate) {
            $this->removeDocument($metadata, 'template_doc', 'template_doc_path', 'template_doc_url');
            $changed = true;
        }

        if ($certificate) {
            $this->replace($product, $metadata, $certificate, 'certificate_test_report', 'certificate_test_report_path');
            unset($metadata['certificate_test_report_url']);
            $changed = true;
        }

        if ($template) {
            $this->replace($product, $metadata, $template, 'template_doc', 'template_doc_path');
            unset($metadata['template_doc_url']);
            $changed = true;
        }

        if ($changed) $product->update(['metadata' => $metadata]);
        return $product->refresh();
    }

    private function replace(MasterRecord $product, array &$metadata, UploadedFile $file, string $labelKey, string $pathKey): void
    {
        $oldPath = trim((string) ($metadata[$pathKey] ?? ''));
        $folder = 'product-documents/'.$product->workspace_id.'/'.$product->id;
        $stored = app(SecureDocumentStorage::class)->store($file, $folder);
        $path = $stored['path'];

        $metadata[$labelKey] = $file->getClientOriginalName();
        $metadata[$pathKey] = $path;

        if ($oldPath !== '' && $oldPath !== $path) {
            app(SecureDocumentStorage::class)->delete($oldPath);
        }
    }

    private function removeDocument(array &$metadata, string $labelKey, string $pathKey, string $urlKey): void
    {
        $path = trim((string) ($metadata[$pathKey] ?? ''));
        if ($path !== '') app(SecureDocumentStorage::class)->delete($path);
        unset($metadata[$labelKey], $metadata[$pathKey], $metadata[$urlKey]);
    }
}
