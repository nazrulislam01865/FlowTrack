@if($editingSupplierCurrencyTable && $currencyTableSupplierId === (int) $supplierId)
    @php
        $currencyChoices = app(\App\Services\SupplierBridge\SupplierCurrencyPriceTables::class)
            ->currencyOptions((int) $product->workspace_id);
    @endphp
    <div class="ft-product-client-price-entry ft-price-editor">
        <div class="ft-product-price-section-header">
            <strong>{{ $currencyTableOriginalCurrency !== '' ? 'Edit additional currency table' : 'Add currency price table' }}</strong>
        </div>
        <label class="ft-product-field" style="display:block;max-width:280px;margin:12px 0">
            <span>Currency</span>
            <select wire:model="currencyTableCurrency" @disabled($currencyTableOriginalCurrency !== '')>
                <option value="">Select currency</option>
                @foreach($currencyChoices as $currencyChoice)
                    <option value="{{ $currencyChoice['code'] }}">{{ $currencyChoice['code'] }} — {{ $currencyChoice['name'] }}</option>
                @endforeach
            </select>
        </label>
        @error('currencyTableCurrency')<b class="validation-error">{{ $message }}</b>@enderror
        <div class="ft-product-matrix-toolbar">
            <small>Enter quantity, supplier price and optional charges. This additional table does not replace approved primary supplier costs.</small>
            <button type="button" class="ft-product-matrix-add" wire:click="addSupplierCurrencyTier">+ Add quantity</button>
        </div>
        <div class="ft-product-price-preview-wrap ft-product-matrix-scroll" role="region" aria-label="Additional currency pricing" tabindex="0">
            <table class="ft-product-price-preview ft-product-price-matrix">
                <thead><tr><th scope="col">Quantity</th>
                    @foreach($currencyTableTiers as $tierIndex => $tier)
                        <th scope="col" wire:key="currency-table-quantity-{{ $tierIndex }}">
                            <div class="ft-product-matrix-quantity">
                                <input type="number" min="1" max="100000000" step="1" inputmode="numeric" placeholder="Quantity"
                                    aria-label="Quantity {{ $tierIndex + 1 }}"
                                    wire:model.blur="currencyTableTiers.{{ $tierIndex }}.quantity">
                                <button type="button" class="ft-product-matrix-remove" aria-label="Remove quantity {{ $tierIndex + 1 }}"
                                    wire:click="removeSupplierCurrencyTier({{ $tierIndex }})">×</button>
                            </div>
                        </th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach(['price'=>'Supplier price','remote_surcharge'=>'Remote surcharge','standard_shipment'=>'Standard shipment','urgent_shipment'=>'Urgent shipment'] as $field=>$heading)
                        <tr><th scope="row">{{ $heading }}</th>
                            @foreach($currencyTableTiers as $tierIndex => $tier)
                                <td><input type="number" min="0" max="999999999.99" step="0.000001" placeholder="—"
                                    aria-label="{{ $heading }} for quantity column {{ $tierIndex + 1 }}"
                                    wire:model.blur="currencyTableTiers.{{ $tierIndex }}.{{ $field }}"></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('currencyTableTiers')<b class="validation-error">{{ $message }}</b>@enderror
        <div class="ft-product-form-bottom-actions" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
            @if($currencyTableOriginalCurrency !== '')
                <button type="button" class="ft-product-page-btn is-secondary" wire:click="deleteSupplierCurrencyTable"
                    wire:confirm="Remove this additional currency price table?">Remove table</button>
            @endif
            <button type="button" class="ft-product-page-btn is-secondary" wire:click="closeSupplierCurrencyTable">Cancel</button>
            <button type="button" class="ft-product-page-btn is-primary" wire:click="saveSupplierCurrencyTable">Save currency table</button>
        </div>
    </div>
@endif
