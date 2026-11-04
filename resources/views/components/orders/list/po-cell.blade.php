<td data-label="Purchase order" class="ft-order-po-cell">
    <x-orders.po-action :order-id="$job->id" :generated="(bool) $job->has_active_supplier_po" />
</td>
