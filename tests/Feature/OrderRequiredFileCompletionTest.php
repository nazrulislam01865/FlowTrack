<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderRequiredFileCompletionTest extends TestCase
{
    public function test_required_order_task_accepts_file_or_external_link_as_submission_evidence(): void
    {
        $service = file_get_contents(app_path('Services/TaskService.php'));
        $presenter = file_get_contents(app_path('Support/JobDetailPresenter.php'));
        $jobService = $this->jobServiceSource();
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        $this->assertStringContainsString('$task->documents()->exists() || $task->links()->exists()', $service);
        $this->assertStringContainsString('return $link->refresh();', $service);
        $this->assertStringNotContainsString('$hasMatchingDocument', $service);

        $this->assertStringContainsString('$documentCount = self::documentsForTask($job, $task)->count();', $presenter);
        $this->assertStringContainsString('$linkCount = self::taskLinks($job, $task)->count();', $presenter);
        $this->assertStringContainsString('$received = $documentCount + $linkCount;', $presenter);
        $this->assertStringContainsString("'link_count' => \$linkCount", $presenter);
        $this->assertStringNotContainsString('strcasecmp(trim((string) $document->category)', $presenter);

        $this->assertStringContainsString('private function hydrateLoadedTaskLinks(FlowJob $job): void', $jobService);
        $this->assertStringContainsString("->whereIn('task_id', \$taskIds->all())", $jobService);
        $this->assertStringContainsString("'links',", $jobService);
        $this->assertStringContainsString('JobDetailPresenter::taskLinks($job, $task)', $taskRow);
        $this->assertStringContainsString('$taskDocuments->count()', $taskRow);
        $this->assertStringContainsString('$taskLinks->count()', $taskRow);
    }
}
