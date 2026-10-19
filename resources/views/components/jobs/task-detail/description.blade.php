            <section
                class="ft-detail-card ft-description-card ft-inline-edit-shell"
                x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-description'), label: 'task description', value: @js($effectiveDescription ?? ''), display: @js($effectiveDescription ?: 'No description has been provided for this task.') })"
                :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
            >
                @if($canEditTask)<button x-show="!editing" :disabled="status === 'saving'" class="ft-card-edit ft-detail-edit-button" type="button" title="Edit description" aria-label="Edit task description" x-on:click="beginRichTextEdit($refs.description)"><x-ui.detail-icon name="edit" /></button>@endif
                <h2>Description</h2>
                <div x-show="!editing" class="ft-rich-text-content">
                    <div x-show="!hasRichTextOverride">@if($effectiveDescription)<x-ui.mention-text :text="$effectiveDescription" />@else No description has been provided for this task. @endif</div>
                    <div x-cloak x-show="hasRichTextOverride" x-html="richTextOverrideHtml"></div>
                </div>
                @if($canEditTask)
                    <div x-cloak x-show="editing" class="ft-inline-description-editor"><textarea x-ref="description" class="ft-mention-input" data-rich-text rows="4" autocomplete="off" data-mention-users='@json($mentionUsers->values())'>{{ $effectiveDescription ?? '' }}</textarea><div class="ft-inline-description-actions"><button type="button" class="ft-outline-btn" x-on:click="cancelRichTextEdit($refs.description)">Cancel</button><button type="button" class="ft-new-job-btn" data-rich-text-submit :disabled="status === 'saving'" x-on:click="saveRichText($refs.description, 'No description has been provided for this task.', (clean) => $wire.updateSelectedTaskField('description', clean))">Save</button></div></div>
                    <x-ui.inline-save-state />
                @endif
            </section>
