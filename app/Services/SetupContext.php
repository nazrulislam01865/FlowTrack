<?php

namespace App\Services;

/**
 * Backwards-compatible setup context. Phase 9 makes WorkspaceContext the
 * request-scoped source of truth while preserving all existing call sites.
 */
class SetupContext
{
    public function __construct(private readonly WorkspaceContext $workspace)
    {
    }

    public function workspaceId(): int
    {
        return $this->workspace->id(auth()->user());
    }
}
