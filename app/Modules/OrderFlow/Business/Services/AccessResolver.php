<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;

class AccessResolver
{
    public function __construct(private readonly FlowAuthorizationService $authorization)
    {
    }

    public function canViewJob(User $user, FlowJob $job): bool
    {
        return $this->authorization->allows($user, 'jobs', 'view', $job);
    }

    public function canManageJob(User $user, FlowJob $job): bool
    {
        return $this->authorization->canChangeJobWorkflowStatus($user, $job);
    }

    public function canManageTasks(User $user, FlowJob $job): bool
    {
        return $this->authorization->allows($user, 'tasks', 'edit_all', $job)
            || $this->authorization->allows($user, 'tasks', 'edit_own', $job);
    }

    public function canViewFinancials(User $user, FlowJob $job): bool
    {
        return $this->authorization->canViewSensitiveField($user, 'invoice_amount')
            && $this->authorization->allows($user, 'invoice', 'view', $job);
    }

    public function canDeleteJobPhase(User $user, FlowJob $job): bool
    {
        return $this->authorization->canDeleteJobPhase($user, $job);
    }
}
