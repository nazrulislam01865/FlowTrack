<?php

namespace App\Http\Controllers;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Support\StoredFileResponse;
use Illuminate\Http\Request;

class ProductDocumentController extends Controller
{
    public function __invoke(Request $request, MasterRecord $product, string $kind, string $filename)
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'view'), 403);
        abort_unless($product->workspace_id === app(MasterDataService::class)->workspaceId(), 404);
        abort_unless($product->type === 'product', 404);

        [$pathKey, $labelKey] = match ($kind) {
            'certificate' => ['certificate_test_report_path', 'certificate_test_report'],
            'template' => ['template_doc_path', 'template_doc'],
            default => [null, null],
        };
        abort_unless($pathKey && $labelKey, 404);

        $path = trim((string) data_get($product->metadata, $pathKey));
        $prefix = 'product-documents/'.$product->workspace_id.'/'.$product->id.'/';
        abort_unless($path !== '' && str_starts_with($path, $prefix), 404);
        abort_unless(basename($path) === $filename, 404);

        $originalName = trim((string) data_get($product->metadata, $labelKey));
        $originalName = $originalName !== '' ? basename(str_replace('\\', '/', $originalName)) : $filename;

        return $request->boolean('download')
            ? StoredFileResponse::download($path, $originalName)
            : StoredFileResponse::inline($path, $originalName);
    }
}
