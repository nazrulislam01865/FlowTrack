<?php
namespace App\Actions\Setup;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Services\TaskPackService;
class SaveTaskPackItemAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(TaskPack $pack, array $data, ?int $id = null): TaskPackItem { return $this->taskPacks->saveItem($pack, $data, $id); } }
