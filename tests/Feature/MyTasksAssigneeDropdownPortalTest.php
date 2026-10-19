<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyTasksAssigneeDropdownPortalTest extends TestCase
{
    public function test_my_tasks_assignee_picker_uses_the_shared_fixed_menu_portal(): void
    {
        $groupView = file_get_contents(resource_path('views/livewire/my-work/_order-groups-v5.blade.php'));
        $picker = file_get_contents(resource_path('views/components/ui/inline-remote-user.blade.php'));

        $this->assertStringContainsString('<x-ui.inline-remote-user', $groupView);
        $this->assertStringContainsString("'fixedMenu' => true", $picker);
        $this->assertStringContainsString('<template x-teleport="body">', $picker);
        // Do not spread the searchSelect object. Object spread evaluates its
        // `visibleItems` getter once and freezes the initial empty array, which
        // makes asynchronously loaded users invisible in every shared picker.
        $this->assertStringContainsString('const picker = window.FlowTrack.ui.searchSelect({', $picker);
        $this->assertStringContainsString('return picker;', $picker);
        $this->assertStringNotContainsString('...window.FlowTrack.ui.searchSelect({', $picker);
        // Selections happen inside the teleported <body> menu. They must be
        // re-dispatched from the picker's original DOM root so the owning inline
        // editor receives the event and actually invokes its Livewire save action.
        $this->assertStringContainsString('const origin = document.getElementById(@js($pickerId));', $picker);
        $this->assertStringContainsString("origin.dispatchEvent(new CustomEvent('ft-inline-remote-selected'", $picker);
        $this->assertStringContainsString("x-on:click.stop=\"select(item); emitSelection(", $picker);
        $this->assertStringNotContainsString("select(item); \$dispatch('ft-inline-remote-selected'", $picker);
        $this->assertStringContainsString('data-ft-inline-remote-menu', $picker);
        $this->assertStringContainsString("open ? menuStyle + ';display:flex!important;' : 'display:none!important;'", $picker);
    }
}
