<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageService
{
    public function replace(MasterRecord $product, UploadedFile $image): MasterRecord
    {
        abort_unless($product->type === 'product', 404);

        $disk = Storage::disk('public');
        $metadata = (array) ($product->metadata ?? []);
        $oldPath = trim((string) ($metadata['product_image_path'] ?? ''));
        $path = $image->storePublicly('product-images/'.$product->workspace_id.'/'.$product->id, 'public');
        $metadata['product_image_path'] = $path;

        try {
            $product->update(['metadata' => $metadata]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        if ($oldPath !== '' && $oldPath !== $path) {
            $disk->delete($oldPath);
        }

        return $product->refresh();
    }

    public function remove(MasterRecord $product): MasterRecord
    {
        abort_unless($product->type === 'product', 404);

        $metadata = (array) ($product->metadata ?? []);
        $oldPath = trim((string) ($metadata['product_image_path'] ?? ''));
        unset($metadata['product_image_path']);
        $product->update(['metadata' => $metadata ?: null]);

        if ($oldPath !== '') {
            Storage::disk('public')->delete($oldPath);
        }

        return $product->refresh();
    }
}
