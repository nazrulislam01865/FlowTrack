@props([
    'users',
    'clientCode',
    'clientName' => '',
    'clientCountries' => [],
    'clientCountryFlags' => [],
    'clientStatesByCountry' => [],
    'clientLanguages' => [],
    'clientCurrencies' => [],
    'paymentTermOptions' => [],
    'accountManagerId' => null,
    'preferredCurrency' => '',
    'clientCountry' => '',
    'officeState' => '',
    'billingState' => '',
    'billingCountry' => '',
    'billingRecipient' => '',
    'billingAddressLine1' => '',
    'billingSuite' => '',
    'billingCity' => '',
    'billingZip' => '',
    'billingSameAsOffice' => true,
    'salesTaxStatus' => 'taxable',
    'shippingAddresses' => [],
    'contacts' => [],
    'mode' => 'create',
    'clientLogoUpload' => null,
    'existingClientLogoUrl' => '',
    'removeClientLogo' => false,
    'addressOptionsReady' => true,
])
@php
    $isEdit = $mode === 'edit';
    $accountManagerOptions = collect($users)->values();
    $selectedManager = $accountManagerOptions->first(fn ($option) => (string) data_get($option, 'id') === (string) $accountManagerId);
    $countryOptions = collect($clientCountries)->map(fn ($country) => [
        'id' => (string) $country,
        'label' => (string) $country,
        'meta' => (string) ($clientCountryFlags[$country] ?? ''),
    ])->values();
    $officeStates = $clientStatesByCountry[$clientCountry] ?? [];
    $billingStates = $clientStatesByCountry[$billingCountry] ?? [];
    $billingLine2Visible = trim((string) $billingSuite) !== '';
    $clientLogoPreview = null;
    if ($clientLogoUpload && in_array(strtolower((string) $clientLogoUpload->getClientOriginalExtension()), ['jpg','jpeg','png','webp'], true)) {
        try { $clientLogoPreview = $clientLogoUpload->temporaryUrl(); } catch (\Throwable $e) { $clientLogoPreview = null; }
    }
    if (! $clientLogoPreview && ! $removeClientLogo && $existingClientLogoUrl !== '') $clientLogoPreview = $existingClientLogoUrl;
    $clientValidationSignature = $errors->any()
        ? sha1((string) json_encode($errors->getMessages(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        : '';
@endphp
<div class="{{ $isEdit ? 'ft-client-inline-edit ft-client-create-prototype ft-reusable-form-theme' : 'ft-create-client-page ft-client-create-prototype ft-reusable-form-theme ft-form-standard ft-form-standard--client' }}" data-ft-feedback-scope="form" data-client-validation-signature="{{ $clientValidationSignature }}">
    <div class="ft-client-create-shell">
        @unless($isEdit)
            <div class="ft-client-create-top">
                <div>
                    <div class="ft-create-breadcrumb">Add Client</div>
                    <h1>Add Client</h1>
                    <p>Client Information is saved as a full page, not a drawer.</p>
                </div>
                <button type="button" class="ft-back-clients" wire:click="closeCreate">← Back to Clients</button>
            </div>
        @endunless

        <section class="ft-client-prototype-card">
            <header class="ft-client-prototype-head">
                <div>
                    <h2>{{ $isEdit ? 'Edit Client' : 'Create New Client' }}</h2>
                    <p>{{ $isEdit ? 'Update the client business, contact, address and commercial information.' : "Add the client's business, contact and delivery information." }}</p>
                </div>
                <div class="ft-client-required-note"><span>*</span> Required <b>•</b> Optional fields are labeled</div>
            </header>

            <section class="ft-client-prototype-section">
                <div class="ft-client-section-title"><span>1</span><div><h3>Client details</h3></div></div>
                <div class="ft-client-grid ft-client-grid-3">
                    <div class="ft-client-logo-upload">
                        <div class="ft-client-logo-preview">
                            @if($clientLogoPreview)
                                <img src="{{ $clientLogoPreview }}" alt="Client logo preview">
                            @else
                                <x-ui.client-logo :name="$clientName ?: 'Client'" :size="82" />
                            @endif
                        </div>
                        <div class="ft-client-logo-upload-copy">
                            <b>Client logo <span class="ft-client-logo-optional">(Optional)</span></b>
                            <p>Upload the company logo once and FlowTrack will reuse it anywhere this client is shown. JPG, PNG or WebP · max 5 MB.</p>
                            <div class="ft-client-logo-actions">
                                <label class="ft-client-logo-file">
                                    <input type="file" wire:model="clientLogoUpload" accept="image/jpeg,image/png,image/webp">
                                    <span wire:loading.remove wire:target="clientLogoUpload">{{ $clientLogoPreview ? 'Choose new logo' : 'Choose logo' }}</span>
                                    <span wire:loading wire:target="clientLogoUpload">Preparing…</span>
                                </label>
                                @if($isEdit && $existingClientLogoUrl !== '' && ! $removeClientLogo && ! $clientLogoUpload)
                                    <button type="button" class="ft-client-logo-remove" wire:click="markClientLogoForRemoval">Remove logo</button>
                                @elseif($removeClientLogo)
                                    <button type="button" class="ft-client-logo-undo" wire:click="restoreClientLogo">Undo remove</button>
                                @endif
                            </div>
                            @if($removeClientLogo)<small class="ft-client-logo-removal-note">The existing logo will be removed when you save the client.</small>@endif
                            @error('clientLogoUpload')<small class="validation-error">{{ $message }}</small>@enderror
                        </div>
                    </div>
                    <label class="ft-proto-field">
                        <b>Client code</b>
                        <div class="ft-client-code-lock"><span>{{ $clientCode }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V7a5 5 0 0110 0v3m-9 0h8a2 2 0 012 2v7H6v-7a2 2 0 012-2z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></div>
                        <small>Generated automatically after the client is created.</small>
                    </label>
                    <label class="ft-proto-field">
                        <b>Client name <em>*</em></b>
                        <input wire:model="clientName" placeholder="Acme Apparel Inc.">
                        @error('clientName')<small class="validation-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ft-proto-field">
                        <b>Legal business name <span>(Optional)</span></b>
                        <input wire:model="legalBusinessName" placeholder="Acme Apparel Incorporated">
                    </label>
                    <label class="ft-proto-field">
                        <b>Website <span>(Optional)</span></b>
                        <input wire:model="website" placeholder="www.acmeapparel.com">
                    </label>
                    <div class="ft-proto-field">
                        <b>Account manager <em>*</em></b>
                        <x-ui.search-select
                            label="Account manager"
                            property="accountManagerId"
                            :value="$accountManagerId ?? ''"
                            placeholder="Unassigned"
                            type="users"
                            context="client-account-manager"
                            :initial-options="$accountManagerOptions"
                            :selected-label="data_get($selectedManager, 'label', 'Unassigned')"
                            search-placeholder="Search account manager…"
                            :menu-width="360"
                            :fixed-menu="true"
                            :hide-label="true"
                            class="ft-form-search-select"
                        />
                        @error('accountManagerId')<small class="validation-error">{{ $message }}</small>@enderror
                    </div>
                    <label class="ft-proto-field">
                        <b>Preferred language <span>(Optional)</span></b>
                        <select wire:model="preferredLanguage">@foreach($clientLanguages as $language)<option value="{{ $language }}">{{ $language }}</option>@endforeach</select>
                    </label>
                    <label class="ft-proto-field ft-currency-field">
                        <b>Preferred currency <em>*</em></b>
                        <select wire:model="preferredCurrency">
                            <option value="">Select currency</option>
                            @foreach($clientCurrencies as $code => $currencyName)
                                <option value="{{ $code }}">{{ $code }} · {{ $currencyName }}</option>
                            @endforeach
                        </select>
                        <small>Available currencies are managed in Financial Master Data.</small>
                        @error('preferredCurrency')<small class="validation-error">{{ $message }}</small>@enderror
                    </label>
                </div>
            </section>

            <section class="ft-client-prototype-section ft-client-contacts-editor">
                <div class="ft-client-section-title">
                    <div class="ft-section-title-left"><span>2</span><div><h3>Contact</h3><p>Add the people your team can use for inquiries, orders and documents. The first contact is primary.</p></div></div>
                </div>
                <div class="ft-client-contact-editor-list">
                    @foreach($contacts as $contactIndex => $contact)
                        <article class="ft-client-contact-editor-row" wire:key="client-contact-row-{{ $contactIndex }}">
                            <div class="ft-client-contact-row-label">
                                <b>{{ $contactIndex === 0 ? 'Primary contact' : 'Contact '.($contactIndex + 1) }}</b>
                                @if($contactIndex === 0)<span>Primary</span>@endif
                            </div>
                            <label class="ft-proto-field"><b>Contact name <em>*</em></b><input wire:model="contacts.{{ $contactIndex }}.name" placeholder="Sarah Chen">@error('contacts.'.$contactIndex.'.name')<small class="validation-error">{{ $message }}</small>@enderror</label>
                            <label class="ft-proto-field"><b>Job title <span>(Optional)</span></b><input wire:model="contacts.{{ $contactIndex }}.job_title" placeholder="Purchasing Manager"></label>
                            <label class="ft-proto-field"><b>Email <em>*</em></b><input type="email" wire:model="contacts.{{ $contactIndex }}.email" placeholder="purchasing@acmeapparel.com">@error('contacts.'.$contactIndex.'.email')<small class="validation-error">{{ $message }}</small>@enderror</label>
                            <label class="ft-proto-field"><b>Phone <span>(Optional)</span></b><input wire:model="contacts.{{ $contactIndex }}.phone" placeholder="+1 (212) 555-0184"></label>
                            @if(count($contacts) > 1)
                                <button type="button" class="ft-client-remove-contact" wire:click="removeContact({{ $contactIndex }})" aria-label="Remove {{ $contactIndex === 0 ? 'primary contact' : 'contact '.($contactIndex + 1) }}">×</button>
                            @else
                                <span class="ft-client-contact-remove-spacer" aria-hidden="true"></span>
                            @endif
                        </article>
                    @endforeach
                    @if(count($contacts) < 20)
                        <div class="ft-client-contact-add-row" wire:key="client-contact-add-row">
                            <button type="button" class="ft-client-add-contact" wire:click="addContact">+ Add contact</button>
                        </div>
                    @endif
                </div>
                @error('contacts')<small class="validation-error ft-client-contacts-error">{{ $message }}</small>@enderror
            </section>

            @if($isEdit || $addressOptionsReady)
            <section class="ft-client-prototype-section ft-client-shipping-aligned-section">
                <div class="ft-client-section-title ft-client-section-title-spread">
                    <div class="ft-section-title-left">
                        <span>3</span>
                        <div>
                            <h3>Shipping address</h3>
                            <p>Enter the delivery address for this client.</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="ft-client-shipping-saved-action"
                        wire:click="useSavedAddressForShipping"
                        title="Use the office or billing address already entered on this form"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4.75A1.75 1.75 0 017.75 3h8.5A1.75 1.75 0 0118 4.75V21l-6-3.8L6 21V4.75z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        <span>Use saved address</span>
                    </button>
                </div>

                <div class="ft-shipping-prototype-list">
                    @foreach($shippingAddresses as $index => $address)
                        @php
                            $shippingStates = $clientStatesByCountry[$address['country'] ?? ''] ?? [];
                        @endphp
                        <x-ui.shipping-address-editor
                            :index="$index"
                            :address="$address"
                            :countries="$clientCountries"
                            :country-flags="$clientCountryFlags"
                            :states="$shippingStates"
                            :can-remove="count($shippingAddresses) > 1"
                            :recipient-required="!$isEdit"
                        />
                    @endforeach
                </div>

                @if(count($shippingAddresses) < 20)
                    <button type="button" class="ft-shipping-prototype-add" wire:click="addShippingAddress">
                        <span aria-hidden="true">+</span> Add another shipping address
                    </button>
                @endif
            </section>

            <section class="ft-client-prototype-section ft-client-shipping-aligned-section">
                <div class="ft-client-section-title ft-client-section-title-spread">
                    <div class="ft-section-title-left">
                        <span>4</span>
                        <div>
                            <h3>Billing address</h3>
                            <p>Enter the billing address for this client.</p>
                        </div>
                    </div>
                </div>

                <article
                    class="ft-shipping-prototype-address"
                    x-data="{ showLine2: @js($billingLine2Visible) }"
                >
                    <div class="ft-client-grid ft-shipping-aligned-grid">
                        <label class="ft-proto-field ft-shipping-recipient-field">
                            <b>Recipient name <em>*</em></b>
                            <input wire:model="billingRecipient" placeholder="Recipient name">
                            @error('billingRecipient')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>

                        <div class="ft-proto-field ft-shipping-country-field">
                            <b>Country / region <em>*</em></b>
                            <x-ui.search-select
                                label="Country / region"
                                property="billingCountry"
                                :value="$billingCountry"
                                placeholder="Select country / region"
                                :options="$countryOptions"
                                search-placeholder="Search country / region…"
                                :menu-width="360"
                                :fixed-menu="true"
                                :hide-label="true"
                                class="ft-form-search-select"
                            />
                            @error('billingCountry')<small class="validation-error">{{ $message }}</small>@enderror
                        </div>

                        <label class="ft-proto-field ft-shipping-address-line1-field">
                            <b>Address line 1 <em>*</em></b>
                            <input wire:model="billingAddressLine1" placeholder="Street address">
                            @error('billingAddressLine1')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>

                        <label class="ft-proto-field ft-shipping-address-line2-field" x-show="showLine2" x-cloak>
                            <b>Address line 2 <span>(Optional)</span></b>
                            <input wire:model="billingSuite" placeholder="Apartment, suite, unit, building, floor, etc.">
                            @error('billingSuite')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>

                        <label class="ft-proto-field ft-shipping-city-field">
                            <b>City <em>*</em></b>
                            <input wire:model="billingCity" placeholder="City">
                            @error('billingCity')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>

                        <div class="ft-proto-field ft-shipping-state-field">
                            <b>State @if(count($billingStates))<em>*</em>@endif</b>
                            <x-ui.search-select
                                label="State"
                                property="billingState"
                                :value="$billingState"
                                :placeholder="empty($billingStates) ? 'No states configured' : 'Select state'"
                                :options="$billingStates"
                                :disabled="empty($billingStates)"
                                search-placeholder="Search state…"
                                :menu-width="340"
                                :fixed-menu="true"
                                :hide-label="true"
                                class="ft-form-search-select"
                            />
                            @error('billingState')<small class="validation-error">{{ $message }}</small>@enderror
                        </div>

                        <label class="ft-proto-field ft-shipping-zip-field">
                            <b>ZIP / postal code <em>*</em></b>
                            <input wire:model="billingZip" placeholder="ZIP / postal code">
                            @error('billingZip')<small class="validation-error">{{ $message }}</small>@enderror
                        </label>
                    </div>

                    <div class="ft-shipping-prototype-line2" x-show="!showLine2" x-cloak>
                        <button type="button" x-on:click="showLine2 = true">
                            <span aria-hidden="true">+</span> Add address line 2
                        </button>
                    </div>
                </article>
            </section>

            @else
                <section class="ft-client-prototype-section ft-client-shipping-aligned-section" wire:key="create-client-addresses-placeholder">
                    <div class="ft-client-section-title ft-client-section-title-spread">
                        <div class="ft-section-title-left">
                            <span>3</span>
                            <div>
                                <h3>Shipping &amp; billing addresses</h3>
                                <p>Country and state options load only when you approach the address section.</p>
                            </div>
                        </div>
                    </div>
                    <x-ui.progressive-section-loader section="addresses" :rows="5" />
                </section>
            @endif

            <section class="ft-client-prototype-section">
                <div class="ft-client-section-title"><span>5</span><div><h3>Business &amp; billing preferences</h3></div></div>
                <div class="ft-business-preferences">
                    <label class="ft-proto-field"><b>EIN / Tax ID <span>(Optional)</span></b><input wire:model="einTaxId" placeholder="XX-XXXXXXX"></label>
                    <div class="ft-proto-field"><b>Sales tax status <em>*</em></b><div class="ft-tax-toggle"><button type="button" class="{{ $salesTaxStatus === 'taxable' ? 'active' : '' }}" wire:click="$set('salesTaxStatus','taxable')">Taxable</button><button type="button" class="{{ $salesTaxStatus === 'tax_exempt' ? 'active' : '' }}" wire:click="$set('salesTaxStatus','tax_exempt')">Tax exempt</button></div></div>
                    <label class="ft-proto-field"><b>Payment terms <span>(Optional)</span></b><select wire:model="paymentTerms"><option value="">Select terms</option>@foreach($paymentTermOptions as $term)<option value="{{ $term }}">{{ $term }}</option>@endforeach</select></label>
                </div>
                <div class="ft-po-row"><span>PO required <small>(Optional)</small></span><label class="ft-switch"><input type="checkbox" wire:model="poRequired"><i></i></label></div>
                <p class="ft-tax-certificate-note">Tax exemption certificates can be added from the client profile after creation.</p>
            </section>

            <section class="ft-client-prototype-section ft-notes-section">
                <div class="ft-client-section-title"><span>6</span><div><h3>Internal notes <small>(Optional)</small></h3></div></div>
                <label class="ft-proto-field"><textarea wire:model="notes" placeholder="Commercial preferences, account instructions or internal notes..."></textarea></label>
            </section>

            <footer class="ft-client-prototype-footer">
                <span>Required fields are marked with&nbsp; <em>*</em></span>
                <div>
                    @if($isEdit)
                        <button type="button" class="ft-create-cancel" wire:click="cancelEditClient">Cancel</button>
                        <button type="button" class="ft-create-primary" wire:click="updateClient" wire:loading.attr="disabled" wire:target="updateClient">Save Client</button>
                    @else
                        <button type="button" class="ft-create-cancel" wire:click="closeCreate">Cancel</button>
                        <button type="button" class="ft-client-save-draft" wire:click="saveClientDraft" wire:loading.attr="disabled" wire:target="saveClientDraft,createClient">Save as draft</button>
                        <button type="button" class="ft-create-primary" wire:click="createClient" wire:loading.attr="disabled" wire:target="saveClientDraft,createClient">Create client</button>
                    @endif
                </div>
            </footer>
        </section>
    </div>
</div>
