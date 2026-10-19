<?php

namespace App\Policies;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\WorkspaceContext;

final class InquiryPolicy
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly WorkspaceContext $workspace,
    ) {
    }

    public function viewAny(User $user): bool { return $this->access->can($user, 'inquiries', 'view'); }

    public function view(User $user, Inquiry $inquiry): bool
    {
        if (! $this->workspace->contains((int) $inquiry->workspace_id, $user)) return false;
        return $this->access->applyInquiryScope(Inquiry::query()->whereKey($inquiry->id), $user)->exists();
    }

    public function create(User $user): bool { return $this->access->can($user, 'inquiries', 'create'); }

    public function update(User $user, Inquiry $inquiry): bool
    {
        if (! $this->view($user, $inquiry)) return false;
        if ($this->access->isAdministrator($user) || $this->access->isInquiryCreator($user, $inquiry)) return true;
        if (! $this->access->can($user, 'inquiries', 'edit')) return false;
        if ($this->access->canEditAll($user, 'inquiries')) return true;
        return (int) $inquiry->owner_id === (int) $user->id;
    }

    public function delete(User $user, Inquiry $inquiry): bool
    {
        return $this->access->can($user, 'inquiries', 'delete') && $this->view($user, $inquiry);
    }
}
