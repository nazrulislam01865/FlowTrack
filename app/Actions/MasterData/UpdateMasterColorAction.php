<?php
namespace App\Actions\MasterData;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
class UpdateMasterColorAction { public function __construct(private readonly MasterDataService $masterData) {} public function execute(int $id, string $color): MasterRecord { return $this->masterData->setColor($id, $color); } }
