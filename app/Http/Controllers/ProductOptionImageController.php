<?php

namespace App\Http\Controllers;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use Illuminate\Support\Facades\Storage;

class ProductOptionImageController extends Controller
{
    public function __invoke(MasterRecord $product, string $optionKey, string $filename)
    {
        $user = auth()->user();
        abort_unless($user?->canModule('catalog_products', 'view'), 403);
        abort_unless($product->workspace_id === app(MasterDataService::class)->workspaceId(), 404);
        abort_unless($product->type === 'product', 404);

        $option = collect((array) data_get($product->metadata, 'product_options', []))
            ->first(fn ($row) => trim((string) data_get($row, 'key')) === $optionKey);
        abort_unless(is_array($option), 404);

        $path = trim((string) data_get($option, 'image_path'));
        $expectedPrefix = 'product-option-images/'.$product->workspace_id.'/'.$product->id.'/'.$optionKey.'/';
        abort_unless($path !== '' && str_starts_with($path, $expectedPrefix), 404);
        abort_unless(basename($path) === $filename, 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
