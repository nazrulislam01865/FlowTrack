@props([
    'section',
    'rows' => 3,
    'message' => 'Loading this section when needed…',
    'rootMargin' => '240px 0px',
    'method' => 'loadCreateSection',
    'keyPrefix' => 'progressive-section',
    'contextType' => '',
    'contextId' => null,
    // Optional queue used by heavy detail pages. When present, only one
    // intersecting section in the same queue group is requested at a time.
    // This prevents Livewire from bundling several expensive viewport calls
    // into one giant update request.
    'queueGroup' => '',
    'queuePriority' => 100,
    'settleDelay' => 160,
])

@php
    $contextKey = $contextType !== '' && $contextId !== null
        ? '-'.$contextType.'-'.$contextId
        : '';
    $progressiveQueueGroup = trim((string) $queueGroup);
    $progressiveQueueKey = implode(':', [
        $progressiveQueueGroup !== '' ? $progressiveQueueGroup : 'direct',
        (string) $method,
        (string) $section,
        (string) $contextType,
        (string) ($contextId ?? ''),
    ]);
@endphp

<div
    {{ $attributes->class(['ft-progressive-section-placeholder']) }}
    wire:key="{{ $keyPrefix }}{{ $contextKey }}-{{ $method }}-{{ $section }}"
    role="status"
    aria-live="polite"
    aria-busy="true"
    x-data="{ requested: false, queued: false, eligible: false, observer: null }"
    x-init="
        const queueGroup = @js($progressiveQueueGroup);
        const queueKey = @js($progressiveQueueKey);
        const queuePriority = Number(@js((int) $queuePriority));
        const settleDelay = Math.max(0, Number(@js((int) $settleDelay)));

        const invokeSectionLoad = () => {
            @if($contextType !== '' && $contextId !== null)
                return $wire[@js($method)](@js($section), @js($contextType), @js((int) $contextId));
            @else
                return $wire[@js($method)](@js($section));
            @endif
        };

        const directLoad = () => {
            if (requested || !$el.isConnected) return Promise.resolve();
            requested = true;

            return Promise.resolve(invokeSectionLoad()).catch((error) => {
                // Allow a later viewport event to retry after a transient
                // Livewire/network failure instead of leaving the skeleton
                // permanently stuck.
                requested = false;
                throw error;
            });
        };

        const ensureProgressiveQueue = () => {
            if (window.FlowTrackProgressiveSectionQueue) {
                return window.FlowTrackProgressiveSectionQueue;
            }

            const groups = new Map();
            let sequence = 0;

            const stateFor = (group) => {
                if (!groups.has(group)) {
                    groups.set(group, {
                        running: false,
                        scheduled: false,
                        items: new Map(),
                    });
                }

                return groups.get(group);
            };

            const schedule = (group, delay = 0) => {
                const state = stateFor(group);
                if (state.scheduled || state.running) return;
                state.scheduled = true;

                window.setTimeout(() => {
                    state.scheduled = false;
                    process(group);
                }, Math.max(0, delay));
            };

            const process = (group) => {
                const state = stateFor(group);
                if (state.running) return;

                // A previous Livewire morph can disconnect queued placeholders.
                // Remove them before selecting the next section. New placeholders
                // will re-register themselves if they are still near the viewport.
                for (const [key, item] of state.items.entries()) {
                    if (!item.valid()) {
                        state.items.delete(key);
                        item.cancel?.();
                    }
                }

                const next = Array.from(state.items.values())
                    .sort((left, right) => {
                        const priorityDiff = left.priority - right.priority;
                        return priorityDiff !== 0 ? priorityDiff : left.sequence - right.sequence;
                    })[0];

                if (!next) {
                    if (!state.running && state.items.size === 0) groups.delete(group);
                    return;
                }

                state.items.delete(next.key);
                if (!next.valid()) {
                    next.cancel?.();
                    schedule(group, 0);
                    return;
                }

                state.running = true;

                Promise.resolve()
                    .then(() => next.run())
                    .catch((error) => next.fail?.(error))
                    .finally(() => {
                        state.running = false;
                        // Give Livewire/Alpine a short opportunity to finish the
                        // DOM morph and IntersectionObserver geometry update before
                        // deciding whether a lower section is still near the viewport.
                        schedule(group, next.settleDelay);
                    });
            };

            window.FlowTrackProgressiveSectionQueue = {
                enqueue(group, item) {
                    const state = stateFor(group);
                    state.items.set(item.key, {
                        ...item,
                        sequence: ++sequence,
                    });
                    schedule(group, 0);
                },
                cancel(group) {
                    const state = groups.get(group);
                    if (!state) return;
                    for (const item of state.items.values()) item.cancel?.();
                    state.items.clear();
                    if (!state.running) groups.delete(group);
                },
            };

            return window.FlowTrackProgressiveSectionQueue;
        };

        const loadSection = () => {
            if (requested || queued || !$el.isConnected || !eligible) return;

            if (!queueGroup) {
                directLoad().catch(() => {});
                return;
            }

            queued = true;
            ensureProgressiveQueue().enqueue(queueGroup, {
                key: queueKey,
                priority: queuePriority,
                settleDelay,
                valid: () => $el.isConnected && eligible && !requested,
                cancel: () => {
                    queued = false;
                },
                run: () => {
                    if (!$el.isConnected || !eligible) {
                        queued = false;
                        return Promise.resolve();
                    }

                    queued = false;
                    requested = true;

                    return Promise.resolve(invokeSectionLoad()).catch((error) => {
                        requested = false;
                        throw error;
                    });
                },
                fail: () => {
                    requested = false;
                    queued = false;
                    if ($el.isConnected && eligible) {
                        window.setTimeout(() => loadSection(), 900);
                    }
                },
            });
        };

        // Disconnect observers before Livewire SPA navigation. Context-aware
        // server guards also reject stale requests, but cancelling the browser
        // queue avoids sending them in the first place.
        document.addEventListener('livewire:navigating', () => {
            observer?.disconnect();
            if (queueGroup) ensureProgressiveQueue().cancel(queueGroup);
        }, { once: true });

        if (!window.IntersectionObserver) {
            eligible = true;
            loadSection();
            return;
        }

        observer = new IntersectionObserver((entries) => {
            eligible = Boolean(entries[0]?.isIntersecting);
            if (!eligible) return;
            loadSection();
        }, { rootMargin: @js($rootMargin) });

        observer.observe($el);
    "
>
    <div class="ft-progressive-skeleton" aria-hidden="true">
        @for($row = 0; $row < $rows; $row++)
            <span style="--ft-skeleton-width: {{ 100 - (($row % 3) * 12) }}%"></span>
        @endfor
    </div>
    @if($message)
        <small class="muted">{{ $message }}</small>
    @endif
</div>
