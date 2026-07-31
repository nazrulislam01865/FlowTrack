<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowActivity;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public function log(int $workspaceId, ?User $user, Model $subject, string $event, string $description, array $properties = []): void
    {
        $flowJobId = match (true) {
            $subject instanceof FlowJob => (int) $subject->getKey(),
            $subject instanceof FlowTask, $subject instanceof FlowDocument => $subject->flow_job_id ? (int) $subject->flow_job_id : null,
            default => $subject->getAttribute('flow_job_id') ? (int) $subject->getAttribute('flow_job_id') : null,
        };

        FlowActivity::create([
            'workspace_id' => $workspaceId,
            'flow_job_id' => $flowJobId,
            'user_id' => $user?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
