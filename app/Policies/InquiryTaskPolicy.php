<?php

namespace App\Policies;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\AccessControlService;

final class InquiryTaskPolicy
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function view(User $user, InquiryTask $task): bool { return $this->access->applyInquiryTaskScope(InquiryTask::query()->whereKey($task->id), $user)->exists(); }
    public function update(User $user, InquiryTask $task): bool { return $this->access->canEditInquiryTask($user, $task); }
    public function assign(User $user, InquiryTask $task): bool { return $this->access->canAssignInquiryTask($user, $task); }
    public function delete(User $user, InquiryTask $task): bool { return $this->access->canDeleteInquiryTask($user, $task); }
}
