<?php
namespace App\Actions\Setup;
use App\Services\TaskPackService;
class MoveTaskPackItemAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(int $id, int $direction): void { $this->taskPacks->moveItem($id, $direction); } }
