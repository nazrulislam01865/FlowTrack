<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserAssignmentPresentationRegressionTest extends TestCase
{
    public function test_user_assignment_table_and_editor_keep_the_refined_multi_role_department_and_password_ui(): void
    {
        $administration = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));
        $editor = file_get_contents(resource_path('views/livewire/user-editor/index.blade.php'));

        $this->assertStringContainsString("\$assignedRoleNames->join(', ')", $administration);
        $this->assertStringNotContainsString('ft-user-role-chips\">@forelse', $administration);

        $this->assertStringContainsString('ft-user-editor-department-picker', $editor);
        $this->assertStringContainsString('<x-ui.search-select', $editor);
        $this->assertStringContainsString('label="Department"', $editor);
        $this->assertStringContainsString('property="departmentId"', $editor);
        $this->assertStringContainsString('required', $editor);
        $this->assertStringContainsString('type="departments"', $editor);
        $this->assertStringContainsString('placeholder="No department"', $editor);
        $this->assertStringNotContainsString('id="ft-edit-department" wire:model="departmentId"', $editor);

        $this->assertStringContainsString("password: ''", $editor);
        $this->assertStringContainsString('syncPassword(value)', $editor);
        $this->assertStringContainsString('syncConfirmation(value)', $editor);
        $this->assertStringNotContainsString('Passwords do not match.', $editor);
        $this->assertStringNotContainsString("\$wire.entangle('newPassword')", $editor);
        $this->assertStringContainsString('data-1p-ignore', $editor);
    }
}
