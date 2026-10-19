<?php

namespace App\Actions\Inquiries;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;

final class CreateInquiryProductCategory
{
    public function handle(string $name, User $actor): MasterRecord
    {
        abort_unless($actor->canModule('catalog_products', 'create'), 403);
        abort_unless($actor->canModule('product_categories', 'view'), 403);
        abort_unless($actor->canModule('product_categories', 'create'), 403);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();

        return $service->save('product_category', [
            'code' => $service->nextCode('product_category'),
            'name' => $name,
            'description' => null,
            'parent_id' => null,
            'status' => 'active',
            'sort_order' => ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->max('sort_order')) + 1,
            'metadata' => null,
        ]);
    }
}
