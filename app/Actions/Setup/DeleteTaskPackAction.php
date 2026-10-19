<?php
namespace App\Actions\Setup;
use App\Services\TaskPackService;
class DeleteTaskPackAction { public function __construct(private readonly TaskPackService $taskPacks) {} public function execute(int $id): array { return $this->taskPacks->deletePack($id); } }
