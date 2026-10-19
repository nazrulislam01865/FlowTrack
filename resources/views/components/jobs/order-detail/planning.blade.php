@props(['job', 'canEditJob' => false, 'canChangeOwner' => false, 'shipmentUrgencyOptions' => collect(), 'context' => [], 'remoteArea' => null])
@php
    // Remote Area is resolved once in OrderDetailViewService. Keep this Blade
    // component presentation-only so moving the flag here never adds queries.
    $remoteArea = is_array($remoteArea) && ! empty($remoteArea['postal_code']) ? $remoteArea : null;
    $isOnHold = (bool) ($context['isOnHold'] ?? false);
    $hold = is_array($context['hold'] ?? null) ? $context['hold'] : null;
    $canHold = (bool) ($context['canHold'] ?? false);
    $canReleaseHold = (bool) ($context['canReleaseHold'] ?? false);
@endphp
<section class="section-card ft-order-section-card ft-order-planning-card">
    <div class="section-head ft-order-section-head"><h2>Planning &amp; ownership</h2><span class="card-sub">Quick edits</span></div>
    <div class="section-body info-list ft-order-info-list">
        @if($remoteArea)
            <div class="info-row ft-order-info-row ft-order-remote-area-row">
                <span>Remote area</span>
                <b>
                    <span
                        class="pill amber ft-order-remote-area-pill"
                        title="{{ trim((string) ($remoteArea['name'] ?? 'Remote Area')) }} · {{ $remoteArea['postal_code'] }}"
                        aria-label="Remote Area postal code match"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4m0 1h10l-1.5 3L15 11H5"></path></svg>
                        Remote Area
                    </span>
                </b>
            </div>
        @endif
        <div class="info-row ft-order-info-row ft-inline-edit-shell"
            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('job-'.$job->id.'-delivery-date'), label: 'Hand Date', value: @js($job->delivery_date?->format('Y-m-d') ?? ''), display: @js($job->delivery_date?->format('M j, Y') ?? 'Not set') })">
            <span>Hand Date</span>
            <b><span x-show="!editing" x-text="display">{{ $job->delivery_date?->format('M j, Y') ?? 'Not set' }}</span>
                @if($canEditJob)
                    <button x-show="!editing" type="button" class="inline-edit" x-on:click.stop="if(beginEdit()) $nextTick(() => $refs.delivery.focus())">✎</button>
                    <input x-ref="delivery" x-cloak x-show="editing" type="date" x-model="draftValue" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit(draftValue, draftValue ? new Date(draftValue+'T12:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}) : 'Not set', () => $wire.updateJobDeliveryDate({{ $job->id }}, draftValue))">
                    <x-ui.inline-save-state compact />
                @endif
            </b>
        </div>

        <div class="info-row ft-order-info-row ft-order-hold-row">
            <span>Hold order</span>
            <b>
                @if($isOnHold)
                    <span class="ft-order-hold-status-button" aria-label="Order is on hold">
                        <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                        <span>On Hold</span>
                    </span>
                @elseif($canHold)
                    <button type="button" class="ft-order-hold-action-button" wire:click="openOrderHoldModal" wire:loading.attr="disabled" wire:target="openOrderHoldModal" data-ft-feedback="off">
                        <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                        <span>Hold order</span>
                    </button>
                @else
                    <span class="ft-order-hold-static-state">Not on hold</span>
                @endif
            </b>
        </div>

        @if($isOnHold && $hold)
            <x-jobs.order-detail.hold-status-card :hold="$hold" :can-release-hold="$canReleaseHold" />
        @endif

        <div class="info-row ft-order-info-row"><span>Reference number</span><b>{{ $job->order_number ?: '—' }}</b></div>
        <div id="order-shipment-urgency" class="info-row ft-order-info-row ft-order-urgency-info-row">
            <span><span class="help" title="Shipment urgency determines operational prioritization for packing, carrier booking, and dispatch.">Shipment urgency</span></span>
            <b>
                <x-jobs.order-detail.shipment-urgency-inline
                    :job="$job"
                    :can-edit-job="$canEditJob"
                    :shipment-urgency-options="$shipmentUrgencyOptions"
                    :context="$context"
                    variant="planning"
                />
            </b>
        </div>
        <div id="order-owner-field" class="info-row ft-order-info-row ft-inline-edit-shell ft-order-owner-inline-field"
            x-data="{
                ...window.FlowTrack.ui.inlineEdit({ key: @js('job-'.$job->id.'-owner'), label: 'Order owner', value: @js($job->owner_id ?? ''), display: @js($job->owner?->name ?? 'Unassigned'), avatarUrl: @js($job->owner?->profileImageUrl() ?? '') }),
                syncOwner(detail) {
                    if (!detail || Number(detail.jobId) !== Number({{ $job->id }})) return;
                    const nextValue = String(detail.value ?? '');
                    const nextDisplay = String(detail.label ?? 'Unassigned');
                    const nextAvatarUrl = String(detail.avatarUrl ?? '');
                    const fromSelf = String(detail.sourceKey ?? '') === this.key;

                    this.serverValue = nextValue;
                    this.value = nextValue;
                    this.savedValue = nextValue;
                    this.draftValue = nextValue;
                    this.display = nextDisplay;
                    this.savedDisplay = nextDisplay;
                    this.avatarUrl = nextAvatarUrl;
                    this.savedAvatarUrl = nextAvatarUrl;
                    this.editing = false;

                    if (!fromSelf && this.status !== 'saving') {
                        this.status = '';
                        this.error = '';
                    }
                },
                async saveOwner(detail) {
                    const nextValue = String(detail?.value ?? '');
                    const nextDisplay = String(detail?.label ?? 'Unassigned');
                    const nextAvatarUrl = String(detail?.avatarUrl ?? '');
                    const ok = await this.commit(
                        nextValue,
                        nextDisplay,
                        () => $wire.updateJobOwner({{ $job->id }}, nextValue),
                        { avatarUrl: nextAvatarUrl }
                    );

                    if (!ok) return;

                    const payload = {
                        jobId: {{ $job->id }},
                        value: String(this.value ?? ''),
                        label: String(this.display ?? 'Unassigned'),
                        avatarUrl: String(this.lastResponse?.avatarUrl ?? this.avatarUrl ?? ''),
                        sourceKey: this.key,
                    };
                    this.syncOwner(payload);
                    window.dispatchEvent(new CustomEvent('ft-order-owner-updated', { detail: payload }));
                }
            }"
            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
            x-on:click.outside="if(editing) cancelEdit()"
            x-on:ft-inline-remote-cancel.stop="cancelEdit()"
            x-on:ft-inline-remote-selected.stop="saveOwner($event.detail)"
            x-on:ft-order-owner-updated.window="syncOwner($event.detail)">
            <span>Order owner</span>
            <b>
                <span class="ft-order-inline-display-row ft-order-owner-display-row">
                    @if($canChangeOwner)
                        <button
                            x-ref="ownerAnchor"
                            :disabled="status === 'saving'"
                            type="button"
                            class="ft-order-assignee-display ft-order-owner-display ft-order-inline-name-trigger"
                            :class="{ 'is-open': editing }"
                            title="Edit order owner"
                            aria-label="Edit order owner"
                            x-on:click.stop="openRemotePicker($refs.ownerAnchor)"
                        >
                            <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="28" /></span>
                            <span class="ft-order-assignee-name" x-text="display">{{ $job->owner?->name ?? 'Unassigned' }}</span>
                            <span class="ft-order-inline-trigger-icon" aria-hidden="true">✎</span>
                        </button>
                    @else
                        <span class="ft-order-assignee-display ft-order-owner-display">
                            <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="28" /></span>
                            <span class="ft-order-assignee-name" x-text="display">{{ $job->owner?->name ?? 'Unassigned' }}</span>
                        </span>
                    @endif
                </span>
                @if($canChangeOwner)
                    <span x-cloak x-show="editing" class="ft-order-owner-picker"><x-ui.inline-remote-user :value="$job->owner_id ?? ''" :selected-label="$job->owner?->name ?? 'Unassigned'" context="job-owner" instance-key="order-planning-owner" parent-type="job" :parent-id="$job->id" search-placeholder="Search owner..." variant="compact" :menu-width="300" external-trigger /></span>
                    <x-ui.inline-save-state compact />
                @endif
            </b>
        </div>
        <div class="info-row ft-order-info-row"><span>Workflow</span><b>{{ $context['workflowName'] ?? ($job->workflow?->name ?: 'FlowTrack Order Workflow') }}</b></div>
    </div>
</section>
