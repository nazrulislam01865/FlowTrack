<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\AccessControlService;

final class DocumentPolicy
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function viewAny(User $user): bool { return $this->access->can($user, 'documents', 'view'); }
    public function view(User $user, Document $document): bool { return $this->access->applyDocumentScope(Document::query()->whereKey($document->id), $user)->exists(); }
    public function create(User $user): bool { return $this->access->can($user, 'documents', 'create'); }
    public function delete(User $user, Document $document): bool { return $this->access->can($user, 'documents', 'delete') && $this->view($user, $document); }
}
