<?php
namespace App\Actions\MasterData;
use App\Services\MasterDataService;
use App\Services\ProductCategoryDeletionService;
class DeleteProductCategoriesAction
{
    public function __construct(private readonly MasterDataService $masterData, private readonly ProductCategoryDeletionService $deletion) {}
    public function execute(array $selectionKeys): array { return $this->deletion->hardDelete($this->masterData->workspaceId(), $selectionKeys); }
}
