<?php
namespace App\Actions\Setup;
use App\Models\TaskPack;
use App\Services\TaskPackService;
class SaveTaskPackWithItemsAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(array $pack, array $items, ?int $id = null): TaskPack { return $this->taskPacks->savePackWithItems($pack, $items, $id); } }
