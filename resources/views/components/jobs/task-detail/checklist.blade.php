            <section class="ft-detail-card ft-checklist-card" x-data="{adding:false}">
                <div class="ft-card-row-head"><div class="ft-check-title"><h2>Checklist</h2><span>{{ $done }} of {{ $total }} complete</span><div class="ft-small-progress"><span style="width:{{ $done / $checkTotal * 100 }}%"></span></div></div>@if($canEditTask)<button class="ft-link-blue" type="button" x-on:click="adding=!adding">＋ Add item</button>@endif</div>
                @if($canEditTask)
                    <div class="ft-checklist-add-row" x-show="adding"><input wire:model="newChecklistItem" wire:keydown.enter="addTaskChecklistItem" placeholder="Checklist item"><button type="button" class="ft-new-job-btn" wire:click="addTaskChecklistItem" x-on:click="adding=false">Add</button><button type="button" class="ft-outline-btn" x-on:click="adding=false">Cancel</button></div>
                    @error('newChecklistItem')
                        <div class="validation-error ft-checklist-validation">{{ $message }}</div>
                    @enderror
                @endif
                @forelse($task->checklistItems->sortBy('sort_order') as $item)
                    <div class="ft-checklist-row">
                        <input type="checkbox" @checked($item->is_completed) @disabled(!$canCheck) wire:change="toggleTaskChecklistItem({{ $item->id }}, $event.target.checked)">
                        <span class="{{ $item->is_completed ? 'completed' : '' }}">{{ $item->label }}</span>
                        @if($canEditTask)<button type="button" class="ft-checklist-delete" title="Delete checklist item" wire:click="deleteTaskChecklistItem({{ $item->id }})" wire:confirm="Delete this checklist item?">×</button>@else<span></span>@endif
                    </div>
                @empty<div class="empty-state">No checklist items configured.</div>@endforelse
                @unless($canCheck)<p class="ft-checklist-permission-note">Only the assigned person can check or uncheck checklist items.</p>@endunless
            </section>
