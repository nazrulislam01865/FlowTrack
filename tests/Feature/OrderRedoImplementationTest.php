<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class OrderRedoImplementationTest extends TestCase
{
    public function test_redo_feature_is_wired_into_order_details_without_replacing_existing_order_logic(): void
    {
        $root = dirname(__DIR__, 2);

        $index = file_get_contents($root.'/app/Livewire/Jobs/Index.php');
        $navigation = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderNavigation.php');
        $redo = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderRedo.php');
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $detail = file_get_contents($root.'/resources/views/components/jobs/detail.blade.php');
        $header = file_get_contents($root.'/resources/views/components/jobs/order-detail/header.blade.php');
        $tabs = file_get_contents($root.'/resources/views/components/jobs/order-detail/tabs.blade.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');
        $panel = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-panel.blade.php');

        self::assertStringContainsString('use ManagesOrderRedo;', $index);
        self::assertStringContainsString("'overview','inquiry','finance','redo'", $navigation);
        self::assertStringContainsString('function openRedoModal()', $redo);
        self::assertStringContainsString('function createRedoOrder()', $redo);
        self::assertStringContainsString('function createRedo(', $service);
        self::assertStringContainsString("'original_order_id' => \$root->id", $service);
        self::assertStringContainsString("'redo_order_id' => \$redoOrder->id", $service);
        self::assertStringContainsString('syncWorkflowTasks($redoOrder, $actor, true)', $service);
        self::assertStringContainsString('initializeRedoWorkflowAtPhase($redoOrder, $phases, $restartPhase, $actor)', $service);
        self::assertStringContainsString("'workflow_phase_id' => (int) $restartPhase->id", $service);
        self::assertStringContainsString('synchronizePhase($fresh, $restartPhase, $actor)', $service);
        self::assertStringContainsString('firstIncompleteRequiredTask($fresh, (int) $restartPhase->id)', $service);
        self::assertStringContainsString('$this->openJob($redoOrderId);', $redo);
        self::assertStringContainsString('↻ Initiate Redo', $header);
        self::assertStringContainsString("setDetailTab('redo')", $tabs);
        self::assertStringContainsString('x-jobs.order-detail.redo-panel', $detail);
        self::assertStringContainsString('Record the redo issue', $modal);
        self::assertStringContainsString('Choose the redo scope', $modal);
        self::assertStringContainsString('Set customer and supplier adjustments', $modal);
        self::assertStringContainsString('Review and create the redo order', $modal);
        self::assertStringContainsString('Redo order relationship', $panel);
        self::assertStringContainsString('Redo financial impact', $panel);
        self::assertStringContainsString('Redo audit trail', $panel);
    }

    public function test_selected_redo_scope_is_the_operational_restart_phase(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $redo = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderRedo.php');

        self::assertStringContainsString("\$needle = \$scope === 'artwork' ? 'artwork' : 'production';", $service);
        self::assertStringContainsString("'started_from_phase_id' => (int) $restartPhase->id", $service);
        self::assertStringContainsString("'source_workflow_phase_id' => (int) ($restartPhase->source_workflow_phase_id ?: $restartPhase->id)", $service);
        self::assertStringContainsString('if ($sequence < $restartSequence)', $service);
        self::assertStringContainsString('if ($sequence > $restartSequence)', $service);
        self::assertStringContainsString('$this->openJob($redoOrderId);', $redo);
    }

    public function test_redo_persistence_keeps_original_invoice_and_payment_tables_out_of_write_path(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $migration = file_get_contents($root.'/database/migrations/2026_08_25_161500_create_order_redos_table.php');

        self::assertStringContainsString("Schema::create('order_redos'", $migration);
        self::assertStringNotContainsString('Invoice::', $service);
        self::assertStringNotContainsString('Payment::', $service);
        self::assertStringContainsString("'commercial_value' => \$preview['affectedValue']", $service);
        self::assertStringContainsString("'total_supplier_recovery' => \$preview['recovery']", $service);
    }
    public function test_discount_scope_records_financial_adjustment_without_restarting_workflow(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $redo = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderRedo.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');
        $panel = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-panel.blade.php');
        $migration = file_get_contents($root.'/database/migrations/2026_08_25_170500_make_order_redo_order_nullable_for_discount_scope.php');
        $adjustmentMigration = file_get_contents($root.'/database/migrations/2026_09_05_230000_add_adjustment_units_to_order_redos_table.php');

        self::assertStringContainsString("Rule::in(['artwork', 'production', 'discount'])", $redo);
        self::assertStringContainsString('No redo / customer adjustment', $modal);
        self::assertStringContainsString("if (\$scope === 'discount')", $service);
        self::assertStringContainsString("'redo_order_id' => null", $service);
        self::assertStringContainsString("'customer_resolution' => 'discount'", $service);
        self::assertStringContainsString("'workflow_unchanged' => true", $service);
        self::assertStringContainsString("\$record->scope === 'discount'", $redo);
        self::assertStringContainsString('No workflow restart', $panel);
        self::assertStringContainsString("nullable()->change()", $migration);
        self::assertStringContainsString("customer_adjustment_type", $adjustmentMigration);
        self::assertStringContainsString("supplier_adjustment_type", $adjustmentMigration);
        self::assertStringContainsString("order_value_after_adjustment", $adjustmentMigration);
    }

    public function test_redo_financial_preview_can_resolve_current_product_master_pricing(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');

        self::assertStringContainsString("->with(['catalogProduct:id,type,metadata'])", $service);
        self::assertStringContainsString('productPriceForQuantity($quantity)', $service);
        self::assertStringContainsString("->whereNotIn('status', ['draft', 'cancelled'])", $service);
        self::assertStringContainsString("wire:model.live.debounce.250ms=\"redoCustomerDiscount\"", $modal);
        self::assertStringContainsString("wire:model.live.debounce.250ms=\"redoSupplierChargePercent\"", $modal);
        self::assertStringNotContainsString("<select wire:model.live=\"redoCustomerDiscount\">", $modal);
        self::assertSame(1, substr_count($modal, "wire:model.live.debounce.250ms=\"redoCustomerDiscount\""));
        self::assertStringContainsString('wire:model.live="redoCustomerAdjustmentType"', $modal);
        self::assertStringContainsString('wire:model.live="redoSupplierAdjustmentType"', $modal);
        self::assertStringContainsString('pcs (missing qty)', $modal);
        self::assertStringContainsString('Order total after deduction', $modal);
    }


    public function test_redo_issue_reason_uses_shared_rich_text_images_and_mentions(): void
    {
        $root = dirname(__DIR__, 2);
        $detail = file_get_contents($root.'/resources/views/components/jobs/detail.blade.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');
        $panel = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-panel.blade.php');
        $redo = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderRedo.php');
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');

        self::assertStringContainsString(':mention-users="$mentionUsers"', $detail);
        self::assertStringContainsString('data-rich-text', $modal);
        self::assertStringContainsString('data-mention-users=', $modal);
        self::assertStringContainsString('wire:model="redoIssueDescription"', $modal);
        self::assertStringContainsString('RichTextService::class', $redo);
        self::assertStringContainsString("->normalize(\n                (string) (\$data['issue_description'] ?? ''),", $service);
        self::assertStringContainsString('MentionService::class', $service);
        self::assertStringContainsString('notifyMentionedUsers(', $service);
        self::assertStringContainsString("'mention_user_ids' => \$mentionIds", $service);
        self::assertStringContainsString('<x-ui.mention-text :text="$record->issue_description" />', $panel);
    }

    public function test_redo_issue_editor_is_clickable_latest_artwork_only_and_tab_is_hidden_until_redo_exists(): void
    {
        $root = dirname(__DIR__, 2);
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');
        $tabs = file_get_contents($root.'/resources/views/components/jobs/order-detail/tabs.blade.php');
        $detail = file_get_contents($root.'/resources/views/components/jobs/detail.blade.php');
        $pageData = file_get_contents($root.'/app/Livewire/Jobs/Concerns/BuildsOrderPageData.php');
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');

        self::assertStringContainsString('<div class="ft-redo-field wide ft-mention-host">', $modal);
        self::assertStringNotContainsString('<label class="ft-redo-field wide ft-mention-host">', $modal);
        self::assertStringContainsString('aria-labelledby="redo-issue-description-label"', $modal);
        self::assertStringContainsString('Latest artwork attached to the source Order', $modal);
        self::assertStringContainsString("automationKey(\$task) === 'ART_PREPARE_UPLOAD'", $service);
        self::assertStringContainsString("->where('task_id', \$artworkTask->id)", $service);
        self::assertStringContainsString("->orderByDesc('version')", $service);
        self::assertStringContainsString("@if((bool) (\$redoContext['hasRedo'] ?? false))", $tabs);
        self::assertStringContainsString("\$this->detailTab === 'redo'", $pageData);
        self::assertStringContainsString("if (! (bool) (\$preloadedRedoContext['hasRedo'] ?? false))", $pageData);
        self::assertStringContainsString("@elseif(\$detailTab==='redo' && (bool) (\$orderRedoContext['hasRedo'] ?? false))", $detail);
        self::assertStringNotContainsString('No redo has been created yet', file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-panel.blade.php'));
    }


    public function test_optional_supplier_and_courier_freight_are_saved_and_shown_independently(): void
    {
        $root = dirname(__DIR__, 2);
        $livewire = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderRedo.php');
        $service = file_get_contents($root.'/app/Services/OrderRedoService.php');
        $model = file_get_contents($root.'/app/Models/OrderRedo.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-modal.blade.php');
        $panel = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-panel.blade.php');
        $finance = file_get_contents($root.'/resources/views/components/jobs/order-detail/redo-finance.blade.php');
        $migration = file_get_contents($root.'/database/migrations/2026_10_13_170000_add_courier_freight_amount_to_order_redos_table.php');

        self::assertStringContainsString("default('supplier')", file_get_contents($root.'/database/migrations/2026_10_13_160100_add_freight_bearer_to_order_redos_table.php'));
        self::assertStringContainsString("'courier_freight_amount'", $model);
        self::assertStringContainsString("->where('freight_bearer', 'courier')", $migration);
        self::assertStringContainsString("DB::raw('freight_amount')", $migration);
        self::assertStringContainsString("'redoSupplierFreightAmount' => ['nullable', 'numeric'", $livewire);
        self::assertStringContainsString("'redoCourierFreightAmount' => ['nullable', 'numeric'", $livewire);
        self::assertStringContainsString("'supplier_freight_amount' => (float) (\$this->redoSupplierFreightAmount ?: 0)", $livewire);
        self::assertStringContainsString("'courier_freight_amount' => (float) (\$this->redoCourierFreightAmount ?: 0)", $livewire);
        self::assertStringContainsString("'supplierFreight' => \$supplierFreight", $service);
        self::assertStringContainsString("'recovery' => round(\$supplierCharge + \$supplierFreight, 2)", $service);
        self::assertSame(2, substr_count($service, "'courier_freight_amount' => \$preview['courierRecovery']"));
        self::assertStringContainsString('wire:model.live.debounce.250ms="redoSupplierFreightAmount"', $modal);
        self::assertStringContainsString('wire:model.live.debounce.250ms="redoCourierFreightAmount"', $modal);
        self::assertStringNotContainsString('wire:model.live="redoFreightBearer"', $modal);
        self::assertStringContainsString('Total courier recovery', $modal);
        self::assertStringContainsString('Courier company freight deduction', $panel);
        self::assertStringContainsString('Courier company freight deduction', $finance);
        self::assertStringContainsString('Not applicable', $modal);
    }

    public function test_redo_tab_reuses_standard_order_activity_section_from_prototype(): void
    {
        $root = dirname(__DIR__, 2);
        $detail = file_get_contents($root.'/resources/views/components/jobs/detail.blade.php');
        $pageData = file_get_contents($root.'/app/Livewire/Jobs/Concerns/BuildsOrderPageData.php');

        self::assertStringContainsString(
            '<x-jobs.order-detail.activity',
            $detail,
        );
        self::assertStringContainsString(
            "in_array(\$this->detailTab, ['overview', 'redo'], true)",
            $pageData,
        );
        self::assertStringContainsString(
            ':activity-tab="$activityTab"',
            $detail,
        );
        self::assertStringContainsString(
            ':activity-page="$activityPage"',
            $detail,
        );
    }

}
