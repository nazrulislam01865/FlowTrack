<?php

namespace App\Actions\Orders;

use App\DTOs\Orders\OrderCreateData;
use App\Models\FlowJob;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\OrderWorkflowActionService;
use Illuminate\Http\UploadedFile;

/** Create an Order from already validated Create Order form data. */
final class CreateOrder
{
    public function __construct(
        private readonly OrderLifecycleService $jobs,
        private readonly DocumentService $documents,
        private readonly OrderWorkflowActionService $workflowActions,
    ) {
    }

    public function handle(array $data, ?UploadedFile $purchaseOrderUpload, array $attachments, bool $draft, User $actor): FlowJob
    {
        $payload = OrderCreateData::fromLivewire($data, $draft);

        $job = $this->jobs->create($payload->toArray(), $actor);

        if ($purchaseOrderUpload) {
            $purchaseOrderTask = $job->tasks()
                ->with(['job', 'documentCategory', 'setupTemplate.documentCategory'])
                ->get()
                ->first(fn ($task) => $this->workflowActions->automationKey($task) === 'NEW_UPLOAD_PO');

            abort_unless($purchaseOrderTask, 422, 'The selected Order workflow does not contain the Upload Purchase Order task.');

            $this->documents->store($purchaseOrderUpload, [
                'flow_job_id' => $job->id,
                'client_id' => $job->client_id,
                'task_id' => $purchaseOrderTask->id,
                'require_task_pack_requirement' => true,
            ], $actor);

            // Active Orders treat the Create Order PO exactly like using the
            // task-level Upload Purchase Order action: the task completes and
            // normal sequence logic unlocks the next task. Drafts keep their
            // workflow inactive while retaining the PO against the correct task.
            if (! $draft) {
                $this->workflowActions->afterDocumentAdded($purchaseOrderTask->refresh(), $actor);
            }
        }

        foreach ($attachments as $upload) {
            $this->documents->store($upload, [
                'flow_job_id' => $job->id,
                'client_id' => $job->client_id,
                'category' => 'Order attachment',
            ], $actor);
        }

        return $job;
    }
}
