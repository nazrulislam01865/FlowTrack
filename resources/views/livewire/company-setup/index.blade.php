<div class="ft-company-setup">
    <div class="ft-company-setup-head">
        <div>
            <span class="ft-company-kicker">Settings</span>
            <h1>Company Setup</h1>
            <p>Set the legal, contact and payment details that FlowTrack prints automatically on newly generated invoices.</p>
        </div>

        <div class="ft-company-head-actions">
            @if($isEditing)
                <div class="ft-company-save-status" wire:loading wire:target="save">Saving company details…</div>
                <button type="button" class="secondary ft-company-head-cancel" wire:click="cancelEditing" wire:loading.attr="disabled" wire:target="cancelEditing,save">Cancel</button>
            @else
                <button type="button" class="primary ft-company-edit-button" wire:click="beginEditing" wire:loading.attr="disabled" wire:target="beginEditing">
                    <span wire:loading.remove wire:target="beginEditing">Edit Company Details</span>
                    <span wire:loading wire:target="beginEditing">Opening…</span>
                </button>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="flash">{{ session('success') }}</div>
    @endif

    @if($isEditing)
        <form wire:submit="save" class="ft-company-form" data-ft-feedback-scope="form">
            <section class="card ft-company-card">
                <div class="ft-company-card-head">
                    <div><h2>Company identity</h2><p>These details identify the seller / issuing company on the invoice.</p></div>
                    <span class="ft-company-required-note">Legal name is required</span>
                </div>
                <div class="ft-company-grid">
                    <div class="field">
                        <label>Legal company name *</label>
                        <input wire:model="legalName" maxlength="180" placeholder="e.g. Step Promo Co., Ltd.">
                        @error('legalName')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label>Trading / display name</label>
                        <input wire:model="tradingName" maxlength="180" placeholder="Optional trading name">
                        @error('tradingName')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label>Company registration number</label>
                        <input wire:model="registrationNumber" maxlength="100" placeholder="Registration / company number">
                        @error('registrationNumber')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label>Tax / VAT number</label>
                        <input wire:model="taxNumber" maxlength="100" placeholder="Tax ID / VAT / GST number">
                        @error('taxNumber')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Contact & registered address</h2><p>Shown in the seller section of the invoice.</p></div></div>
                <div class="ft-company-grid">
                    <div class="field">
                        <label>Invoice / accounts email</label>
                        <input wire:model="billingEmail" type="email" maxlength="180" placeholder="accounts@company.com">
                        @error('billingEmail')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label>Phone</label>
                        <input wire:model="phone" maxlength="80" placeholder="Company phone number">
                        @error('phone')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field full">
                        <label>Website</label>
                        <input wire:model="website" maxlength="180" placeholder="www.company.com">
                        @error('website')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field full">
                        <label>Address line 1</label>
                        <input wire:model="addressLine1" maxlength="180" placeholder="Building, road, district">
                        @error('addressLine1')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field full">
                        <label>Address line 2</label>
                        <input wire:model="addressLine2" maxlength="180" placeholder="Suite, floor or additional address">
                        @error('addressLine2')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field"><label>City</label><input wire:model="city" maxlength="100">@error('city')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>State / region</label><input wire:model="stateRegion" maxlength="100">@error('stateRegion')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>Postal code</label><input wire:model="postalCode" maxlength="40">@error('postalCode')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>Country</label><input wire:model="country" maxlength="100">@error('country')<div class="validation-error">{{ $message }}</div>@enderror</div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Payment details</h2><p>Optional bank and remittance information printed on invoices so clients know how to pay.</p></div></div>
                <div class="ft-company-grid">
                    <div class="field"><label>Bank name</label><input wire:model="bankName" maxlength="160">@error('bankName')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>Account name</label><input wire:model="bankAccountName" maxlength="160">@error('bankAccountName')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>Account number</label><input wire:model="bankAccountNumber" maxlength="120">@error('bankAccountNumber')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>IBAN</label><input wire:model="bankIban" maxlength="120">@error('bankIban')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field"><label>SWIFT / BIC</label><input wire:model="bankSwift" maxlength="80">@error('bankSwift')<div class="validation-error">{{ $message }}</div>@enderror</div>
                    <div class="field full"><label>Payment instructions</label><textarea wire:model="paymentInstructions" rows="3" maxlength="1000" placeholder="e.g. Please include the invoice number with your transfer."></textarea>@error('paymentInstructions')<div class="validation-error">{{ $message }}</div>@enderror</div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Invoice footer</h2><p>Add an optional legal or thank-you line at the bottom of invoices.</p></div></div>
                <div class="field">
                    <label>Footer text</label>
                    <textarea wire:model="invoiceFooter" rows="3" maxlength="500" placeholder="Optional invoice footer"></textarea>
                    @error('invoiceFooter')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
                <div class="ft-company-info-note">Company details are copied into each new invoice when it is created. Updating Company Setup changes future invoices without silently changing previously generated invoices.</div>
            </section>

            <div class="ft-company-actions">
                <button type="button" class="secondary" wire:click="cancelEditing" wire:loading.attr="disabled" wire:target="cancelEditing,save">Cancel</button>
                <button type="submit" class="primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save Company Details</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </form>
    @else
        <div class="ft-company-form ft-company-view-mode">
            <section class="card ft-company-card">
                <div class="ft-company-card-head">
                    <div><h2>Company identity</h2><p>These details identify the seller / issuing company on the invoice.</p></div>
                </div>
                <div class="ft-company-view-grid">
                    <div class="ft-company-view-item"><span>Legal company name</span><strong>{{ filled($legalName) ? $legalName : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Trading / display name</span><strong>{{ filled($tradingName) ? $tradingName : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Company registration number</span><strong>{{ filled($registrationNumber) ? $registrationNumber : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Tax / VAT number</span><strong>{{ filled($taxNumber) ? $taxNumber : '—' }}</strong></div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Contact & registered address</h2><p>Shown in the seller section of the invoice.</p></div></div>
                <div class="ft-company-view-grid">
                    <div class="ft-company-view-item"><span>Invoice / accounts email</span><strong>{{ filled($billingEmail) ? $billingEmail : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Phone</span><strong>{{ filled($phone) ? $phone : '—' }}</strong></div>
                    <div class="ft-company-view-item full"><span>Website</span><strong>{{ filled($website) ? $website : '—' }}</strong></div>
                    <div class="ft-company-view-item full"><span>Address line 1</span><strong>{{ filled($addressLine1) ? $addressLine1 : '—' }}</strong></div>
                    <div class="ft-company-view-item full"><span>Address line 2</span><strong>{{ filled($addressLine2) ? $addressLine2 : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>City</span><strong>{{ filled($city) ? $city : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>State / region</span><strong>{{ filled($stateRegion) ? $stateRegion : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Postal code</span><strong>{{ filled($postalCode) ? $postalCode : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Country</span><strong>{{ filled($country) ? $country : '—' }}</strong></div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Payment details</h2><p>Optional bank and remittance information printed on invoices so clients know how to pay.</p></div></div>
                <div class="ft-company-view-grid">
                    <div class="ft-company-view-item"><span>Bank name</span><strong>{{ filled($bankName) ? $bankName : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Account name</span><strong>{{ filled($bankAccountName) ? $bankAccountName : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>Account number</span><strong>{{ filled($bankAccountNumber) ? $bankAccountNumber : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>IBAN</span><strong>{{ filled($bankIban) ? $bankIban : '—' }}</strong></div>
                    <div class="ft-company-view-item"><span>SWIFT / BIC</span><strong>{{ filled($bankSwift) ? $bankSwift : '—' }}</strong></div>
                    <div class="ft-company-view-item full"><span>Payment instructions</span><strong class="multiline">{{ filled($paymentInstructions) ? $paymentInstructions : '—' }}</strong></div>
                </div>
            </section>

            <section class="card ft-company-card">
                <div class="ft-company-card-head"><div><h2>Invoice footer</h2><p>Add an optional legal or thank-you line at the bottom of invoices.</p></div></div>
                <div class="ft-company-view-grid single">
                    <div class="ft-company-view-item full"><span>Footer text</span><strong class="multiline">{{ filled($invoiceFooter) ? $invoiceFooter : '—' }}</strong></div>
                </div>
                <div class="ft-company-info-note">Company details are copied into each new invoice when it is created. Updating Company Setup changes future invoices without silently changing previously generated invoices.</div>
            </section>
        </div>
    @endif
</div>
