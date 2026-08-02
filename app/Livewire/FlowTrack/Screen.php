<?php

namespace App\Livewire\FlowTrack;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Screen extends Component
{
    #[Locked]
    public string $name;

    #[Locked]
    public bool $active = false;

    public function mount(string $name, bool $active = false): void
    {
        abort_unless(in_array($name, WorkspaceShell::SCREENS, true), 404);

        $this->name = $name;
        $this->active = $active;
    }

    public function render(): View
    {
        return view('livewire.flow-track.screen');
    }
}
