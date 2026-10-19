<?php
namespace App\Actions\Setup;
use App\Services\TaskPackService;
class DeleteTaskPackItemAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(int $id): void { $this->taskPacks->deleteItem($id); } }
