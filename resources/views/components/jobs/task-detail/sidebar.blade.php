            <section class="ft-detail-card ft-management-card">
                <h2>Management attention</h2>
                <div class="ft-attention-row"><span>Required evidence</span><b><span class="{{ $taskDocumentName ? 'ft-red-doc-icon' : '' }}">▯</span> {{ $taskDocumentName ?: 'No required evidence' }}</b></div>
                <div class="ft-attention-row ft-task-flag-row">
                    <span>Automatic flag</span>
                    <b class="ft-runtime-flag-pill {{ $currentTaskFlagColor ? 'ft-master-color' : ($currentTaskFlag ? 'danger-text' : '') }}" style="{{ \App\Support\MasterColor::style($currentTaskFlagColor) }}"><span class="{{ $currentTaskFlag ? 'ft-red-flag' : '' }}">⚑</span> {{ $currentTaskFlag ?: 'No flag' }}</b>
                </div>
                <small class="ft-task-flag-help">Driven automatically by Order Task Status Master Data. Overdue overrides the status mapping after the due date passes.</small>
                @if($currentTaskFlag && filled($task->attention_reason))
                    <div class="ft-attention-row"><span>Flag reason</span><b>{{ $task->attention_reason }}</b></div>
                @endif
            </section>

            <section class="ft-detail-card ft-job-context-card"><h2>Order context</h2><b>{{ $job?->title }}</b><div><span>Client</span><b>{{ $job?->client?->name }}</b></div><div><span>Order flag</span><b class="ft-runtime-flag-pill {{ $currentOrderFlagColor ? 'ft-master-color' : ($currentOrderFlag ? 'danger-text' : '') }}" style="{{ \App\Support\MasterColor::style($currentOrderFlagColor) }}"><span class="{{ $currentOrderFlag ? 'ft-red-flag' : '' }}">⚑</span> {{ $currentOrderFlag ?: 'No flag' }}</b></div><div><span>Delivery</span><b>{{ $job?->delivery_date?->format('M j, Y') ?? '—' }}</b></div><div class="ft-context-progress"><span>Order progress</span><b>{{ $job?->progress }}%</b><div class="ft-line-progress"><span style="width:{{ $job?->progress ?? 0 }}%"></span></div></div><button class="ft-link-blue ft-open-job" wire:click="closeTask">Open order details ↗</button></section>
