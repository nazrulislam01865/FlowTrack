@props([
    'selectedClient' => null,
    'savedDeliveryContacts' => collect(),
    'phoneCountryCodeOptions' => collect(),
    'shippingPhoneCountryCode' => '+1',
    'shippingPhone' => '',
    'shippingContactType' => 'end_customer',
    'shippingContactId' => null,
    'shippingContactSelection' => '',
    'shippingContactName' => '',
    'shippingSaveContact' => true,
])

@php
    $middleContacts = $selectedClient?->contacts ?? collect();
    $selectedMiddleContact = $shippingContactId
        ? $middleContacts->firstWhere('id', (int) $shippingContactId)
        : null;

    $manualSavedContacts = collect($savedDeliveryContacts)
        ->where('contact_type', $shippingContactType)
        ->values();
    $endCustomerSavedCount = collect($savedDeliveryContacts)->where('contact_type', 'end_customer')->count();
    $otherSavedCount = collect($savedDeliveryContacts)->where('contact_type', 'other_contact')->count();
    $currentPhone = trim(collect([$shippingPhoneCountryCode, $shippingPhone])->filter()->implode(' '));
    $matchedSavedContact = $manualSavedContacts->first(function ($contact) use ($shippingContactName, $shippingPhoneCountryCode, $shippingPhone): bool {
        return mb_strtolower(trim((string) $contact->name)) === mb_strtolower(trim($shippingContactName))
            && trim((string) ($contact->phone_country_code ?? '')) === trim($shippingPhoneCountryCode)
            && trim((string) $contact->phone) === trim($shippingPhone);
    });
    $contactOptions = $shippingContactType === 'middle_client'
        ? $middleContacts->map(function ($contact): array {
            $meta = collect([$contact->job_title, $contact->phone])->filter()->implode(' · ');
            return ['id' => (string) $contact->id, 'label' => (string) $contact->name, 'meta' => $meta];
        })->values()
        : $manualSavedContacts->map(function ($contact): array {
            $phone = trim(collect([$contact->phone_country_code, $contact->phone])->filter()->implode(' '));
            return ['id' => (string) $contact->id, 'label' => (string) $contact->name, 'meta' => $phone];
        })->values();
@endphp

<div
    class="ft-order-delivery-contact"
    data-ft-ui-component="order-delivery-contact"
    x-data="{
        contactType: @js((string) $shippingContactType),
        contactId: @js($shippingContactId),
        selection: @js((string) $shippingContactSelection),
        contactName: @js((string) $shippingContactName),
        countryCode: @js((string) $shippingPhoneCountryCode),
        phone: @js((string) $shippingPhone),
        saveContact: @js((bool) $shippingSaveContact),
        switching: false,
        selectedClientName: @js((string) ($selectedClient?->name ?? 'the selected client')),
        placeholderFor(type) {
            if (type === 'end_customer') return 'Enter end customer name';
            if (type === 'middle_client') return 'Search or enter client contact';
            return 'Enter contact name';
        },
        async switchContactType(type) {
            type = String(type || '');
            if (!type || this.switching || type === this.contactType) return;

            const previous = this.contactType;
            this.contactType = type;
            this.switching = true;

            try {
                const payload = await $wire.call('selectShippingContactType', type);
                this.applyPayload(payload);
            } catch (error) {
                this.contactType = previous;
                throw error;
            } finally {
                this.switching = false;
            }
        },
        applyPayload(payload) {
            if (!payload || typeof payload !== 'object') return;

            this.contactType = String(payload.type || this.contactType);
            this.contactId = payload.contactId ?? null;
            this.selection = String(payload.selection || '');
            this.contactName = String(payload.name || '');
            this.countryCode = String(payload.countryCode || '+1');
            this.phone = String(payload.phone || '');
            this.saveContact = Boolean(payload.saveContact);

            const detail = {
                ...payload,
                placeholder: this.placeholderFor(this.contactType),
            };

            this.$nextTick(() => {
                if (this.$el) {
                    this.$el.querySelectorAll('.validation-error').forEach((element) => element.remove());
                }
                window.dispatchEvent(new CustomEvent('flowtrack-shipping-contact-switched', { detail }));
                window.dispatchEvent(new CustomEvent('flowtrack-search-select-sync', {
                    detail: {
                        property: 'shippingPhoneCountryCode',
                        value: this.countryCode,
                        label: this.countryCode,
                    },
                }));
            });
        },
        get isCustomContact() {
            return String(this.selection || '').startsWith('custom:');
        },
        get isSavedSelection() {
            return /^\\d+$/.test(String(this.selection || ''));
        },
        get saveLabel() {
            if (this.contactType === 'middle_client') {
                if (this.isCustomContact) {
                    return `New contact will be saved to ${this.selectedClientName} for future orders`;
                }
                if (this.isSavedSelection && this.contactName) {
                    return `Save phone changes to ${this.contactName}'s contact profile`;
                }
                return 'Save this contact to the selected client for future orders';
            }

            return this.isCustomContact
                ? 'New contact will be saved for future orders'
                : 'Save this contact for future orders';
        },
        get badgeLabel() {
            if (!this.isSavedSelection) return '';
            return this.contactType === 'middle_client' ? 'Client contact' : 'Saved contact';
        },
        get contactTypeLabel() {
            if (this.contactType === 'end_customer') return 'end-customer';
            if (this.contactType === 'middle_client') return 'middle-client';
            return 'other';
        },
        get currentPhone() {
            return [this.countryCode, this.phone].map((value) => String(value || '').trim()).filter(Boolean).join(' ');
        },
    }"
    x-on:flowtrack-shipping-contact-payload.window="applyPayload($event.detail)"
    x-on:flowtrack-shipping-contact-name-input="contactName = String($event.detail?.name || '')"
    x-on:flowtrack-selection-changed="if ($event.detail?.property === 'shippingPhoneCountryCode') countryCode = String($event.detail?.value || '')"
>
    <div class="ft-order-delivery-contact-heading">
        <b>Delivery contact phone</b>
        <small>Choose whose phone number should be used for delivery coordination.</small>
    </div>

    <div class="ft-order-contact-source-tabs" role="radiogroup" aria-label="Delivery contact source">
        <button
            type="button"
            class="ft-order-contact-source"
            x-bind:class="{ 'is-active': contactType === 'end_customer' }"
            x-on:click="switchContactType('end_customer')"
            x-bind:aria-pressed="(contactType === 'end_customer').toString()"
            x-bind:disabled="switching"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4.5 20c.8-4 3.3-6 7.5-6s6.7 2 7.5 6"/></svg>
            <span>
                <strong>End customer</strong>
                <small>{{ $endCustomerSavedCount > 0 ? $endCustomerSavedCount.' saved' : 'Enter contact details' }}</small>
            </span>
        </button>

        <button
            type="button"
            class="ft-order-contact-source"
            x-bind:class="{ 'is-active': contactType === 'middle_client' }"
            x-on:click="switchContactType('middle_client')"
            x-bind:aria-pressed="(contactType === 'middle_client').toString()"
            x-bind:disabled="switching"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4.5 20c.8-4 3.3-6 7.5-6s6.7 2 7.5 6"/></svg>
            <span>
                <strong>Middle client</strong>
                <small>{{ $selectedClient?->name ?? 'Select client first' }}</small>
            </span>
        </button>

        <button
            type="button"
            class="ft-order-contact-source"
            x-bind:class="{ 'is-active': contactType === 'other_contact' }"
            x-on:click="switchContactType('other_contact')"
            x-bind:aria-pressed="(contactType === 'other_contact').toString()"
            x-bind:disabled="switching"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4.5 20c.8-4 3.3-6 7.5-6s6.7 2 7.5 6"/></svg>
            <span>
                <strong>Other contact</strong>
                <small>{{ $otherSavedCount > 0 ? $otherSavedCount.' saved' : 'Enter contact details' }}</small>
            </span>
        </button>
    </div>

    <div
        class="ft-order-contact-warning"
        role="status"
        x-cloak
        x-show="contactType === 'middle_client' && @js($middleContacts->isEmpty())"
    >
        <span aria-hidden="true">!</span>
        <p>No saved client contacts yet. Type a new contact person below and it will be saved to {{ $selectedClient?->name ?? 'the selected client' }} when the Order is created.</p>
    </div>

    <div class="ft-order-delivery-contact-grid">
        <div class="ft-create-field ft-order-contact-person-field">
            <b>Contact person *</b>
            <x-jobs.create.contact-person-combobox
                :type="$shippingContactType"
                :value="$shippingContactName"
                :selected-id="$shippingContactSelection"
                :options="$contactOptions"
                :placeholder="$shippingContactType === 'end_customer' ? 'Enter end customer name' : ($shippingContactType === 'middle_client' ? 'Search or enter client contact' : 'Enter contact name')"
                wire:key="create-order-contact-person-{{ $selectedClient?->id ?? 'none' }}"
            />
            @error('shippingContactId')<small class="validation-error">{{ $message }}</small>@enderror
            @error('shippingContactName')<small class="validation-error">{{ $message }}</small>@enderror
        </div>

        <div class="ft-create-field ft-order-country-code-field">
            <b>Country code *</b>
            <x-ui.search-select
                class="ft-order-delivery-phone-code-filter"
                label="Country code"
                property="shippingPhoneCountryCode"
                type="phone-country-codes"
                context="create-job"
                action="setCreateShippingPhoneCountryCode"
                :value="$shippingPhoneCountryCode"
                placeholder="+Code"
                :selected-label="$shippingPhoneCountryCode ?: null"
                :initial-options="$phoneCountryCodeOptions"
                :clearable="false"
                :hide-label="true"
                :fixed-menu="true"
                :menu-width="280"
                wire:key="create-order-delivery-phone-code"
            />
            @error('shippingPhoneCountryCode')<small class="validation-error">{{ $message }}</small>@enderror
        </div>

        <label class="ft-create-field ft-order-delivery-phone-field">
            <b>Phone number *</b>
            <input wire:model="shippingPhone" x-model="phone" inputmode="tel" autocomplete="tel" placeholder="Enter phone number">
            @error('shippingPhone')<small class="validation-error">{{ $message }}</small>@enderror
        </label>
    </div>

    <div class="ft-order-contact-save-row">
        <label class="ft-order-contact-save-check" for="create-order-save-contact-fast">
            <input
                id="create-order-save-contact-fast"
                type="checkbox"
                wire:model="shippingSaveContact"
                x-model="saveContact"
                x-bind:disabled="isCustomContact"
            >
            <span x-text="saveLabel"></span>
        </label>

        <span class="ft-order-saved-contact-badge" x-cloak x-show="badgeLabel !== ''">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5.5 10.2 2.8 2.8 6.2-6.2"/></svg>
            <span x-text="badgeLabel"></span>
        </span>
    </div>

    <div class="ft-order-contact-success" role="status" x-cloak x-show="contactName !== '' && currentPhone !== ''">
        <span aria-hidden="true">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m5.5 10.2 2.8 2.8 6.2-6.2"/></svg>
        </span>
        <p>Using <span x-text="contactTypeLabel"></span> contact: <strong x-text="contactName"></strong> · <span x-text="currentPhone"></span></p>
    </div>

    <label class="ft-create-field ft-order-postal-row" for="create-order-postal-code">
        <b>Postal Code *</b>
        <input id="create-order-postal-code" wire:model="shippingPostalCode" autocomplete="postal-code" required aria-required="true" placeholder="Enter postal code">
        @error('shippingPostalCode')<small class="validation-error">{{ $message }}</small>@enderror
    </label>
</div>
