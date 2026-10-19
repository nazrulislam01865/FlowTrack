@props(['tracking'])
@php
    $variant = $tracking['screen_variant'] ?? 'new-order';
    $status = $tracking['current_status'];
    $next = $tracking['next'];
    $timeline = $tracking['timeline'] ?? [];
    $shipment = $tracking['shipment'] ?? [];
    $paymentDetail = $tracking['payment_detail'] ?? null;
    $stageVariant = $variant === 'artwork' ? 'detailed' : 'compact';
@endphp

<div class="ft-tracking-stage-screen is-{{ $variant }}">
    <section class="ft-tracking-result-card" aria-labelledby="tracking-result-title">
        <header class="ft-tracking-result-head">
            <div class="ft-tracking-result-title-row">
                <h2 id="tracking-result-title">Order {{ $tracking['order_number'] }}</h2>
                @if($tracking['sample_order'])
                    <span class="ft-tracking-sample-badge">Sample order</span>
                @endif
            </div>
            <p>
                Reference: {{ $tracking['reference_number'] !== '' ? $tracking['reference_number'] : '—' }}
                <b aria-hidden="true">|</b>
                Last updated: {{ $tracking['last_updated'] }}
            </p>
        </header>

        @if($variant === 'artwork')
            <div class="ft-tracking-status-callout is-{{ $status['tone'] }} {{ ($status['action'] ?? null) === 'review-artwork' ? '' : 'is-passive' }}">
                <span class="ft-tracking-callout-icon"><x-order-tracking.icon :name="$status['icon']" :size="26" /></span>
                <div class="ft-tracking-callout-copy">
                    <strong>{{ $status['title'] }}</strong>
                    <p>{{ $status['message'] }}</p>
                </div>
                @if(($status['action'] ?? null) === 'review-artwork')
                    <div class="ft-tracking-callout-action-copy">Your approval is needed</div>
                    <x-ui.button href="{{ route('login') }}" class="ft-tracking-action-button">
                        Sign in to review artwork
                    </x-ui.button>
                @endif
            </div>
        @else
            <x-order-tracking.overview :status="$status" :summary="$tracking['summary']" />
        @endif

        <div class="ft-tracking-stages" aria-label="Order stages">
            @foreach($tracking['stages'] as $stage)
                <x-order-tracking.stage-card :stage="$stage" :variant="$stageVariant" />
            @endforeach
        </div>

        @switch($variant)
            @case('new-order')
                <div class="ft-tracking-new-order-grid">
                    <section class="ft-tracking-status-card">
                        <header>
                            <h3>Order status</h3>
                            <p>Live updates for your order.</p>
                        </header>
                        <div class="ft-tracking-current-status-row">
                            <span class="ft-tracking-current-marker" aria-hidden="true"></span>
                            <div>
                                <strong>{{ $status['title'] }}</strong>
                                <p>{{ $status['message'] }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="ft-tracking-next-card">
                        <h3>What happens next?</h3>
                        <div class="ft-tracking-next-message">
                            <span class="ft-tracking-next-icon"><x-order-tracking.icon name="document" :size="31" /></span>
                            <p>{{ $next['message'] }}</p>
                        </div>
                    </section>
                </div>
                @break

            @case('artwork')
                <div class="ft-tracking-artwork-grid">
                    <x-order-tracking.progress-list
                        title="Order progress"
                        subtitle="Track the progress of your order."
                        :items="$timeline"
                        tone="purple"
                    />

                    <div class="ft-tracking-artwork-side">
                        <section class="ft-tracking-next-card ft-tracking-next-card--artwork">
                            <h3>What happens next?</h3>
                            @if(($next['action'] ?? null) === 'review-artwork')
                                <p class="ft-tracking-next-intro">{{ $next['intro'] }}</p>
                                <div class="ft-tracking-next-action-box is-warning">
                                    <span class="ft-tracking-next-alert"><x-order-tracking.icon name="alert" :size="25" /></span>
                                    <div>
                                        <strong>{{ $next['title'] }}</strong>
                                        <p>{{ $next['message'] }}</p>
                                    </div>
                                    <x-ui.button href="{{ route('login') }}" class="ft-tracking-action-button">
                                        {{ $next['action_label'] }}
                                    </x-ui.button>
                                </div>
                            @else
                                <div class="ft-tracking-next-message ft-tracking-next-message--artwork">
                                    <span class="ft-tracking-next-icon"><x-order-tracking.icon :name="$status['icon']" :size="31" /></span>
                                    <p>{{ $next['message'] }}</p>
                                </div>
                            @endif
                        </section>

                        <section class="ft-tracking-delivery-card">
                            <header>
                                <h3>Delivery, billing and payment</h3>
                                <p>These steps will be completed after production.</p>
                            </header>
                            <div class="ft-tracking-summary ft-tracking-summary--workflow">
                                <x-order-tracking.summary-item title="Delivery" :data="$tracking['summary']['delivery']" icon="truck" />
                                <x-order-tracking.summary-item title="Billing" :data="$tracking['summary']['billing']" icon="billing" />
                                <x-order-tracking.summary-item title="Payment" :data="$tracking['summary']['payment']" icon="payment" />
                            </div>
                        </section>
                    </div>
                </div>
                @break

            @case('production')
                <div class="ft-tracking-production-grid">
                    <x-order-tracking.progress-list
                        title="Order progress"
                        subtitle="See the latest status of your order."
                        :items="$timeline"
                        tone="orange"
                    />

                    <section class="ft-tracking-next-card ft-tracking-next-card--production">
                        <h3>What happens next?</h3>
                        <p class="ft-tracking-next-intro">{{ $next['intro'] }}</p>
                        <div class="ft-tracking-next-step-box">
                            <span class="ft-tracking-next-step-icon"><x-order-tracking.icon name="search" :size="29" /></span>
                            <div>
                                <strong>{{ $next['title'] }}</strong>
                                <p>{{ $next['message'] }}</p>
                            </div>
                        </div>
                        <div class="ft-tracking-info-box">
                            <span><x-order-tracking.icon name="info" :size="25" /></span>
                            <div>
                                <strong>{{ $next['note_title'] }}</strong>
                                <p>{{ $next['note_message'] }}</p>
                            </div>
                        </div>
                    </section>
                </div>
                @break

            @case('qc')
                <div class="ft-tracking-qc-grid">
                    <x-order-tracking.progress-list
                        title="Order updates"
                        subtitle="Live updates for your order."
                        :items="$timeline"
                        tone="teal"
                        class="ft-tracking-qc-progress"
                    />

                    <section class="ft-tracking-next-card ft-tracking-next-card--qc">
                        <header class="ft-tracking-inline-heading">
                            <span class="ft-tracking-card-heading-icon is-info"><x-order-tracking.icon name="info" :size="24" /></span>
                            <h3>Next step</h3>
                        </header>
                        <div class="ft-tracking-next-copy">
                            <strong>{{ $next['title'] }}</strong>
                            <p>{{ $next['message'] }}</p>
                        </div>
                    </section>

                    <x-order-tracking.shipment-details
                        :shipment="$shipment"
                        subtitle="Your order will be dispatched once quality checking is complete."
                        :show-button="false"
                    />

                    <x-order-tracking.status-strip
                        title="Billing"
                        message="Upcoming."
                        icon="billing"
                        tone="pink"
                        class="ft-tracking-qc-billing"
                    />

                    <x-order-tracking.status-strip
                        title="Payment"
                        message="Upcoming."
                        icon="payment"
                        tone="neutral"
                        class="ft-tracking-qc-payment"
                    />
                </div>
                @break

            @case('shipment')
                <div class="ft-tracking-shipment-grid">
                    <x-order-tracking.progress-list
                        title="Order updates"
                        subtitle="Follow the progress of your order."
                        :items="$timeline"
                        tone="success"
                    />

                    <x-order-tracking.shipment-details
                        :shipment="$shipment"
                        :shipments="$tracking['shipment_rows'] ?? []"
                        subtitle="Track your shipment with the courier."
                    />

                    <x-order-tracking.status-strip
                        :title="($tracking['summary']['billing']['state'] ?? 'upcoming') === 'active' ? 'Billing in progress' : 'Billing'"
                        :message="($tracking['summary']['billing']['state'] ?? 'upcoming') === 'active' ? 'We are preparing your invoice. No action is needed right now.' : 'Upcoming.'"
                        icon="billing"
                        tone="pink"
                        class="ft-tracking-shipment-billing"
                    />
                </div>
                @break

            @case('billing')
                <div class="ft-tracking-billing-grid">
                    <x-order-tracking.progress-list
                        title="Order progress"
                        subtitle="A summary of completed stages for your order."
                        :items="$timeline"
                        tone="success"
                    />

                    <section class="ft-tracking-next-card ft-tracking-next-card--billing">
                        <h3>What happens next?</h3>
                        <div class="ft-tracking-billing-current">
                            <span class="ft-tracking-billing-current-icon"><x-order-tracking.icon :name="$status['icon']" :size="29" /></span>
                            <div>
                                <strong>{{ $next['title'] }}</strong>
                                <p>{{ $next['message'] }}</p>
                            </div>
                        </div>
                        <div class="ft-tracking-info-box">
                            <span><x-order-tracking.icon name="info" :size="23" /></span>
                            <div>
                                <strong>{{ $next['note_title'] }}</strong>
                                <p>{{ $next['note_message'] }}</p>
                            </div>
                        </div>
                    </section>
                </div>
                @break

            @case('payment')
            @default
                <div class="ft-tracking-payment-grid">
                    <x-order-tracking.progress-list
                        title="Order updates"
                        :subtitle="($tracking['public_order_completed'] ?? false) ? 'Your order has been completed.' : 'See the latest status of your order.'"
                        :items="$timeline"
                        tone="success"
                    />

                    <div class="ft-tracking-payment-side">
                        <section class="ft-tracking-payment-card">
                            <header>
                                <h3>Payment details</h3>
                                <p>{{ ($tracking['public_order_completed'] ?? false) ? 'Your payment has been received and the order is complete.' : 'See the latest payment status for your order.' }}</p>
                            </header>
                            <x-order-tracking.status-strip
                                :title="$paymentDetail['title']"
                                :message="$paymentDetail['message']"
                                :icon="$paymentDetail['icon']"
                                :tone="$paymentDetail['tone']"
                            />
                        </section>

                        <section class="ft-tracking-next-card ft-tracking-next-card--payment">
                            <h3>Next steps</h3>
                            <div class="ft-tracking-payment-next">
                                <span class="ft-tracking-payment-next-icon">
                                    <x-order-tracking.icon :name="($tracking['public_order_completed'] ?? false) ? 'check' : ($status['icon'] ?? 'payment')" :size="28" />
                                </span>
                                <div>
                                    <strong>{{ $next['title'] }}</strong>
                                    <p>{{ $next['message'] }}</p>
                                    @if(($next['action'] ?? null) === 'payment-instructions')
                                        <x-ui.button href="{{ route('login') }}" class="ft-tracking-payment-login">
                                            {{ $next['action_label'] ?? 'Sign in' }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                @break
        @endswitch

        <div class="ft-tracking-another-wrap">
            <a href="{{ route('order.track').'#tracking-search-page-title' }}" class="ft-tracking-another">
                <x-order-tracking.icon name="refresh" :size="18" /> Track another order
            </a>
        </div>
    </section>

    @if(($tracking['design_preview'] ?? false) && !empty($tracking['status_variants']))
        <x-order-tracking.status-variants
            :section="$tracking['status_variants']"
            :current-status-key="$status['key'] ?? null"
        />
    @endif
</div>
