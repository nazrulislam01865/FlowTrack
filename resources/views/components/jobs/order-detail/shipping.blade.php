@props(['job', 'canEditJob' => false])
@php
    $address = trim((string) ($job->shipping_address ?? ''));
    $phoneCode = trim((string) ($job->shipping_phone_country_code ?? ''));
    $phone = trim((string) ($job->shipping_phone ?? ''));
    $phoneDisplay = trim($phoneCode.' '.$phone);
    $postal = trim((string) ($job->shipping_postal_code ?? ''));
@endphp
<section
    class="section-card ft-order-section-card ft-order-shipping-card"
    x-data="{
        editOpen:false,
        saving:false,
        address:@js($address),
        phoneDisplay:@js($phoneDisplay),
        postal:@js($postal),
        errors:{address:'',phone:'',postal:'',form:''},
        clearErrors(){
            this.errors={address:'',phone:'',postal:'',form:''};
        },
        async saveShipping(){
            this.saving=true;
            this.clearErrors();
            try {
                const full=String(this.phoneDisplay||'').trim();
                const match=full.match(/^(\+[0-9]{1,4})\s*(.*)$/);
                const code=match ? match[1] : @js($phoneCode);
                const phone=match ? match[2] : full;
                const result=await $wire.updateJobShippingDetails({{ $job->id }}, String(this.address||'').trim(), String(code||'').trim(), String(phone||'').trim(), String(this.postal||'').trim());
                if(result?.ok===false){
                    const serverErrors=result?.errors || {};
                    this.errors.address=serverErrors.shipping_address?.[0] || '';
                    this.errors.phone=serverErrors.shipping_phone_country_code?.[0] || serverErrors.shipping_phone?.[0] || '';
                    this.errors.postal=serverErrors.shipping_postal_code?.[0] || '';
                    if(!this.errors.address && !this.errors.phone && !this.errors.postal){
                        this.errors.form=result?.message || 'Could not save shipping details.';
                    }
                    return;
                }
                this.editOpen=false;
                await $wire.$refresh();
            } finally { this.saving=false; }
        }
    }"
>
    <div class="section-head ft-order-section-head"><h2>Shipping address</h2>@if($canEditJob)<button type="button" class="btn small" x-on:click="clearErrors(); editOpen=true">✎</button>@endif</div>
    <div class="section-body info-list ft-order-info-list">
        <div class="info-row ft-order-info-row"><span>Delivery address</span><b class="preline">{{ $address ?: 'Not set' }}</b></div>
        <div class="info-row ft-order-info-row"><span>Phone number</span><b>{{ $phoneDisplay ?: 'Not set' }}</b></div>
        <div class="info-row ft-order-info-row"><span>Postal code</span><b>{{ $postal ?: 'Not set' }}</b></div>
    </div>

    @if($canEditJob)
        <div class="modal-wrap show" x-cloak x-show="editOpen" x-on:click.self="editOpen=false">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="prototype-edit-shipping-title">
                <div class="modal-head"><h2 id="prototype-edit-shipping-title">Edit Shipping Address</h2><button type="button" class="close" x-on:click="editOpen=false">✕</button></div>
                <div class="modal-body">
                    <div class="field"><label>Delivery address</label><textarea x-model="address" rows="5" x-on:input="errors.address=''"></textarea><p class="validation-error" x-cloak x-show="errors.address" x-text="errors.address"></p></div>
                    <div class="form-grid">
                        <div class="field"><label>Phone</label><input x-model="phoneDisplay" x-on:input="errors.phone=''"><p class="validation-error" x-cloak x-show="errors.phone" x-text="errors.phone"></p></div>
                        <div class="field"><label>Postal code</label><input x-model="postal" maxlength="30" x-on:input="errors.postal=''"><p class="validation-error" x-cloak x-show="errors.postal" x-text="errors.postal"></p></div>
                    </div>
                    <p class="validation-error ft-order-popup-form-error" x-cloak x-show="errors.form" x-text="errors.form"></p>
                </div>
                <div class="modal-foot"><button type="button" class="btn" x-on:click="editOpen=false">Cancel</button><button type="button" class="btn primary" :disabled="saving" data-ft-feedback="off" x-on:click="saveShipping()"><span x-show="!saving">Save Changes</span><span x-show="saving">Saving…</span></button></div>
            </div>
        </div>
    @endif
</section>
