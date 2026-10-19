<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderShipmentEditingUxImplementationTest extends TestCase
{
    public function test_shipment_plan_uses_shipment_wise_editing_without_a_global_edit_mode(): void
    {
        $plan = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/plan-table.blade.php'));

        $this->assertStringContainsString('Use Edit for the delivery address.', $plan);
        $this->assertStringContainsString('openEditShipment', $plan);
        $this->assertStringContainsString('openAddShipment', $plan);
        $this->assertStringContainsString('No changes — continue', $plan);
        $this->assertStringNotContainsString('shipmentPlanEditing', $plan);
        $this->assertStringNotContainsString('Edit shipment details', $plan);
        $this->assertStringNotContainsString('Done editing', $plan);
    }

    public function test_shipment_plan_groups_delivery_details_to_prevent_table_breakage(): void
    {
        $plan = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/plan-table.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/shipment-row-layout.css'));

        $this->assertStringContainsString('<th>Delivery details</th>', $plan);
        $this->assertStringContainsString('ft-ms-delivery__meta', $plan);
        $this->assertStringContainsString('th:nth-child(2) { width: 43%; }', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $css);
    }

    public function test_tracking_opens_missing_fields_when_active_and_uses_inline_pencil_edits_after_save(): void
    {
        $tracking = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/tracking-table.blade.php'));
        $presenter = file_get_contents(app_path('Support/OrderShipmentPresenter.php'));

        $this->assertStringContainsString('$autoOpen = $editable && $row[\'mode\'] === \'active\'', $tracking);
        $this->assertStringContainsString('courierEditing: @js($autoOpen)', $tracking);
        $this->assertStringContainsString('trackingEditing: @js($autoOpen)', $tracking);
        $this->assertStringContainsString('ft-ms-cell-edit-button', $tracking);
        $this->assertStringContainsString('persist()', $tracking);
        $this->assertStringContainsString('initialEntry: @js($initialEntry)', $tracking);
        $this->assertStringContainsString('if (!initialEntry) persist()', $tracking);
        $this->assertStringContainsString('x-on:click="persist()"', $tracking);
        $this->assertStringContainsString("saving ? 'Saving...' : 'Save'", $tracking);
        $this->assertStringContainsString('<th>Courier</th>', $tracking);
        $this->assertStringContainsString('courier-select', $tracking);
        $this->assertStringContainsString('<th>Actions</th>', $tracking);
        $this->assertStringNotContainsString('Add courier & tracking</span>', $tracking);
        $this->assertStringNotContainsString('Print label', $tracking);
        $this->assertStringNotContainsString('printOrderShipmentLabel', $tracking);
        $this->assertStringContainsString("'Add courier & tracking number'", $presenter);
    }

    public function test_shipping_method_picker_uses_a_fixed_teleported_menu(): void
    {
        $picker = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/method-picker.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/shipment-floating-overlays.css'));

        $this->assertStringContainsString('floatingActionMenu()', $picker);
        $this->assertStringContainsString('x-teleport="body"', $picker);
        $this->assertStringContainsString('ft-ms-method-portal', $picker);
        $this->assertStringContainsString('max-height: min(360px, calc(100vh - 20px));', $css);
        $this->assertStringContainsString('overflow-y: auto;', $css);
    }

    public function test_shipment_stage_modal_matches_create_order_compact_address_fields(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/add-modal.blade.php'));
        $manager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderShipments.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $presenter = file_get_contents(app_path('Support/OrderShipmentPresenter.php'));

        $this->assertStringContainsString('ft-form-standard ft-form-standard--order', $modal);
        $this->assertStringContainsString('property="shipmentForm.phone_country_code"', $modal);
        $this->assertStringContainsString('search-placeholder="Search code or country…"', $modal);
        $this->assertStringContainsString('wire:model.blur="shipmentForm.recipient"', $modal);
        $this->assertStringContainsString('wire:model.blur="shipmentForm.phone"', $modal);
        $this->assertStringContainsString('wire:model.blur="shipmentForm.address"', $modal);
        $this->assertStringContainsString('wire:model.blur="shipmentForm.postal_code"', $modal);
        $this->assertStringContainsString('Use saved address', $modal);
        $this->assertStringNotContainsString('shipmentForm.country', $modal);
        $this->assertStringNotContainsString('shipmentForm.state', $modal);
        $this->assertStringNotContainsString('shipmentForm.city', $modal);
        $this->assertStringNotContainsString('QUANTITY (OPTIONAL)', $modal);
        $this->assertStringNotContainsString('PACKAGE / REFERENCE (OPTIONAL)', $modal);
        $this->assertStringContainsString('Shipping method', $modal);
        $this->assertStringContainsString('mode="modal"', $modal);
        $this->assertStringContainsString(':selected="$selectedShipmentMethod"', $modal);
        $this->assertStringContainsString("@error('shipmentMethod')", $modal);

        $this->assertStringContainsString('openShipmentSavedAddressPicker', $manager);
        $this->assertStringContainsString('applyShipmentSavedAddress', $manager);
        $this->assertStringContainsString("active('phone_country_code')", $workflow);
        $this->assertStringContainsString("'phone_country_codes'", $presenter);
        $this->assertStringContainsString("'saved_shipping_addresses'", $presenter);
    }

    public function test_multiple_shipment_flags_are_derived_from_actual_shipment_rows(): void
    {
        $service = file_get_contents(app_path('Services/OrderShipmentService.php'));

        $this->assertStringContainsString('syncPlanFromShipments', $service);
        $this->assertStringContainsString("'allow_multiple_shipments' => \$allowMultiple", $service);
        $this->assertStringNotContainsString('Enable Allow multiple shipments before adding another shipment.', $service);
    }

    public function test_shipment_modal_required_fields_match_create_order_and_hidden_shipping_data_is_preserved(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/add-modal.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/shipment-modal.css'));
        $manager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderShipments.php'));
        $service = file_get_contents(app_path('Services/OrderShipmentService.php'));

        foreach (['Contact person', 'Phone', 'Shipping address', 'Postal code', 'Shipping method'] as $label) {
            $this->assertMatchesRegularExpression('/'.preg_quote($label, '/').'.*ft-order-required-star/s', $modal);
        }
        $this->assertStringContainsString('var(--ft-form-label-color)', $css);
        $this->assertStringContainsString('var(--ft-form-control-height)', $css);

        // The compact modal no longer duplicates fields managed elsewhere, but
        // their persisted values remain in the form state and service payload.
        $this->assertStringNotContainsString('SHIPMENT NO.', $modal);
        $this->assertStringNotContainsString('QUANTITY (OPTIONAL)', $modal);
        $this->assertStringNotContainsString('PACKAGE / REFERENCE (OPTIONAL)', $modal);
        $this->assertStringContainsString('Shipping method', $modal);
        $this->assertStringContainsString("addError('shipmentMethod', 'Choose a shipping method.')", $manager);
        $this->assertStringContainsString("'shipment_method_id' => \$defaultMethodId", $manager);
        $this->assertStringContainsString("'quantity' => \$shipment->quantity", $manager);
        $this->assertStringContainsString("'_shipment_address_form' => true", $manager);
        $this->assertStringContainsString('validatedAddressFields($payload, null, false, true)', $service);
        $this->assertStringContainsString('Choose an active phone country code from Master Data.', $service);
    }

    public function test_order_shipment_urgency_and_primary_shipping_method_stay_synchronized_both_ways(): void
    {
        $shipmentService = file_get_contents(app_path('Services/OrderShipmentService.php'));
        $legacyJobService = file_get_contents(app_path('Services/LegacyJobService.php'));
        $shipmentManager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderShipments.php'));
        $orderManager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderDetail.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $presenter = file_get_contents(app_path('Support/CreateOrderShippingMethodPresenter.php'));
        $viewService = file_get_contents(app_path('Services/OrderDetailViewService.php'));
        $inline = file_get_contents(resource_path('views/components/jobs/order-detail/shipment-urgency-inline.blade.php'));

        // Planning can represent the complete shipping choice, not only the
        // Express urgency IDs. Sea/Air/Road and Express levels use one safe
        // composite value and one selector.
        $this->assertStringContainsString('orderShippingOptions', $presenter);
        $this->assertStringContainsString("'road' => 'Road Freight'", $presenter);
        $this->assertStringContainsString("return 'm:'.\$methodId.':u:'", $presenter);
        $this->assertStringContainsString("'shipmentShippingOptions' => \$shippingOptions->all()", $viewService);
        $this->assertStringContainsString('updateJobShippingSelection', $inline);
        $this->assertStringContainsString('shipmentShippingValue', $inline);

        // Order-level selection -> primary shipment method/service level.
        $this->assertStringContainsString('syncPrimaryShipmentFromOrderShippingSelection', $shipmentService);
        $this->assertStringContainsString("'shipment_method_ids' => [(int) \$method->id]", $shipmentService);
        $this->assertStringContainsString("'shipment_urgency_ids' => \$normalizedUrgencyId ? [(int) \$normalizedUrgencyId] : []", $shipmentService);
        $this->assertStringContainsString("'shipment_method_id' => \$method->id", $shipmentService);
        $this->assertStringContainsString('updateShippingSelection', $legacyJobService);
        $this->assertStringContainsString('UpdateOrderShippingSelection', $orderManager);

        // Legacy urgency-only callers remain supported and still select Express.
        $this->assertStringContainsString('syncPrimaryShipmentFromOrderUrgency', $shipmentService);
        $this->assertStringContainsString("if (\$field === 'shipment_urgency_ids')", $legacyJobService);

        // Primary shipment method -> Order-level method + urgency/default selection.
        $this->assertStringContainsString('syncLegacyPrimaryShippingSelection', $shipmentService);
        $this->assertStringContainsString('if ($locked->is_primary)', $shipmentService);
        $this->assertStringContainsString("'shipment_method_ids' => \$shipment->shipment_method_id ? [(int) \$shipment->shipment_method_id] : []", $shipmentService);
        $this->assertStringContainsString('$shippingMethodChanged =', $shipmentService);
        $this->assertStringContainsString('syncLegacyPrimaryShippingSelection($job, $locked->refresh())', $shipmentService);
        $this->assertStringContainsString('$hadTrackingForPreviousMethod', $shipmentService);

        // Both isolated Livewire directions refresh immediately without a browser refresh.
        $this->assertStringContainsString("'ft-shipment-urgency-updated'", $shipmentManager);
        $this->assertStringContainsString('selectionValue(', $shipmentManager);
        $this->assertStringContainsString("'order-runtime-refreshed'", $shipmentManager);
        $this->assertStringContainsString("'order-shipping-selection-updated'", $orderManager);
        $this->assertStringContainsString("#[On('order-shipping-selection-updated')]", $workflow);

        // Method/service changes must not leave a stale carrier label behind.
        $this->assertStringContainsString('A shipping selection change invalidates any label', $shipmentService);
        $this->assertStringContainsString('$this->reopenTrackingTask($lockedJob, $actor);', $shipmentService);
    }

    public function test_tracking_uses_courier_master_data_and_shipping_method_eta_copy_is_hidden(): void
    {
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $presenter = file_get_contents(app_path('Support/OrderShipmentPresenter.php'));
        $tracking = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/tracking-table.blade.php'));
        $dispatch = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/dispatch-table.blade.php'));
        $picker = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/method-picker.blade.php'));
        $plan = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/plan-table.blade.php'));

        $this->assertStringContainsString("active('courier')", $pageData);
        $this->assertStringContainsString("'shipmentCouriers'", $pageData);
        $this->assertStringContainsString("'courier_name'", $presenter);
        $this->assertStringContainsString('<th>Courier</th>', $tracking);
        $this->assertStringContainsString('<th>Courier</th>', $dispatch);
        $this->assertStringNotContainsString('Courier / Method', $tracking);
        $this->assertStringNotContainsString('Courier / Method', $dispatch);
        $this->assertStringNotContainsString("['estimate']", $picker);
        $this->assertStringNotContainsString("['estimate']", $plan);
    }

}
