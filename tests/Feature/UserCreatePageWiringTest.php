<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserCreatePageWiringTest extends TestCase
{
    public function test_add_user_uses_full_page_user_editor_in_create_mode(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $administration = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));
        $createPage = file_get_contents(resource_path('views/pages/user-create.blade.php'));
        $editorComponent = file_get_contents(app_path('Livewire/UserEditor/Index.php'));
        $editorView = file_get_contents(resource_path('views/livewire/user-editor/index.blade.php'));

        $this->assertStringContainsString("Route::get('/users/create', UserCreateController::class)", $routes);
        $this->assertStringContainsString("route('users.create')", $administration);
        $this->assertStringNotContainsString('@if($showUserModal)', $administration);
        $this->assertStringContainsString('<livewire:user-editor.index :create-mode="true" />', $createPage);
        $this->assertStringContainsString('public bool $createMode = false;', $editorComponent);
        $this->assertStringContainsString('$target = $service->create($payload, $actor);', $editorComponent);
        $this->assertStringContainsString("$createMode ? 'Create user' : 'Edit user'", $editorView);
        $this->assertStringContainsString("$createMode ? 'Create user' : 'Save changes'", $editorView);
    }
}
