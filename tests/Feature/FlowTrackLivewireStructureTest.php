<?php

namespace Tests\Feature;

use App\Livewire\FlowTrack\Screen;
use App\Livewire\FlowTrack\WorkspaceShell;
use Livewire\Livewire;
use Tests\TestCase;

class FlowTrackLivewireStructureTest extends TestCase
{
    public function test_workspace_shell_mounts_all_canonical_screens(): void
    {
        Livewire::test(WorkspaceShell::class)
            ->assertSet('activePage', 'dashboard')
            ->assertSeeLivewire(Screen::class);
    }

    public function test_every_screen_component_keeps_the_existing_dom_contract(): void
    {
        foreach (WorkspaceShell::SCREENS as $screen) {
            Livewire::test(Screen::class, ['name' => $screen, 'active' => $screen === 'dashboard'])
                ->assertSee('id="page-'.$screen.'"', false)
                ->assertSee('data-livewire-screen="'.$screen.'"', false)
                ->assertSee('wire:ignore', false);
        }
    }
}
