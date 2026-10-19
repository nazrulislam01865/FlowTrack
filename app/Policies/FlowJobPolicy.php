<?php

namespace App\Policies;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\AccessControlService;

final class FlowJobPolicy
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function viewAny(User $user): bool { return $this->access->can($user, 'jobs', 'view'); }
    public function view(User $user, FlowJob $job): bool { return $this->access->applyJobScope(FlowJob::query()->whereKey($job->id), $user)->exists(); }
    public function create(User $user): bool { return $this->access->can($user, 'jobs', 'create'); }
    public function update(User $user, FlowJob $job): bool { return $this->access->canEditJob($user, $job); }
    public function assign(User $user, FlowJob $job): bool { return $this->access->canAssignJob($user, $job); }
    public function changeStatus(User $user, FlowJob $job): bool { return $this->access->canChangeJobStatus($user, $job); }
    public function delete(User $user, FlowJob $job): bool
    {
        return $this->access->can($user, 'jobs', 'delete') && $this->view($user, $job);
    }
}
