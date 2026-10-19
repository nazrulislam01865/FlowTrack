<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierPortalAccessUxTest extends TestCase
{
    public function test_edit_screen_has_prototype_actions_and_optional_password_confirmation(): void
    {
        $view = file_get_contents(resource_path('views/livewire/master-data/sections/supplier-edit.blade.php'));
        $logic = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierDetails.php'));
        $bridge = file_get_contents(app_path('Services/SupplierBridge/SyncSupplier.php'));

        $this->assertStringContainsString('Account linked', $view);
        $this->assertStringContainsString('Send password reset link', $view);
        $this->assertStringContainsString('portalPasswordConfirmation', $view);
        $this->assertStringContainsString('portalRequirePasswordChange', $view);
        $this->assertStringContainsString('Save portal access', $view);
        $this->assertStringContainsString('Sync supplier &amp; assigned products', $view);
        $this->assertStringContainsString("$"."linked ? 'nullable' : 'required'", $logic);
        $this->assertStringContainsString('sendSupplierPortalPasswordReset', $logic);
        $this->assertStringContainsString('Password::min(8)', $logic);
        $this->assertStringContainsString('Use at least 8 characters.', $view);
        $this->assertStringContainsString('if ($password !== null && $password !==', $bridge);
        $this->assertStringContainsString('if ($syncCatalog && ! $this->sync($supplierId))', $bridge);
    }
}
