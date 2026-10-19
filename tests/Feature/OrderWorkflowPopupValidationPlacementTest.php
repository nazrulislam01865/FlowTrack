<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowPopupValidationPlacementTest extends TestCase
{
    public function test_order_detail_popup_errors_are_rendered_beneath_their_exact_fields(): void
    {
        $workflowModal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $documentModal = file_get_contents(resource_path('views/components/jobs/order-detail/document-modal.blade.php'));
        $shippingModal = file_get_contents(resource_path('views/components/jobs/order-detail/shipping.blade.php'));
        $css = $this->orderDetailCss();

        $this->assertStringContainsString(
            'wire:model="orderWorkflowActionPayload.packages" placeholder="e.g. 24 cartons">@error(\'orderWorkflowActionPayload.packages\')',
            $workflowModal,
        );
        $this->assertStringContainsString(
            'wire:model="orderWorkflowActionPayload.weight" placeholder="e.g. 312 kg">@error(\'orderWorkflowActionPayload.weight\')',
            $workflowModal,
        );
        $this->assertStringContainsString(
            'wire:model="orderWorkflowActionPayload.tracking_number">@error(\'orderWorkflowActionPayload.tracking_number\')',
            $workflowModal,
        );
        $this->assertStringContainsString(
            'wire:model="orderWorkflowActionPayload.invoice_amount">@error(\'orderWorkflowActionPayload.invoice_amount\')',
            $workflowModal,
        );
        $this->assertStringContainsString(
            'wire:model="orderWorkflowActionPayload.payment_reference">@error(\'orderWorkflowActionPayload.payment_reference\')',
            $workflowModal,
        );

        $this->assertStringNotContainsString(
            "@foreach(['recipient','address','packages','weight'] as \$field)",
            $workflowModal,
        );
        $this->assertStringContainsString('wire:model="overviewTaskExistingDocumentId"', $documentModal);
        $this->assertStringContainsString(
            '@error(\'overviewTaskExistingDocumentId\')<p class="validation-error">',
            $documentModal,
        );
        $this->assertStringContainsString('x-show="errors.address"', $shippingModal);
        $this->assertStringContainsString('x-show="errors.phone"', $shippingModal);
        $this->assertStringContainsString('x-show="errors.postal"', $shippingModal);

        $this->assertStringContainsString('.ft-prototype-field>.validation-error', $css);
        $this->assertStringContainsString('align-items:start;', $css);
        $this->assertStringContainsString('align-self:start;', $css);
        $this->assertStringContainsString('align-content:start;', $css);
        $this->assertStringContainsString('.ft-order-popup-field>.validation-error', $css);
        $this->assertStringContainsString('.field:has(>.validation-error)>input', $css);

        // Billing and Payment errors must occupy a pre-reserved row rather
        // than increasing the modal dimensions after validation.
        $this->assertStringContainsString('ft-order-workflow-action-modal--stable-finance-validation', $workflowModal);
        $this->assertStringContainsString("in_array(\$variant, ['invoice_prepare', 'payment'], true)", $workflowModal);
        $this->assertStringContainsString('grid-template-rows:auto auto 13px;', $css);
        $this->assertStringContainsString('scrollbar-gutter:stable;', $css);
    }

    public function test_workflow_payload_validation_returns_all_missing_field_errors_together(): void
    {
        $service = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $inlineEdits = file_get_contents(app_path('Livewire/Concerns/HandlesInlineEdits.php'));
        $jobService = $this->jobServiceSource();

        $this->assertStringContainsString('private function requiredPayloadErrors', $service);
        $this->assertStringContainsString('private function throwPayloadErrors', $service);
        $this->assertStringContainsString("'packages' => 'Package count'", $service);
        $this->assertStringContainsString("'weight' => 'Weight'", $service);
        $this->assertStringContainsString("'payment_reference' => 'Payment reference'", $service);
        $this->assertStringContainsString("'orderWorkflowActionPayload.qty_received'", $service);
        $this->assertStringContainsString("'orderWorkflowActionPayload.qty_inspected'", $service);
        $this->assertStringContainsString("'errors' => \$exception->errors()", $inlineEdits);
        $this->assertStringContainsString("'shipping_phone_country_code' => 'Choose an active phone country code from Master Data.'", $jobService);
    }
}
