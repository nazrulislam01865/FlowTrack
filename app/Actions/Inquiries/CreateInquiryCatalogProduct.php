<?php

namespace App\Actions\Inquiries;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use Illuminate\Http\UploadedFile;

final class CreateInquiryCatalogProduct
{
    /** @return array{product: MasterRecord, image_stored: bool} */
    public function handle(string $code, string $name, MasterRecord $category, ?UploadedFile $image, User $actor): array
    {
        abort_unless($actor->canModule('catalog_products', 'create'), 403);
        abort_unless($actor->canModule('product_categories', 'view'), 403);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $product = $service->save('product', [
            'code' => $code,
            'name' => $name,
            'description' => null,
            'parent_id' => $category->id,
            'status' => 'active',
            'sort_order' => ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->max('sort_order')) + 1,
            'metadata' => null,
        ]);

        $imageStored = true;
        if ($image) {
            try {
                app(ProductImageService::class)->replace($product, $image);
            } catch (\Throwable $exception) {
                report($exception);
                $imageStored = false;
            }
        }

        return ['product' => $product, 'image_stored' => $imageStored];
    }
}
