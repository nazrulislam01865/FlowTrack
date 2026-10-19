<?php
namespace App\Actions\MasterData;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
class ToggleMasterRecordAction { public function __construct(private readonly MasterDataService $masterData) {} public function execute(int $id): MasterRecord { return $this->masterData->toggle($id); } }
