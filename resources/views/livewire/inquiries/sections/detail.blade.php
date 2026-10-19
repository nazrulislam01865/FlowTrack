        @php
            $inquiry = $selectedInquiry;
            $totalTasks = (int) $inquiry->tasks_count;
            $completedTasks = (int) $inquiry->completed_tasks_count;
            $readyForDecision = !$inquiry->result && $totalTasks > 0 && $completedTasks === $totalTasks;
            $currentTask = $inquiry->currentTask;
            $firstStartedTask = $inquiry->tasks->whereNotNull('started_at')->sortBy('started_at')->first();
            $lastCompletedTask = $inquiry->tasks->whereNotNull('completed_at')->sortByDesc('completed_at')->first();
            $inquiryStartAt = $inquiry->started_at ?: $firstStartedTask?->started_at;
            $inquiryStartLocal = \App\Support\UserLocalTime::localize($inquiryStartAt);
            $inquiryCompletedAt = $inquiry->completed_at ?: ($readyForDecision ? $lastCompletedTask?->completed_at : null);
            $detailStatus = match (true) {
                $inquiry->result === 'converted' => 'Converted',
                $inquiry->result === 'dead' => 'Closed',
                (string) $inquiry->status === 'Draft' => 'Draft',
                default => (string) ($inquiry->status ?: $inquiryDefaultStatus),
            };
            $detailStatusColor = $detailStatusColor ?? null;
            $detailPriorityColor = $masterData->displayColorFor('priority', (string) $inquiry->priority);
            $headerFlagTask = $currentTask?->needs_attention ? $currentTask : $inquiry->tasks->first(fn ($task) => (bool) $task->needs_attention);
            $headerFlagLabel = $inquiry->needs_attention
                ? 'Requires attention'
                : ($headerFlagTask ? 'Requires attention' : '');
            $headerFlagReason = $inquiry->needs_attention
                ? (string) ($inquiry->attention_reason ?? '')
                : (string) ($headerFlagTask?->attention_reason ?? '');
        @endphp
        <section class="view inquiry-detail-view ft-detail-products-scope" wire:key="inquiry-detail-{{ $inquiry->id }}" x-data="{
            inquiryStatus:@js($detailStatus),
            inquiryStatusColor:@js($detailStatusColor),
            inquiryStartValue:@js($inquiryStartLocal?->format('Y-m-d\TH:i') ?? ''),
            inquiryStartDisplay:@js($inquiryStartLocal?->format('M j, Y · g:i A') ?? '—'),
            statusTone(status){
                if (String(status).includes('Converted') || String(status).includes('Completed')) return 'green';
                if (String(status).includes('Dead') || String(status).includes('Closed')) return 'red';
                if (String(status).includes('Ready') || String(status).includes('On Hold')) return 'amber';
                if (String(status).includes('Waiting')) return 'purple';
                return 'blue';
            },
            async saveTaskStatus(event, taskId){
                const previous=this.inquiryStatus;
                try{
                    const result=await $wire.updateTaskStatusInline(taskId,event.currentTarget.value);
                    if(result?.inquiryStatus)this.inquiryStatus=result.inquiryStatus;
                    if(result?.inquiryColor)this.inquiryStatusColor=result.inquiryColor;
                    if(result && Object.prototype.hasOwnProperty.call(result,'inquiryStartValue')){
                        this.inquiryStartValue=result.inquiryStartValue || '';
                        this.inquiryStartDisplay=result.inquiryStartDisplay || '—';
                        window.dispatchEvent(new CustomEvent('flowtrack-inquiry-started',{detail:{value:this.inquiryStartValue,display:this.inquiryStartDisplay}}));
                    }
                }catch(error){
                    this.inquiryStatus=previous;
                    window.location.reload();
                }
            }
        }">
            <div class="ft-detail-toolbar task-toolbar ft-exact-task-header ft-inquiry-exact-header">
                <div class="ft-task-heading-copy">
                    <div class="ft-detail-breadcrumb ft-id-breadcrumb">
                        <a href="{{ route('inquiries.index') }}" wire:navigate>Inquiries</a>
                        <span>/</span>
                        <span class="ft-copyable-id-wrap ft-inquiry-detail-code-wrap">
                            <span>{{ $inquiry->inquiry_number }}</span>
                            <button type="button" class="ft-copy-id-btn" title="Copy Inquiry ID" aria-label="Copy {{ $inquiry->inquiry_number }}" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard?.writeText(@js($inquiry->inquiry_number)); this.classList.add('copied'); setTimeout(()=>this.classList.remove('copied'),900)"><x-ui.detail-icon name="copy" /></button>
                        </span>
                    </div>
                    <div class="ft-task-title-line">
                        <h1
                            class="ft-editable-task-title ft-inline-edit-shell"
                            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-title'), label: 'Inquiry title', value: @js($inquiry->subject), display: @js($inquiry->subject) })"
                            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                        >
                            <span x-show="!editing" x-text="display">{{ $inquiry->subject }}</span>
                            @if($canEditInquiry && !$inquiry->result)
                                <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-pencil ft-detail-edit-button" aria-label="Edit Inquiry title" title="Edit Inquiry title" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.inquiryTitle.focus())"><x-ui.detail-icon name="edit" /></button>
                                <input x-ref="inquiryTitle" x-cloak x-show="editing" x-model="draftValue" type="text" maxlength="255"
                                    x-on:keydown.escape.prevent="cancelEdit()"
                                    x-on:keydown.enter.prevent="$event.target.blur()"
                                    x-on:blur="if (editing) commit(draftValue.trim(), draftValue.trim(), () => $wire.updateInquiryField('subject', draftValue.trim()))">
                                <x-ui.inline-save-state />
                            @endif
                        </h1>
                    </div>
                    <div class="ft-inquiry-header-meta" aria-label="Inquiry information">
                        <span class="ft-inquiry-header-meta-item"><span class="ft-inquiry-header-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.4 3-5.2 6.5-5.2s5.7 1.8 6.5 5.2"></path></svg></span><span class="ft-client-inline-identity"><x-ui.client-logo :client="$inquiry->client" :name="$inquiry->client?->name ?: 'Client'" :size="20" /><span>Client <strong>{{ $inquiry->client?->name ?: '—' }}</strong></span></span></span>
                        <span class="ft-inquiry-header-meta-separator" aria-hidden="true">•</span>
                        <span class="ft-inquiry-header-meta-item"><span class="ft-inquiry-header-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.4 3-5.2 6.5-5.2s5.7 1.8 6.5 5.2"></path></svg></span><span>Client contact <strong>{{ $inquiry->client_contact ?: '—' }}</strong></span></span>
                        <span class="ft-inquiry-header-meta-separator" aria-hidden="true">•</span>
                        <span class="ft-inquiry-header-meta-item ft-inquiry-header-reference">
                            <span class="ft-inquiry-header-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M7 3.5h7l4 4V20.5H7z"></path><path d="M14 3.5v4h4"></path></svg></span>
                            <span>Reference <strong>{{ $inquiry->reference_number ?: '—' }}</strong></span>
                            @if($inquiry->reference_number)
                                <button type="button" class="ft-copy-id-btn ft-inquiry-header-copy" title="Copy Reference Number" aria-label="Copy reference number {{ $inquiry->reference_number }}" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard?.writeText(@js($inquiry->reference_number)); this.classList.add('copied'); setTimeout(()=>this.classList.remove('copied'),900)"><x-ui.detail-icon name="copy" /></button>
                            @endif
                        </span>
                        <span class="ft-inquiry-header-meta-separator" aria-hidden="true">•</span>
                        <span class="ft-inquiry-header-meta-item"><span class="ft-inquiry-header-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 19c.8-3.4 3-5.2 6.5-5.2s5.7 1.8 6.5 5.2"></path></svg></span><span>Created by <strong>{{ $inquiry->creator?->name ?: 'System' }}</strong></span></span>
                        <span class="ft-inquiry-header-meta-separator" aria-hidden="true">•</span>
                        <span class="ft-inquiry-header-meta-item"><span class="ft-inquiry-header-meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5.5" width="16" height="14" rx="2"></rect><path d="M8 3.5v4M16 3.5v4M4 10h16"></path></svg></span><span>Created <strong>{{ $inquiry->created_at ? \App\Support\UserLocalTime::format($inquiry->created_at, 'M j, Y') : '—' }}@if($inquiry->created_at) at {{ \App\Support\UserLocalTime::format($inquiry->created_at, 'g:i A') }}@endif</strong></span></span>
                        <span class="ft-inquiry-header-meta-separator" aria-hidden="true">•</span>
                        <span class="ft-inquiry-header-meta-item ft-inquiry-header-action" title="{{ $headerFlagReason ?: 'Request attention from the Inquiry creator and administrators' }}">
                            <span>Action:</span>
                            <button type="button" class="ft-inquiry-header-flag-button {{ $headerFlagLabel !== '' ? 'is-flagged' : '' }}" wire:click="openInquiryAttentionReason" @disabled($inquiry->result) aria-label="Request attention" title="{{ $headerFlagLabel !== '' ? 'View or update attention request' : 'Request attention' }}">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 21V4"></path><path d="M7 5h10l-2 4 2 4H7"></path></svg>
                            </button>
                            @if($headerFlagLabel !== '')<strong class="ft-inquiry-header-flag-label">{{ $headerFlagLabel }}</strong>@endif
                        </span>
                    </div>
                </div>
            </div>

            <div class="tabs ft-inquiry-detail-tabs" role="tablist" aria-label="Inquiry detail sections">
                <button class="tab {{ $detailTab === 'overview' ? 'active' : '' }}" type="button" wire:click="setDetailTab('overview')">Overview</button>
                <button class="tab {{ $detailTab === 'rfq' ? 'active' : '' }}" type="button" wire:click="setDetailTab('rfq')">RFQ @if(($inquiryRfqSummary['invited'] ?? 0) > 0)<span class="ft-inquiry-tab-count">{{ $inquiryRfqSummary['invited'] }} invitations</span>@endif</button>
                <button class="tab {{ $detailTab === 'comparison' ? 'active' : '' }}" type="button" wire:click="setDetailTab('comparison')">Comparison statement @if(($inquiryRfqSummary['submitted'] ?? 0) > 0)<span class="ft-inquiry-tab-count is-green">{{ $inquiryRfqSummary['submitted'] }}</span>@endif</button>
            </div>

            @if($detailTab === 'overview')
                <div class="tabpane ft-task-detail-page ft-exact-task-detail ft-inquiry-task-overview-exact" wire:key="inquiry-detail-overview-{{ $inquiry->id }}">
                    <section class="ft-task-property-grid ft-friendly-task-properties ft-inquiry-overview-properties">
                        <div class="ft-task-property ft-inquiry-auto-status-property">
                            <small>Status</small>
                            <div class="ft-task-property-display">
                                <span class="status-dot {{ $detailStatusColor ? 'ft-master-color-dot' : '' }}" style="{{ \App\Support\MasterColor::style($detailStatusColor) }}" x-bind:class="inquiryStatusColor ? 'ft-master-color-dot' : statusTone(inquiryStatus)" x-bind:style="inquiryStatusColor ? '--ft-master-color:'+inquiryStatusColor : ''"></span>
                                <b class="ft-property-value" x-text="inquiryStatus">{{ $detailStatus }}</b>
                            </div>
                        </div>

                        <div
                            class="ft-task-property ft-inline-edit-shell"
                            x-data="{ ...window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-priority'), label: 'Inquiry priority', value: @js($inquiry->priority), display: @js($inquiry->priority) }), priorityColor: @js($detailPriorityColor) }"
                            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                            x-on:click.outside="if (editing) cancelEdit()"
                        >
                            <small>Priority</small>
                            <div x-show="!editing" class="ft-task-property-display"><span class="status-dot ft-master-color-dot" style="{{ \App\Support\MasterColor::style($detailPriorityColor) }}" x-bind:style="priorityColor ? '--ft-master-color:'+priorityColor : ''"></span><b class="ft-property-value" x-text="display">{{ $inquiry->priority }}</b>@if($canEditInquiry && !$inquiry->result)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit priority" aria-label="Edit Inquiry priority" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.inquiryPriority?.showPicker ? $refs.inquiryPriority.showPicker() : $refs.inquiryPriority?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                            @if($canEditInquiry && !$inquiry->result)
                                <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><select data-master-color-select x-ref="inquiryPriority" x-model="draftValue" class="ft-task-property-inline-input ft-master-color" style="{{ \App\Support\MasterColor::style($detailPriorityColor) }}" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="const nextColor=String($event.target.selectedOptions[0]?.dataset?.color || ''); window.FlowTrack.ui.masterColor?.applySelect($event.target); commit($event.target.value, selectedLabel($event), () => $wire.updateInquiryField('priority', draftValue)).then(ok => { if(ok) priorityColor=nextColor; });">@unless($inquiryPriorities->contains(fn($priority) => (string) $priority->name === (string) $inquiry->priority))<option value="{{ $inquiry->priority }}" data-color="{{ $masterData->displayColorFor('priority', (string) $inquiry->priority) }}">{{ $inquiry->priority }}</option>@endunless @foreach($inquiryPriorities as $priority)<option value="{{ $priority->name }}" data-color="{{ $masterData->displayColorFor('priority', $priority->name) }}">{{ $priority->name }}</option>@endforeach</select></div>
                                <x-ui.inline-save-state compact />
                            @endif
                        </div>

                        <div
                            class="ft-task-property ft-inline-edit-shell"
                            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-assignee'), label: 'Inquiry assignee', value: @js($inquiry->owner_id ?? ''), display: @js($inquiry->owner?->name ?? 'Unassigned'), avatarUrl: @js($inquiry->owner?->profileImageUrl() ?? '') })"
                            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                            x-on:click.outside="if (editing) cancelEdit()"
                            x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                            x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateInquiryField('owner_id', draftValue), { avatarUrl: String($event.detail?.avatarUrl ?? '') })"
                        >
                            <small>Assignee</small>
                            <div x-show="!editing" class="ft-task-property-display ft-inline-person-live">
                                <x-ui.inline-live-avatar :size="26" />
                                <b class="ft-property-value" x-text="display">{{ $inquiry->owner?->name ?? 'Unassigned' }}</b>
                                @if($canEditInquiry && $canAssignInquiry && !$inquiry->result)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit assignee" aria-label="Edit Inquiry assignee" x-on:click.stop="openRemotePicker($event.currentTarget)"><x-ui.detail-icon name="edit" /></button>@endif
                            </div>
                            @if($canEditInquiry && $canAssignInquiry && !$inquiry->result)
                                <div x-cloak x-show="editing" class="ft-task-property-inline-editor ft-task-property-assignee-editor">
                                    <x-ui.inline-remote-user
                                        :value="$inquiry->owner_id ?? ''"
                                        :selected-label="$inquiry->owner?->name ?? 'Unassigned'"
                                        context="inquiry-owner"
                                        parent-type="inquiry"
                                        :parent-id="$inquiry->id"
                                        search-placeholder="Search assignee…"
                                        trigger-class="ft-task-property-inline-input"
                                        variant="compact"
                                        :menu-width="300"
                            :fixed-menu="true"
                                    />
                                </div>
                                <x-ui.inline-save-state compact />
                            @endif
                        </div>

                        <div
                            class="ft-task-property ft-inline-edit-shell"
                            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-due-date'), label: 'Inquiry next due date', value: @js($currentTask?->due_date?->format('Y-m-d') ?? ''), display: @js($currentTask?->due_date?->format('M j, Y') ?? '—') })"
                            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                            x-on:click.outside="if (editing) cancelEdit()"
                        >
                            <small>Due date</small>
                            <div x-show="!editing" class="ft-task-property-display"><x-ui.detail-icon name="calendar" class="ft-calendar-glyph" /><b class="ft-property-value" x-text="display">{{ $currentTask?->due_date?->format('M j, Y') ?? '—' }}</b>@if($currentTask && $canEditActiveTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit due date" aria-label="Edit Inquiry due date" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.inquiryOverviewDue?.showPicker ? $refs.inquiryOverviewDue.showPicker() : $refs.inquiryOverviewDue?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                            @if($currentTask && $canEditActiveTask)
                                <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><input x-ref="inquiryOverviewDue" x-model="draftValue" class="ft-task-property-inline-input" type="date" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueInline({{ $currentTask->id }}, draftValue))"></div>
                                <x-ui.inline-save-state compact />
                            @endif
                        </div>

                        <div
                            class="ft-task-property ft-inline-edit-shell"
                            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-start-at'), label: 'Inquiry start date', value: @js($inquiryStartLocal?->format('Y-m-d\TH:i') ?? ''), display: @js($inquiryStartLocal?->format('M j, Y · g:i A') ?? '—') })"
                            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                            x-on:click.outside="if (editing) cancelEdit()"
                            x-on:flowtrack-inquiry-started.window="const v=String($event.detail?.value ?? ''); const d=String($event.detail?.display ?? '—'); serverValue=v; value=v; savedValue=v; draftValue=v; display=d; savedDisplay=d;"
                        >
                            <small>Start date</small>
                            <div x-show="!editing" class="ft-task-property-display">
                                <x-ui.detail-icon name="calendar" class="ft-calendar-glyph" />
                                <b class="ft-property-value" x-text="display">{{ $inquiryStartLocal?->format('M j, Y · g:i A') ?? '—' }}</b>
                                @if($canEditInquiry && !$inquiry->result)
                                    <button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit start date and time" aria-label="Edit Inquiry start date and time" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.inquiryStartAt?.showPicker ? $refs.inquiryStartAt.showPicker() : $refs.inquiryStartAt?.focus())"><x-ui.detail-icon name="edit" /></button>
                                @endif
                            </div>
                            @if($canEditInquiry && !$inquiry->result)
                                <div x-cloak x-show="editing" class="ft-task-property-inline-editor">
                                    <input x-ref="inquiryStartAt" x-model="draftValue" class="ft-task-property-inline-input" type="datetime-local" step="60"
                                        x-on:keydown.escape.prevent="cancelEdit()"
                                        x-on:change="commit($event.target.value, formatDateTime($event.target.value), () => $wire.updateInquiryStartInline(draftValue))">
                                </div>
                                <x-ui.inline-save-state compact />
                            @endif
                        </div>

                        <div class="ft-task-property ft-task-completed-property">
                            <small>Completed On</small>
                            <div class="ft-task-property-display"><x-ui.detail-icon name="calendar" class="ft-calendar-glyph" /><b class="ft-property-value ft-completed-date-time"><span>{{ $inquiryCompletedAt ? \App\Support\UserLocalTime::format($inquiryCompletedAt, 'M j, Y') : '—' }}</span>@if($inquiryCompletedAt)<span class="ft-completed-time">{{ \App\Support\UserLocalTime::format($inquiryCompletedAt, 'g:i A') }}</span>@endif</b></div>
                        </div>
                    </section>

                    <section
                        class="ft-detail-card ft-inquiry-description-card ft-inline-edit-shell"
                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-'.$inquiry->id.'-description'), label: 'Inquiry description', value: @js($inquiry->requirement_notes ?? ''), display: @js($inquiry->requirement_notes ?: 'No description has been provided for this Inquiry.') })"
                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    >
                        <div class="ft-inquiry-description-head">
                            <h2>Description</h2>
                            @if($canEditInquiry && !$inquiry->result)
                                <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button ft-detail-edit-button" title="Edit description" aria-label="Edit Inquiry description" x-on:click.stop="beginRichTextEdit($refs.inquiryDescription)"><x-ui.detail-icon name="edit" /></button>
                            @endif
                        </div>
                        <div x-show="!editing" class="ft-rich-text-content ft-inquiry-description-content">
                            <div x-show="!hasRichTextOverride">@if($inquiry->requirement_notes)<x-ui.mention-text :text="$inquiry->requirement_notes" />@else No description has been provided for this Inquiry. @endif</div>
                            <div x-cloak x-show="hasRichTextOverride" x-html="richTextOverrideHtml"></div>
                        </div>
                        @if($canEditInquiry && !$inquiry->result)
                            <div x-cloak x-show="editing" class="ft-inquiry-description-editor ft-inline-description-editor">
                                <textarea x-ref="inquiryDescription" data-rich-text placeholder="Add the client requirement or Inquiry description, or paste screenshots here...">{{ $inquiry->requirement_notes ?? '' }}</textarea>
                                <div class="ft-inquiry-description-editor-actions">
                                    <button type="button" class="secondary" x-on:click="cancelRichTextEdit($refs.inquiryDescription)">Cancel</button>
                                    <button type="button" class="primary" data-rich-text-submit :disabled="status === 'saving'" x-on:click="saveRichText($refs.inquiryDescription, 'No description has been provided for this Inquiry.', (clean) => $wire.updateInquiryField('requirement_notes', clean))">Save</button>
                                    <x-ui.inline-save-state compact />
                                </div>
                            </div>
                        @endif
                    </section>

                    @if($canViewInquiryProducts)
                    @if((bool) ($inquiryDetailSectionsReady['products'] ?? false))
                    @php
                        $inquiryProductOverview = $inquiryProductRfqOverview ?? [
                            'stats' => ['products' => 0, 'supplier_assignments' => 0, 'invitations_sent' => 0, 'quotations_received' => 0],
                            'rows' => collect(),
                            'product_count' => 0,
                            'total_units' => 0,
                        ];
                        $inquiryOverviewRows = collect($inquiryProductOverview['rows'] ?? []);
                    @endphp
                    <x-inquiries.product-rfq-overview
                        id="inquiry-products-card"
                        :overview="$inquiryProductOverview"
                        :can-add="$canCreateInquiryProducts"
                        :show-add-form="$showAddInquiryProductForm"
                    >
                        @forelse($inquiryOverviewRows as $overviewRow)
                            <x-inquiries.product-rfq-overview-row
                                :row="$overviewRow"
                                :can-edit="$canEditInquiryProducts"
                                :can-delete="$canDeleteInquiryProducts"
                            />

                            @if($canEditInquiryProducts && (int) $editInquiryProductItemId === (int) ($overviewRow['item_id'] ?? 0))
                                <tr class="ft-detail-product-editor-row ft-inquiry-prq-editor-row" wire:key="inquiry-product-inline-editor-{{ (int) ($overviewRow['item_id'] ?? 0) }}">
                                    <td colspan="7">
                                        <x-catalog.detail-product-edit
                                            :wire-key="'inquiry-product-edit-'.(int) ($overviewRow['item_id'] ?? 0)"
                                            variant="inquiry"
                                            record-label="Inquiry"
                                            search-model="editInquiryProductSearch"
                                            :search-value="$editInquiryProductSearch"
                                            :search-results="$editInquiryProductSearchResults"
                                            :search-suppliers="$editInquiryProductSearchSuppliers"
                                            :result-total="$editInquiryProductResultTotal"
                                            :show-all-results="$editInquiryProductShowAllResults"
                                            show-all-method="showAllEditInquiryProductResults"
                                            select-method="selectEditInquiryProduct"
                                            :selected-product="$editInquiryProductSelectedProduct"
                                            :selected-supplier="$editInquiryProductSelectedSupplier"
                                            :category-value="$editInquiryProductCategory"
                                            quantity-model="editInquiryProductQuantity"
                                            :quantity-value="$editInquiryProductQuantity"
                                            :unit-price-value="$editInquiryProductUnitPrice"
                                            notes-model="editInquiryProductNotes"
                                            :notes-value="$editInquiryProductNotes"
                                            :supplier-editable="false"
                                            :currency-symbol="$inquiryCurrencySymbol"
                                            close-method="closeEditInquiryProduct"
                                            save-method="saveEditInquiryProduct"
                                            selected-error-key="editInquiryProductSelectedId"
                                            quantity-error-key="editInquiryProductQuantity"
                                            unit-price-error-key="editInquiryProductUnitPrice"
                                            notes-error-key="editInquiryProductNotes"
                                        />
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr class="ft-inquiry-prq-empty-row">
                                <td colspan="7">No products have been added to this Inquiry yet.</td>
                            </tr>
                        @endforelse

                        <x-slot:afterTable>
                            @if($showAddInquiryProductForm && $canCreateInquiryProducts)
                                <div class="ft-detail-products-inline-add ft-inquiry-prq-inline-add" wire:key="inquiry-add-product-inline-{{ $inquiry->id }}" x-data x-on:keydown.escape.window="$wire.closeAddInquiryProductForm()">
                                    <x-catalog.detail-add-product
                                        :wire-key="'inquiry-detail-add-product-'.$inquiry->id"
                                        search-model="inquiryProductSearch"
                                        :search-value="$inquiryProductSearch"
                                        :search-results="$inquiryProductSearchResults"
                                        :search-suppliers="$inquiryProductSearchSuppliers"
                                        :result-total="$inquiryProductResultTotal"
                                        :show-all-results="$inquiryProductShowAllResults"
                                        show-all-method="showAllInquiryProductResults"
                                        select-method="selectInquiryProduct"
                                        :selected-product="$inquiryProductSelectedProduct"
                                        :selected-supplier="$inquiryProductSelectedSupplier"
                                        :category-value="$inquiryProductCategory"
                                        quantity-model="inquiryProductQuantity"
                                        :quantity-value="$inquiryProductQuantity"
                                        unit-price-model="inquiryProductUnitPrice"
                                        :unit-price-value="$inquiryProductUnitPrice"
                                        :currency-symbol="$inquiryCurrencySymbol"
                                        close-method="closeAddInquiryProductForm"
                                        save-method="saveInquiryProduct"
                                        selected-error-key="inquiryProductSelectedId"
                                        quantity-error-key="inquiryProductQuantity"
                                        unit-price-error-key="inquiryProductUnitPrice"
                                        missing-supplier-method="openInquiryProductSupplierResolution"
                                        record-label="Inquiry"
                                    />
                                </div>
                            @endif
                        </x-slot:afterTable>
                    </x-inquiries.product-rfq-overview>
                    @else
                        <x-ui.progressive-section-loader
                            section="products"
                            method="loadDetailSection"
                            key-prefix="inquiry-detail"
                            context-type="inquiry"
                            :context-id="$inquiry->id"
                            :rows="4"
                            message="Loading Inquiry products when needed…"
                            root-margin="360px 0px"
                        />
                    @endif
                    @endif

                    <div id="tab-workflow" class="ft-inquiry-overview-taskflow ft-inquiry-workflow-pane">
                        @if(auth()->user()->canModule('tasks', 'view'))
                            @if((bool) ($inquiryDetailSectionsReady['taskflow'] ?? false))
                                @include('livewire.inquiries._taskflow')
                            @else
                                <x-ui.progressive-section-loader
                                    section="taskflow"
                                    method="loadDetailSection"
                                    key-prefix="inquiry-detail"
                                    context-type="inquiry"
                                    :context-id="$inquiry->id"
                                    :rows="5"
                                    message="Loading Inquiry taskflow when needed…"
                                    root-margin="360px 0px"
                                />
                            @endif
                        @else
                            <section class="panel"><div class="ft-inquiry-empty-workflow">Task access is not enabled for your role.</div></section>
                        @endif
                    </div>

                    @if(auth()->user()->canModule('documents', 'view'))
                        @if((bool) ($inquiryDetailSectionsReady['documents'] ?? false))
                            @include('livewire.inquiries._attachments')
                        @else
                            <x-ui.progressive-section-loader section="documents" method="loadDetailSection" key-prefix="inquiry-detail" context-type="inquiry" :context-id="$inquiry->id" :rows="3" message="Loading Inquiry attachments when needed…" root-margin="300px 0px" />
                        @endif
                    @endif

                    @if((bool) ($inquiryDetailSectionsReady['activity'] ?? false))
                        @include('livewire.inquiries._activity')
                    @else
                        <x-ui.progressive-section-loader section="activity" method="loadDetailSection" key-prefix="inquiry-detail" context-type="inquiry" :context-id="$inquiry->id" :rows="4" message="Loading Inquiry activity when needed…" root-margin="300px 0px" />
                    @endif

                </div>
            @elseif($detailTab === 'rfq')
                @include('livewire.inquiries.sections.rfq')
            @elseif($detailTab === 'comparison')
                @include('livewire.inquiries.sections.comparison')
            @endif

            @if($showRfqSettings)
                <x-inquiries-rfq-settings :rfq-reminder-enabled="$rfqReminderEnabled" />
            @endif

            @if($showTaskDocumentModal && $taskDocumentModalTask)
                @php
                    $completeAfterTaskDocument = (bool) $taskDocumentModalTask->requires_submission
                        && ! $taskDocumentModalTask->completed_at;
                    $taskDocumentMax = \App\Models\InquiryTask::MAX_DOCUMENTS;
                    $taskDocumentExistingCount = (int) ($taskDocumentModalTask->documents_count ?? 0);
                    $taskDocumentSelectedCount = count($taskDocumentUploads);
                    $taskDocumentRemainingSlots = max(0, $taskDocumentMax - $taskDocumentExistingCount - $taskDocumentSelectedCount);
                @endphp
                <div class="ft-inquiry-task-document-modal-backdrop" wire:key="inquiry-task-document-modal" wire:click.self="closeTaskDocumentModal">
                    <section class="ft-inquiry-task-document-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="task-document-modal-title">
                        <header class="ft-inquiry-task-document-modal-head">
                            <div>
                                <h2 id="task-document-modal-title">{{ $completeAfterTaskDocument ? 'Required file needed to complete task' : 'Add new document to task' }}</h2>
                                <p>{{ $completeAfterTaskDocument ? 'Add the required file or files now. The task will be completed automatically after all selected documents are saved.' : 'Upload up to '.$taskDocumentMax.' documents to this task.' }}</p>
                            </div>
                            <button type="button" class="ft-inquiry-task-document-modal-close" wire:click="closeTaskDocumentModal" aria-label="Close">×</button>
                        </header>

                        <div class="ft-inquiry-task-document-modal-body">
                            <div class="ft-inquiry-task-document-target">
                                <span class="ft-inquiry-task-document-target-icon">▣</span>
                                <div>
                                    <small>ATTACHING TO</small>
                                    <strong>{{ $taskDocumentModalTask->title }}</strong>
                                    <span>INQ-TASK-{{ str_pad((string) $taskDocumentModalTask->id, 5, '0', STR_PAD_LEFT) }} &nbsp;·&nbsp; {{ $inquiry->sourceWorkflow?->name ?? 'Inquiry Taskflow' }}</span>
                                    <span class="ft-inquiry-task-document-reference"><b>Inquiry Reference:</b> {{ $inquiry->reference_number ?: '—' }}</span>
                                </div>
                                <span class="ft-inquiry-task-document-target-lock">▣&nbsp; Task selected</span>
                            </div>

                            <div class="ft-inquiry-task-document-source-label">Document source</div>
                            <div class="ft-inquiry-task-document-source-tabs is-single-source">
                                <button type="button" class="active" disabled aria-current="true">
                                    <span>↥</span> Upload new
                                </button>
                            </div>

                            @if($canCreateDocuments)
                                <div
                                    class="ft-inquiry-task-document-upload-field"
                                    x-data="{ uploading: false, progress: 0 }"
                                    x-on:livewire-upload-start="uploading = true; progress = 0"
                                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                                    x-on:livewire-upload-finish="progress = 100; window.setTimeout(() => { uploading = false; progress = 0 }, 250)"
                                    x-on:livewire-upload-error="uploading = false; progress = 0"
                                    x-on:livewire-upload-cancel="uploading = false; progress = 0"
                                >
                                    @if($taskDocumentSelectedCount > 0)
                                        <div class="ft-inquiry-attachment-selected-count">
                                            {{ $taskDocumentSelectedCount }} file{{ $taskDocumentSelectedCount === 1 ? '' : 's' }} selected
                                            @if($taskDocumentExistingCount > 0)
                                                · {{ $taskDocumentExistingCount }} already attached
                                            @endif
                                        </div>
                                        <div class="ft-inquiry-attachment-selected-files">
                                            @foreach($taskDocumentUploads as $uploadIndex => $taskDocumentUpload)
                                                @php
                                                    $taskDocumentUploadName = $taskDocumentUpload?->getClientOriginalName() ?: 'Selected file';
                                                    $taskDocumentUploadType = strtoupper((string) pathinfo($taskDocumentUploadName, PATHINFO_EXTENSION)) ?: 'FILE';
                                                    $taskDocumentUploadSize = $taskDocumentUpload
                                                        ? ($taskDocumentUpload->getSize() >= 1048576
                                                            ? number_format($taskDocumentUpload->getSize() / 1048576, 1).' MB'
                                                            : number_format(max(1, (int) ceil($taskDocumentUpload->getSize() / 1024))).' KB')
                                                        : 'Selected file';
                                                @endphp
                                                <div class="ft-inquiry-attachment-selected-file" wire:key="task-document-upload-{{ $uploadIndex }}-{{ md5($taskDocumentUploadName) }}">
                                                    <span class="ft-inquiry-attachment-selected-check" aria-hidden="true">✓</span>
                                                    <span class="ft-inquiry-attachment-selected-copy">
                                                        <strong title="{{ $taskDocumentUploadName }}">{{ $taskDocumentUploadName }}</strong>
                                                        <small>{{ $taskDocumentUploadType }} · {{ $taskDocumentUploadSize }} · Ready to upload</small>
                                                    </span>
                                                    <button type="button" wire:click="removeTaskDocumentUpload({{ $uploadIndex }})" wire:loading.attr="disabled" wire:target="removeTaskDocumentUpload({{ $uploadIndex }})">Remove</button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="ft-inquiry-attachment-field-label">
                                            File attachment
                                            @if($taskDocumentExistingCount > 0)
                                                · {{ $taskDocumentExistingCount }}/{{ $taskDocumentMax }} already attached
                                            @endif
                                        </div>
                                    @endif

                                    @if($taskDocumentRemainingSlots > 0)
                                        <label class="ft-inquiry-task-document-dropzone ft-inquiry-attachment-dropzone {{ $taskDocumentSelectedCount > 0 ? 'is-compact' : '' }}">
                                            <input type="file" multiple wire:model="taskDocumentUploads" accept="{{ \App\Support\AttachmentUpload::accept() }}" aria-label="{{ $taskDocumentSelectedCount > 0 ? 'Add more files' : 'Choose files to upload' }}">
                                            <svg class="ft-inquiry-attachment-upload-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 16l-4-4-4 4M12 12v9M20.4 17.5A5 5 0 0 0 18 8.2 7 7 0 0 0 4.3 10.8 4.5 4.5 0 0 0 5.5 19H7"/></svg>
                                            @if($taskDocumentSelectedCount > 0)
                                                <strong>Add more files</strong>
                                                <b>Drag &amp; drop or <span>browse</span></b>
                                            @else
                                                <strong>Drag &amp; drop files here</strong>
                                                <b>or choose from your computer</b>
                                                <span class="ft-inquiry-attachment-browse">Browse files</span>
                                            @endif
                                            <small>{{ \App\Support\AttachmentUpload::helperText(20) }} · {{ $taskDocumentRemainingSlots }} more allowed · {{ $taskDocumentMax }} maximum per task</small>
                                        </label>
                                    @else
                                        <div class="ft-inquiry-task-document-limit-reached" role="status">
                                            <strong>{{ $taskDocumentMax }}-document limit reached</strong>
                                            <span>Remove a selected or existing task document before adding another file.</span>
                                        </div>
                                    @endif

                                    <div class="ft-inquiry-task-document-upload-progress" x-cloak x-show="uploading" x-transition.opacity.duration.120ms>
                                        <div class="ft-inquiry-upload-progress-meta">
                                            <span>Uploading file{{ $taskDocumentSelectedCount > 1 ? 's' : '' }}...</span>
                                            <b x-text="`${progress}%`">0%</b>
                                        </div>
                                        <div class="ft-inquiry-upload-progress-track" role="progressbar" aria-label="File upload progress" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress">
                                            <span x-bind:style="`width: ${progress}%`"></span>
                                        </div>
                                    </div>

                                    @error('taskDocumentUploads')<p class="ft-inquiry-task-document-error">{{ $message }}</p>@enderror
                                    @error('taskDocumentUploads.*')<p class="ft-inquiry-task-document-error">{{ $message }}</p>@enderror
                                </div>
                            @endif

                            <label class="ft-inquiry-task-document-note">
                                <span>Document note (optional){{ $taskDocumentSelectedCount > 1 ? ' · applies to all selected files' : '' }}</span>
                                <input type="text" wire:model="taskDocumentNote" placeholder="Add a short note about {{ $taskDocumentSelectedCount > 1 ? 'these documents' : 'this document' }}...">
                            </label>
                            @error('taskDocumentNote')<p class="ft-inquiry-task-document-error">{{ $message }}</p>@enderror

                            <div class="ft-inquiry-task-document-info">
                                <span>ⓘ</span>
                                <p>
                                    {{ $taskDocumentSelectedCount === 1 ? 'This document' : 'These documents' }} will appear directly under <strong>{{ $taskDocumentModalTask->title }}</strong>.
                                    @if($completeAfterTaskDocument) Saving the complete selected batch will also mark the task as Completed. @elseif($taskDocumentModalTask->completed_at) Adding documents will not reopen or change the completed task. @endif
                                </p>
                            </div>
                        </div>

                        <footer class="ft-inquiry-task-document-modal-actions">
                            <button type="button" class="secondary" wire:click="closeTaskDocumentModal">Cancel</button>
                            <button type="button" class="primary" wire:click="saveTaskDocument" wire:loading.attr="disabled" wire:target="saveTaskDocument,taskDocumentUploads"
                                @disabled($taskDocumentSelectedCount === 0)>
                                <span wire:loading.remove wire:target="saveTaskDocument">{{ $taskDocumentSelectedCount > 0 ? 'Add '.$taskDocumentSelectedCount.' document'.($taskDocumentSelectedCount === 1 ? '' : 's') : 'Add document' }}</span>
                                <span wire:loading wire:target="saveTaskDocument">{{ $completeAfterTaskDocument ? 'Adding & completing...' : 'Adding...' }}</span>
                            </button>
                        </footer>
                    </section>
                </div>
            @endif

            @if($showInquiryAttentionModal)
                <div class="ft-inquiry-attention-modal-backdrop" wire:key="inquiry-attention-modal" wire:click.self="closeInquiryAttentionReason">
                    <section class="ft-inquiry-attention-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="inquiry-attention-modal-title">
                        <header class="ft-inquiry-attention-modal-head">
                            <div>
                                <h2 id="inquiry-attention-modal-title">Request attention</h2>
                                <p>{{ $inquiry->inquiry_number }} · Admin, Super Admin and the Inquiry creator will be notified.</p>
                            </div>
                            <button type="button" class="ft-inquiry-attention-modal-close" wire:click="closeInquiryAttentionReason" aria-label="Close">×</button>
                        </header>
                        <div class="ft-inquiry-attention-modal-body ft-mention-host">
                            <label for="inquiry-attention-reason">Reason for flag *</label>
                            <textarea id="inquiry-attention-reason" class="ft-mention-input" wire:model="inquiryAttentionReason" rows="5" maxlength="2000" autocomplete="off" data-mention-users="{{ json_encode($inquiryMentionUsers->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" placeholder="Explain what needs attention. Type @ to mention a user..."></textarea>
                            @error('inquiryAttentionReason')<p class="ft-inquiry-attention-modal-error">{{ $message }}</p>@enderror
                            <p class="ft-inquiry-attention-modal-help">The reason is added to Inquiry comments. Use <b>@</b> to mention specific users in addition to the automatic Admin/Super Admin/creator notification.</p>
                        </div>
                        <footer class="ft-inquiry-attention-modal-actions">
                            @if($inquiry->needs_attention)<button type="button" class="ft-inquiry-attention-clear" wire:click="clearInquiryAttention" wire:loading.attr="disabled" wire:target="clearInquiryAttention">Clear flag</button>@else<span></span>@endif
                            <div>
                                <button type="button" class="secondary" wire:click="closeInquiryAttentionReason">Cancel</button>
                                <button type="button" class="primary" wire:click="saveInquiryAttentionReason" wire:loading.attr="disabled" wire:target="saveInquiryAttentionReason">
                                    <span wire:loading.remove wire:target="saveInquiryAttentionReason">Request attention</span>
                                    <span wire:loading wire:target="saveInquiryAttentionReason">Saving...</span>
                                </button>
                            </div>
                        </footer>
                    </section>
                </div>
            @endif

            @if($showTaskAttentionModal && $taskAttentionTaskId)
                @php
                    $attentionTask = $inquiry->tasks->firstWhere('id', (int) $taskAttentionTaskId);
                @endphp
                <div class="ft-inquiry-attention-modal-backdrop" wire:key="inquiry-task-attention-modal" wire:click.self="closeTaskAttentionReason">
                    <section class="ft-inquiry-attention-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="task-attention-modal-title">
                        <header class="ft-inquiry-attention-modal-head">
                            <div>
                                <h2 id="task-attention-modal-title">Why is attention required?</h2>
                                <p>{{ $attentionTask?->title ?: 'Inquiry task' }} · {{ $attentionTask?->status ?: 'Attention required' }}</p>
                            </div>
                            <button type="button" class="ft-inquiry-attention-modal-close" wire:click="closeTaskAttentionReason" aria-label="Close">×</button>
                        </header>
                        <div class="ft-inquiry-attention-modal-body ft-mention-host">
                            <label for="inquiry-task-attention-reason">Reason for flag *</label>
                            <textarea id="inquiry-task-attention-reason" class="ft-mention-input" wire:model="taskAttentionReason" rows="5" maxlength="2000" autocomplete="off" data-mention-users="{{ json_encode($inquiryMentionUsers->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" placeholder="Explain what is blocking the task or what needs attention. Type @ to mention a user..."></textarea>
                            @error('taskAttentionReason')<p class="ft-inquiry-attention-modal-error">{{ $message }}</p>@enderror
                            <p class="ft-inquiry-attention-modal-help">Saving this reason adds it to Inquiry comments, notifies Admin/Super Admin/the Inquiry creator, and supports <b>@mentions</b>.</p>
                        </div>
                        <footer class="ft-inquiry-attention-modal-actions">
                            <button type="button" class="secondary" wire:click="closeTaskAttentionReason">Cancel</button>
                            <button type="button" class="primary" wire:click="saveTaskAttentionReason" wire:loading.attr="disabled" wire:target="saveTaskAttentionReason">
                                <span wire:loading.remove wire:target="saveTaskAttentionReason">Save reason</span>
                                <span wire:loading wire:target="saveTaskAttentionReason">Saving...</span>
                            </button>
                        </footer>
                    </section>
                </div>
            @endif

        </section>
