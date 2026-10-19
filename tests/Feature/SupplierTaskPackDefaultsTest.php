<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierDefaultTaskAssigner;
use App\Services\SupplierBridge\SupplierWorkflowProjection;
use Tests\TestCase;

class SupplierTaskPackDefaultsTest extends TestCase
{
    public function test_order_level_supplier_is_only_selected_when_unambiguous(): void
    {
        $this->assertNull(SupplierDefaultTaskAssigner::uniqueSupplierId([]));
        $this->assertSame(12, SupplierDefaultTaskAssigner::uniqueSupplierId([12]));
        $this->assertSame(12, SupplierDefaultTaskAssigner::uniqueSupplierId([12, 12]));
        $this->assertNull(SupplierDefaultTaskAssigner::uniqueSupplierId([12, 14]));
        $this->assertNull(SupplierDefaultTaskAssigner::uniqueSupplierId([0]));
    }

    public function test_supplier_default_can_retain_an_optional_internal_backup(): void
    {
        $form = file_get_contents(resource_path('views/livewire/task-pack-setup/form.blade.php'));
        $service = file_get_contents(app_path('Services/TaskPackService.php'));
        $component = file_get_contents(app_path('Livewire/TaskPackSetup/Form.php'));

        $this->assertStringContainsString('Internal backup assignee (optional)', $form);
        $this->assertStringContainsString("'default_assignee_id' => \$row['default_assignee_id'] ?? null", $service);
        $this->assertStringContainsString("'default_assignee_id' => \$data['default_assignee_id'] ?? null", $service);
        $this->assertStringNotContainsString("default_assignee_type'] ?? 'user') === 'supplier' ? null", $service);
        $this->assertStringNotContainsString("abort_unless((\$this->tasks[\$index]['default_assignee_type'] ?? 'user') === 'user'", $component);
    }

    public function test_supplier_default_does_not_treat_the_setup_backup_as_manual_override(): void
    {
        $assigner = file_get_contents(app_path('Services/SupplierBridge/SupplierDefaultTaskAssigner.php'));

        $this->assertStringContainsString('(int) $task->assignee_id === (int) $task->setup_assignee_id', $assigner);
        $this->assertStringContainsString('(int) $task->assignee_id !== (int) $task->setup_assignee_id', $assigner);
        $this->assertStringContainsString('->delegates($order, $actor, $backupIds)', $assigner);
        $this->assertStringContainsString('$access->canEditTask($candidate, $task)', $assigner);
    }

    public function test_billing_and_payment_can_never_be_supplier_tasks(): void
    {
        $this->assertNull(SupplierWorkflowProjection::actionKey('BILL_PREPARE', 6));
        $this->assertNull(SupplierWorkflowProjection::actionKey('PAY_PROCESS', 7));
        $this->assertSame('QC_CHECK', SupplierWorkflowProjection::actionKey('QC_CHECK', 4));
    }
}
