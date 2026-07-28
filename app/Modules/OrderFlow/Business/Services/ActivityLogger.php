<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowActivity;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public function log(int $workspaceId, ?User $user, Model $subject, string $event, string $description, array $properties = []): void
    {
        FlowActivity::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
