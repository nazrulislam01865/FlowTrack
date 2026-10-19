<?php

namespace App\Livewire\Jobs\Concerns;

/**
 * Progressive loading boundaries for Order and Task detail screens.
 *
 * The detail component itself must always mount immediately so route context,
 * permissions and primary actions remain reliable. Only expensive lower
 * sections opt in as they approach the viewport.
 */
trait ManagesDetailProgressiveLoading
{
    /** @var array<string,bool> */
    public array $orderDetailSectionsReady = [
        'products' => false,
        'workflow' => false,
        'attachments' => false,
        'activity' => false,
    ];

    /** @var array<string,bool> */
    public array $taskDetailSectionsReady = [
        'checklist' => false,
        'attachments' => false,
        'activity' => false,
    ];

    public function loadDetailSection(string $section, ?string $contextType = null, ?int $contextId = null): void
    {
        // Viewport observers can finish after Livewire has already navigated to
        // another Order/Task. Treat those requests as stale instead of throwing
        // a 422 that can leave the current detail DOM in a partially morphed
        // state. Context-aware loaders also use entity-specific wire keys.
        if ($contextType === 'task') {
            if (! $this->selectedTaskId || (int) $this->selectedTaskId !== (int) $contextId) {
                return;
            }
            if (! array_key_exists($section, $this->taskDetailSectionsReady)) {
                return;
            }

            $this->taskDetailSectionsReady[$section] = true;
            return;
        }

        if ($contextType === 'order') {
            if (
                $this->selectedTaskId
                || ! $this->selectedJobId
                || (int) $this->selectedJobId !== (int) $contextId
                || $this->detailTab !== 'overview'
            ) {
                return;
            }
            if (! array_key_exists($section, $this->orderDetailSectionsReady)) {
                return;
            }

            if ($this->orderDetailSectionsReady[$section]) {
                return;
            }

            $this->orderDetailSectionsReady[$section] = true;
            return;
        }

        // Backward-compatible path for older/non-context progressive loaders.
        if ($this->selectedTaskId) {
            if (! array_key_exists($section, $this->taskDetailSectionsReady)) {
                return;
            }
            $this->taskDetailSectionsReady[$section] = true;
            return;
        }

        if (! $this->selectedJobId || $this->detailTab !== 'overview') {
            return;
        }
        if (! array_key_exists($section, $this->orderDetailSectionsReady)) {
            return;
        }

        if ($this->orderDetailSectionsReady[$section]) {
            return;
        }

        $this->orderDetailSectionsReady[$section] = true;
    }

    protected function resetOrderDetailProgressiveSections(): void
    {
        $this->orderDetailSectionsReady = [
            'products' => false,
            'workflow' => false,
            'attachments' => false,
            'activity' => false,
        ];
    }

    protected function resetTaskDetailProgressiveSections(): void
    {
        $this->taskDetailSectionsReady = [
            'checklist' => false,
            'attachments' => false,
            'activity' => false,
        ];
    }
}
