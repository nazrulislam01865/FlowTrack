@props(['job', 'context' => [], 'shipmentUrgencyOptions' => collect(), 'redoContext' => []])
@php
    $team = collect($context['team'] ?? []);
    $canEditJob = (bool) ($context['canEditJob'] ?? false);
    $canChangeOwner = (bool) ($context['canChangeOwner'] ?? false);
    $canCancel = (bool) ($context['canCancel'] ?? false);
    $attentionLocked = (bool) ($context['attentionLocked'] ?? false);
    $flagged = (bool) ($context['flagged'] ?? false);
    $flagReason = trim((string) ($context['flagReason'] ?? ''));
    $isCancelled = strcasecmp((string) $job->status, 'Cancelled') === 0;
    $stageName = $isCancelled ? ($job->phase?->name ?: 'Cancelled') : ($job->phase?->name ?: 'New Order');
    $hasRedo = (bool) ($redoContext['hasRedo'] ?? false);
    $isRedoOrder = (bool) ($redoContext['isRedoOrder'] ?? false);
    $redoOrderCount = (int) ($redoContext['redoOrderCount'] ?? 0);

    // Show this only when an operational Redo Order actually exists.
    // A discount-only resolution creates a Redo record but no Redo Order,
    // so it must not be labelled as "Redo initiated".
    $redoInitiated = $isRedoOrder || $redoOrderCount > 0;

    $canInitiateRedo = (bool) ($redoContext['canInitiate'] ?? false);
    $isOnHold = (bool) ($context['isOnHold'] ?? false);
@endphp
<section class="detail-header ft-order-prototype-header">
    <div class="breadcrumbs ft-order-prototype-breadcrumb">
        Orders &nbsp;/&nbsp;
        <b>{{ $job->displayOrderNumber() }}</b>
        <button type="button" class="copy-order-code" title="Copy Order ID" aria-label="Copy {{ $job->displayOrderNumber() }}" data-copy-value="{{ $job->displayOrderNumber() }}">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="8" y="8" width="10" height="10" rx="1.5"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path></svg>
        </button>
    </div>

    <div class="detail-title-row ft-order-prototype-title-row">
        <div class="detail-heading" style="flex:1">
            <h1 class="detail-title ft-order-prototype-title">{{ $job->title }}</h1>

            <div class="meta-line ft-order-prototype-meta" aria-label="Order information">
                <span class="ft-order-header-meta-item">
                    <span class="ft-order-header-meta-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.4 3-5.2 6.5-5.2s5.7 1.8 6.5 5.2"></path></svg>
                    </span>
                    <span class="ft-client-inline-identity">
                        <x-ui.client-logo :client="$job->client" :name="$job->client?->name ?: 'Client'" :size="20" />
                        <span>Client <strong>{{ $job->client?->name ?: '—' }}</strong></span>
                    </span>
                </span>
                <span class="meta-sep" aria-hidden="true">•</span>
                <span class="ft-order-header-meta-item ft-order-header-reference">
                    <span class="ft-order-header-meta-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M7 3.5h7l4 4V20.5H7z"></path><path d="M14 3.5v4h4"></path></svg>
                    </span>
                    <span>Reference <strong>{{ $job->order_number ?: '—' }}</strong></span>
                    @if($job->order_number)
                        <button type="button" class="ft-order-header-copy" title="Copy Reference Number" aria-label="Copy reference number {{ $job->order_number }}" data-copy-value="{{ $job->order_number }}">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="8" y="8" width="10" height="10" rx="1.5"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                    @endif
                </span>
                <span class="meta-sep" aria-hidden="true">•</span>
                <span class="ft-order-header-meta-item">
                    <span class="ft-order-header-meta-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.4 3-5.2 6.5-5.2s5.7 1.8 6.5 5.2"></path></svg>
                    </span>
                    <span>Created by <strong>{{ $job->creator?->name ?: 'System' }}</strong></span>
                </span>
                <span class="meta-sep" aria-hidden="true">•</span>
                <span class="ft-order-header-meta-item">
                    <span class="ft-order-header-meta-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5.5" width="16" height="14" rx="2"></rect><path d="M8 3.5v4M16 3.5v4M4 10h16"></path></svg>
                    </span>
                    <span>Created <strong>{{ $job->created_at ? \App\Support\UserLocalTime::format($job->created_at, 'M j, Y') : '—' }}@if($job->created_at) at {{ \App\Support\UserLocalTime::format($job->created_at, 'g:i A') }}@endif</strong></span>
                </span>
            </div>

            <div class="pills ft-order-prototype-pills">
                @if($redoInitiated)
                    <span
                        class="pill redo-initiated"
                        title="A linked Redo Order has been initiated."
                    >
                        Redo initiated
                    </span>
                @endif

                @if($isCancelled)
                    <span class="pill cancelled">⊘ Cancelled</span>
                    <span class="pill purple" id="stagePill" title="The last workflow stage reached before cancellation.">Last stage · {{ $stageName }}</span>
                @else
                    <span class="pill purple" id="stagePill" title="The workflow stage containing the current required task.">{{ $stageName }}</span>
                @endif

                @if($isOnHold && !$isCancelled)
                    <span class="pill ft-order-hold-header-pill" title="This Order is currently on hold.">
                        <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                        On Hold
                    </span>
                @endif

                @if($isRedoOrder)
                    <span class="pill redo">↻ Redo order</span>
                @endif
            </div>

        </div>

        <div class="ft-order-header-side">
            <div class="people ft-order-team-stack" title="Team members currently involved in this order">
                @foreach($team->take(4) as $member)
                    @php($initials = collect(preg_split('/\s+/', trim((string) $member->name)))->filter()->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode(''))
                    <i title="{{ $member->name }}">{{ $initials ?: '—' }}</i>
                @endforeach
                @if($team->count() > 4)<i>+{{ $team->count() - 4 }}</i>@endif
            </div>

        </div>
    </div>

            <div class="order-commandbar ft-order-commandbar">
                <x-jobs.order-detail.shipment-urgency-inline
                    :job="$job"
                    :can-edit-job="$canEditJob"
                    :shipment-urgency-options="$shipmentUrgencyOptions"
                    :context="$context"
                    variant="header"
                />

                <div class="header-owner-control ft-inline-edit-shell"
                    x-data="{
                        ...window.FlowTrack.ui.inlineEdit({ key: @js('job-'.$job->id.'-header-owner'), label: 'Order owner', value: @js($job->owner_id ?? ''), display: @js($job->owner?->name ?? 'Unassigned'), avatarUrl: @js($job->owner?->profileImageUrl() ?? '') }),
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
                    x-on:click.outside="if(editing) cancelEdit()"
                    x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                    x-on:ft-inline-remote-selected.stop="saveOwner($event.detail)"
                    x-on:ft-order-owner-updated.window="syncOwner($event.detail)"
                >
                    @if($canChangeOwner)
                        <button
                            x-ref="headerOwnerAnchor"
                            type="button"
                            class="assignee-stage-chip ft-order-owner-chip-trigger"
                            :class="{ 'is-open': editing }"
                            x-on:click.stop="openRemotePicker($refs.headerOwnerAnchor)"
                            title="Edit order owner"
                            aria-label="Edit order owner"
                        >
                            <x-ui.inline-live-avatar :size="22" />
                            <span x-text="display">{{ $job->owner?->name ?: 'Unassigned' }}</span>
                            <span class="ft-order-inline-trigger-icon" aria-hidden="true">✎</span>
                        </button>
                        <div class="header-owner-picker" x-cloak x-show="editing">
                            <x-ui.inline-remote-user :value="$job->owner_id ?? ''" :selected-label="$job->owner?->name ?? 'Unassigned'" context="job-owner" instance-key="order-header-owner" parent-type="job" :parent-id="$job->id" search-placeholder="Search owner..." variant="compact" :menu-width="320" external-trigger />
                        </div>
                        <x-ui.inline-save-state compact />
                    @else
                        <span class="assignee-stage-chip"><x-ui.inline-live-avatar :size="22" /><span x-text="display">{{ $job->owner?->name ?: 'Unassigned' }}</span></span>
                    @endif
                </div>

                <div class="ft-order-header-actions" aria-label="Order actions">
                    @if($canInitiateRedo)
                        <button type="button" class="btn redo small" wire:click="openRedoModal" title="Create a controlled redo linked to this order.">↻ Initiate Redo</button>
                    @endif

                    @if(!$isCancelled)
                        <button type="button" class="btn small flag-btn {{ $flagged ? 'flagged' : '' }}" wire:click="openOrderAttentionReason" @disabled($attentionLocked) title="{{ $flagReason ?: 'Flag this order so it remains visibly marked for attention across every stage.' }}">⚑ {{ $flagged ? 'Flagged' : 'Flag order' }}</button>
                        <button type="button" class="btn danger small" wire:click="openOrderCancelModal" @disabled(!$canCancel) title="{{ $canCancel ? 'Cancel this order. Cancellation is available only through the QC stage.' : 'Cancellation is available only through the QC stage.' }}">⊘ Cancel order</button>
                    @else
                        <span class="ft-order-workflow-lock" title="Workflow actions are blocked because this order is cancelled.">⊘ Workflow locked</span>
                    @endif
                </div>
            </div>
</section>

@if($isCancelled)
    <x-jobs.order-detail.cancellation-card :job="$job" :stage-name="$stageName" />
@endif

@if($flagged)
    <div class="state-banner flag show ft-order-state-banner"><span>⚑</span><div><b>Flagged for attention</b><p>{{ $flagReason ?: 'This order requires attention.' }}</p></div></div>
@endif
