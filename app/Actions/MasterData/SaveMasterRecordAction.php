<?php
namespace App\Actions\MasterData;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
class SaveMasterRecordAction { public function __construct(private readonly MasterDataService $masterData) {} public function execute(string $type, array $data, ?int $id = null): MasterRecord { return $this->masterData->save($type, $data, $id); } }
