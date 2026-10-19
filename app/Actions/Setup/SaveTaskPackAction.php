<?php
namespace App\Actions\Setup;
use App\Models\TaskPack;
use App\Services\TaskPackService;
class SaveTaskPackAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(array $data, ?int $id = null): TaskPack { return $this->taskPacks->savePack($data, $id); } }
