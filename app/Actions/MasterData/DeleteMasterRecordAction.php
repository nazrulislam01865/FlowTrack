<?php
namespace App\Actions\MasterData;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
class DeleteMasterRecordAction
{
    public function __construct(private readonly MasterDataService $masterData) {}
    public function execute(int $id): array
    {
        $record = MasterRecord::query()->forWorkspace($this->masterData->workspaceId())->findOrFail($id);
        $snapshot = ['id' => (int) $record->id, 'type' => (string) $record->type, 'name' => (string) $record->name, 'code' => (string) $record->code];
        $this->masterData->delete($id);
        return $snapshot;
    }
}
