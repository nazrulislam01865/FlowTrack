@props(['job', 'canEditJob' => false, 'mentionUsers' => collect()])

{{--
    Order overview intentionally reuses the same rich-text interaction pattern
    as Inquiry Description. Products/specifications already have their own card,
    so this section is dedicated to the order narrative/brief only.
--}}
<section
    class="section-card ft-order-section-card ft-order-overview-card ft-order-rich-overview ft-inline-edit-shell"
    x-data="window.FlowTrack.ui.inlineEdit({
        key: @js('job-'.$job->id.'-overview-description'),
        label: 'Order overview',
        value: @js($job->description ?? ''),
        display: @js($job->description ?: 'No order overview has been provided.')
    })"
    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
>
    <div class="section-head ft-order-section-head ft-order-rich-overview-head">
        <h2>Order overview</h2>
        @if($canEditJob)
            <button
                x-show="!editing"
                :disabled="status === 'saving'"
                type="button"
                class="ft-order-overview-edit-button"
                title="Edit order overview"
                aria-label="Edit order overview"
                x-on:click.stop="(async () => {
                    const input = $refs.orderOverviewDescription;
                    if (input && input.dataset.mentionUsersLoaded !== '1') {
                        const users = await $wire.loadOrderMentionUsers({{ $job->id }});
                        input.dataset.mentionUsers = JSON.stringify(users ?? []);
                        input.dataset.mentionUsersLoaded = '1';
                    }
                    beginRichTextEdit(input);
                })()"
            >✎</button>
        @endif
    </div>

    <div x-show="!editing" class="ft-rich-text-content ft-order-overview-rich-content">
        <div x-show="!hasRichTextOverride">
            @if($job->description)
                <x-ui.mention-text :text="$job->description" />
            @else
                <span class="ft-order-overview-empty">No order overview has been provided.</span>
            @endif
        </div>
        <div x-cloak x-show="hasRichTextOverride" x-html="richTextOverrideHtml"></div>
    </div>

    @if($canEditJob)
        <div x-cloak x-show="editing" class="ft-order-overview-rich-editor ft-inline-description-editor">
            <textarea
                x-ref="orderOverviewDescription"
                data-rich-text
                autocomplete="off"
                data-mention-users="[]" data-mention-users-loaded="0"
                placeholder="Add the order overview, requirements or instructions, or paste screenshots here..."
            >{{ $job->description ?? '' }}</textarea>

            <div class="ft-order-overview-editor-actions">
                <button
                    type="button"
                    class="btn"
                    x-on:click="cancelRichTextEdit($refs.orderOverviewDescription)"
                >Cancel</button>
                <button
                    type="button"
                    class="btn primary ft-order-overview-save"
                    data-rich-text-submit
                    :disabled="status === 'saving'"
                    x-on:click="saveRichText(
                        $refs.orderOverviewDescription,
                        'No order overview has been provided.',
                        (clean) => $wire.updateJobTextField({{ $job->id }}, 'description', clean)
                    )"
                >
                    <span x-show="status !== 'saving'">Save</span>
                    <span x-cloak x-show="status === 'saving'">Saving…</span>
                </button>
                <x-ui.inline-save-state compact />
            </div>
        </div>
    @endif
</section>
