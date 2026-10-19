@props(['job', 'payload' => []])

<div class="ft-shipment-modal-form">
    <div class="ft-shipment-modal-notice">
        <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 9v4M10 6.5h.01"/></svg>
        <p>These changes apply to this shipment. The original order and client profile will not change unless you choose to update the saved contact.</p>
    </div>

    <section class="ft-shipment-modal-section">
        <h3>Recipient</h3>
        <div class="ft-shipment-modal-grid ft-shipment-modal-grid--two">
            <label class="ft-shipment-modal-field">
                <span>Client name <b>*</b></span>
                <input wire:model="orderWorkflowActionPayload.client_name">
                @error('orderWorkflowActionPayload.client_name')<small class="validation-error">{{ $message }}</small>@enderror
            </label>
            <label class="ft-shipment-modal-field">
                <span>Contact person <b>*</b></span>
                <select wire:model="orderWorkflowActionPayload.contact_selection" wire:change="selectShipmentContact($event.target.value)">
                    @if(empty($payload['contact_options'] ?? []))
                        <option value="current">{{ trim((string) ($payload['contact_name'] ?? $payload['recipient'] ?? '')) ?: 'Current shipment contact' }}</option>
                    @else
                        @foreach(($payload['contact_options'] ?? []) as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    @endif
                </select>
                @error('orderWorkflowActionPayload.contact_name')<small class="validation-error">{{ $message }}</small>@enderror
            </label>
        </div>

        <div class="ft-shipment-modal-grid ft-shipment-modal-grid--phone">
            <label class="ft-shipment-modal-field">
                <span>Phone source <b>*</b></span>
                <select wire:model="orderWorkflowActionPayload.contact_type">
                    <option value="middle_client">Middle client contact</option>
                    <option value="end_customer">End customer</option>
                    <option value="other_contact">Other contact</option>
                </select>
            </label>
            <div class="ft-shipment-modal-field">
                <span>Country code <b>*</b></span>
                <x-ui.search-select
                    class="ft-shipment-modal-search-select"
                    label="Country code"
                    property="orderWorkflowActionPayload.phone_country_code"
                    :value="$payload['phone_country_code'] ?? ''"
                    :options="$payload['phone_country_code_options'] ?? []"
                    placeholder="+Code"
                    :selected-label="($payload['phone_country_code'] ?? '') ?: null"
                    :clearable="false"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="280"
                    search-placeholder="Search country code…"
                    wire:key="shipment-phone-country-code-{{ $job->id }}-{{ $payload['phone_country_code'] ?? 'none' }}"
                />
                @error('orderWorkflowActionPayload.phone_country_code')<small class="validation-error">{{ $message }}</small>@enderror
            </div>
            <label class="ft-shipment-modal-field">
                <span>Phone number <b>*</b></span>
                <input wire:model="orderWorkflowActionPayload.phone_number" placeholder="1712 345678">
                @error('orderWorkflowActionPayload.phone_number')<small class="validation-error">{{ $message }}</small>@enderror
            </label>
        </div>
        <div class="ft-shipment-saved-indicator">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m6.5 10 2.2 2.2 4.8-4.8"/></svg>
            Saved contact
        </div>
    </section>

    <section class="ft-shipment-modal-section ft-shipment-modal-section--address">
        <div class="ft-shipment-modal-section__title">
            <h3>Delivery address</h3>
            <button type="button" wire:click="useShipmentSavedAddress('{{ (string) ($payload['address_selection'] ?? '') }}')">Use saved address</button>
        </div>
        <label class="ft-shipment-modal-field">
            <span>Shipping address <b>*</b></span>
            <textarea rows="3" wire:model="orderWorkflowActionPayload.address"></textarea>
            @error('orderWorkflowActionPayload.address')<small class="validation-error">{{ $message }}</small>@enderror
        </label>
        <div class="ft-shipment-modal-grid ft-shipment-modal-grid--two">
            <label class="ft-shipment-modal-field"><span>City</span><input wire:model="orderWorkflowActionPayload.city"></label>
            <label class="ft-shipment-modal-field"><span>State / region</span><input wire:model="orderWorkflowActionPayload.state"></label>
            <div class="ft-shipment-modal-field">
                <span>Country <b>*</b></span>
                <x-ui.search-select
                    class="ft-shipment-modal-search-select"
                    label="Country"
                    property="orderWorkflowActionPayload.country"
                    :value="$payload['country'] ?? ''"
                    :options="$payload['country_options'] ?? []"
                    placeholder="Select country"
                    :selected-label="($payload['country'] ?? '') ?: null"
                    :clearable="false"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="320"
                    search-placeholder="Search country…"
                    wire:key="shipment-country-{{ $job->id }}-{{ \Illuminate\Support\Str::slug((string) ($payload['country'] ?? 'none')) }}"
                />
                @error('orderWorkflowActionPayload.country')<small class="validation-error">{{ $message }}</small>@enderror
            </div>
            <label class="ft-shipment-modal-field"><span>Postal code <b>*</b></span><input wire:model="orderWorkflowActionPayload.postal_code">@error('orderWorkflowActionPayload.postal_code')<small class="validation-error">{{ $message }}</small>@enderror</label>
        </div>
        <label class="ft-shipment-update-contact-check">
            <input type="checkbox" wire:model="orderWorkflowActionPayload.update_saved_contact">
            <span>Also update {{ trim((string) ($payload['contact_name'] ?? $payload['recipient'] ?? '')) ?: 'this contact' }}'s saved contact information.<small>Leave unchecked to use these changes for this shipment only.</small></span>
        </label>
        <div class="ft-shipment-contact-summary">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="7" r="3"/><path d="M4.5 16c.7-3 2.5-4.5 5.5-4.5s4.8 1.5 5.5 4.5"/></svg>
            <span>Shipment contact: <strong>{{ trim((string) ($payload['contact_name'] ?? $payload['recipient'] ?? '')) ?: '—' }}</strong> · {{ trim((string) (($payload['phone_country_code'] ?? '').' '.($payload['phone_number'] ?? ''))) ?: '—' }}</span>
        </div>
    </section>
</div>
