<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * Shared Livewire receiver for FlowTrack workspace invalidation events.
 *
 * Reverb broadcasts only a lightweight "data changed" signal. Every mounted
 * component re-queries its own authorized data on the following Livewire
 * render, so realtime updates never bypass the existing access-control layer.
 * Components that keep local summary/computed snapshots can optionally define
 * a protected `prepareForWorkspaceRefresh(): void` hook.
 */
trait RefreshesFromWorkspace
{
    #[On('flowtrack-refresh')]
    public function refreshFromWorkspace(): void
    {
        if (method_exists($this, 'prepareForWorkspaceRefresh')) {
            $this->prepareForWorkspaceRefresh();
        }
    }
}
