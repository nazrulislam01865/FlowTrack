<div class="ft-supplier-create-page" data-ft-feedback-scope="form">
    <div class="ft-supplier-create-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('master-data', ['group' => 'supplier']) }}" wire:navigate>Suppliers</a>
        <span>/</span>
        <strong>Create supplier</strong>
    </div>

    <header class="ft-supplier-create-head">
        <h1>Create supplier</h1>
        <p>Add the essentials now. More details can be added later.</p>
    </header>

    <div class="ft-supplier-create-layout">
        <div class="ft-supplier-create-main">
            <x-suppliers.form-card title="Supplier information" copy="Only the supplier name is required.">
                <div class="ft-supplier-form-grid">
                    <x-suppliers.field label="Supplier name" required error="name" wide>
                        <input wire:model.blur="name" type="text" placeholder="e.g. Guangzhou Apex Sports" autocomplete="organization">
                    </x-suppliers.field>

                    <x-suppliers.field label="Supplier short code" error="supplierShortCode">
                        <input wire:model.blur="supplierShortCode" type="text" maxlength="40" placeholder="e.g. MR" autocomplete="off">
                    </x-suppliers.field>

                    <x-suppliers.field label="Contact person" error="supplierContactPerson">
                        <input wire:model.blur="supplierContactPerson" type="text" placeholder="Full name" autocomplete="name">
                    </x-suppliers.field>

                    <x-suppliers.field label="Email" error="supplierEmail">
                        <input wire:model.blur="supplierEmail" type="email" placeholder="name@company.com" autocomplete="email">
                    </x-suppliers.field>

                    <x-suppliers.field label="Phone" error="supplierPhone">
                        <input wire:model.blur="supplierPhone" type="tel" placeholder="Country code + number" autocomplete="tel">
                    </x-suppliers.field>

                    <x-suppliers.field label="Status" error="status">
                        <select wire:model="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </x-suppliers.field>
                </div>
            </x-suppliers.form-card>

            <x-suppliers.form-card
                title="Assign products"
                badge="Optional"
                copy="Paste product codes separated by commas, spaces, or new lines. Matching products are added automatically."
                class="ft-supplier-assign-card"
            >

                <x-suppliers.field label="Product codes" error="supplierProductCodes">
                    <div
                        class="ft-supplier-codebox"
                        x-data
                        x-on:click="$refs.productCodeInput.focus()"
                    >
                        @if($supplierCreateCodeRows->isNotEmpty())
                            <div class="ft-supplier-code-tokens" aria-label="Entered product codes">
                                @foreach($supplierCreateCodeRows as $row)
                                    <button
                                        type="button"
                                        class="ft-supplier-code-token {{ $row['valid'] ? '' : 'is-invalid' }}"
                                        wire:click.stop="removeSupplierProductCode('{{ $row['code'] }}')"
                                        title="Remove {{ $row['code'] }}"
                                    >
                                        <span>{{ $row['code'] }}</span>
                                        @if(!$row['valid'])<small>not found</small>@endif
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <input
                            x-ref="productCodeInput"
                            wire:model="supplierCodeDraft"
                            wire:keydown.enter.prevent="commitSupplierProductCodes"
                            wire:keydown.tab="commitSupplierProductCodes"
                            wire:blur="commitSupplierProductCodes"
                            x-on:paste="setTimeout(() => $wire.commitSupplierProductCodes($refs.productCodeInput.value), 0)"
                            type="text"
                            placeholder="Try PRD-1007, PRD-1009"
                            aria-label="Product codes"
                            autocomplete="off"
                        >
                    </div>
                    <div class="ft-supplier-code-help">Press Enter after each code. Unknown codes are flagged before saving.</div>
                </x-suppliers.field>

                @if($supplierCreateCodeRows->where('valid', true)->isNotEmpty())
                    <div class="ft-supplier-product-preview" aria-label="Products to assign">
                        @foreach($supplierCreateCodeRows->where('valid', true) as $row)
                            <div class="ft-supplier-product-preview-row">
                                <div>
                                    <strong>{{ $row['name'] }}</strong>
                                    <span>{{ $row['code'] }} · {{ $row['category'] }}</span>
                                </div>
                                <span class="ft-supplier-preview-state {{ $row['has_supplier'] ? 'is-reassign' : 'is-ready' }}">
                                    {{ $row['has_supplier'] ? 'Will also link' : 'Ready' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="ft-supplier-tip">
                    <strong>Need to assign many products visually?</strong>
                    <span>Save the supplier first, then use checkboxes on the Product list and choose “Assign supplier”.</span>
                </div>
            </x-suppliers.form-card>

            @if(auth()->user()->canModule('suppliers', 'manage'))
                <x-suppliers.form-card title="Supplier portal login" badge="Optional" copy="Set a username, login email and password to create portal access immediately. Supplier and products will be synchronized using their FlowTracker IDs. No password is saved in FlowTracker.">
                    <div class="ft-supplier-form-grid">
                        <x-suppliers.field label="Portal username" error="portalUsername">
                            <input wire:model="portalUsername" type="text" maxlength="60" autocomplete="off" placeholder="supplier.username">
                        </x-suppliers.field>
                        <x-suppliers.field label="Portal login email" error="portalEmail">
                            <input wire:model="portalEmail" type="email" maxlength="254" autocomplete="off" placeholder="supplier@example.com">
                        </x-suppliers.field>
                        <x-suppliers.field label="Portal password" error="portalPassword" wide>
                            <input wire:model="portalPassword" type="password" autocomplete="new-password" placeholder="At least 12 characters, mixed case and a number">
                        </x-suppliers.field>
                    </div>
                    <small>Leave all three blank to create the supplier without a login. You can add portal credentials later from Edit supplier.</small>
                    <small>When enabled under Reminder Setup, successful portal account creation sends login details to the portal email and a separate secure password-setup link. Passwords are not sent by email.</small>
                </x-suppliers.form-card>
            @endif

            <div class="ft-supplier-create-actions">
                <button type="button" class="ft-supplier-secondary-button" wire:click="cancelSupplierCreate">Cancel</button>
                <button
                    type="button"
                    class="ft-supplier-primary-button"
                    wire:click="createSupplier"
                    wire:loading.attr="disabled"
                    wire:target="createSupplier"
                >
                    <span wire:loading.remove wire:target="createSupplier">Create supplier</span>
                    <span wire:loading wire:target="createSupplier">Creating…</span>
                </button>
            </div>
        </div>

    </div>
</div>
