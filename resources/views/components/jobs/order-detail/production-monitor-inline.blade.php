@props([
    'task',
    'initialDate' => '',
    'errorPrefix',
])

<div class="ft-production-monitor-inline" aria-label="Monitor or resolve production issue details">
    <div class="ft-production-monitor-date-row">
        <div class="ft-production-monitor-date-label">
            Supplier Delivery Date <em>(Optional)</em>
        </div>

        <div class="ft-production-monitor-date-control">
            <input
                type="date"
                x-model="productionSupplierDate"
                class="ft-production-monitor-date-input ft-prototype-clickable-date"
                onclick="this.focus({ preventScroll: true }); if (typeof this.showPicker === 'function') { try { this.showPicker(); } catch (e) {} }"
                aria-label="Supplier Delivery Date. Click anywhere in the field to open the calendar."
            >
            @error($errorPrefix.'.supplier_delivery_date')
                <small class="validation-error">{{ $message }}</small>
            @enderror
        </div>

    </div>

    <label class="ft-production-monitor-field ft-production-monitor-note-field">
        <span>Production Issue Note <em>(Optional)</em></span>
        <textarea
            rows="4"
            maxlength="10000"
            x-model="productionIssueNote"
            placeholder="Add note about the production issue, resolution, or communication with supplier..."
        ></textarea>
        @error($errorPrefix.'.production_issue_note')
            <small class="validation-error">{{ $message }}</small>
        @enderror
    </label>

    @error($errorPrefix.'.task')
        <div class="validation-error ft-production-monitor-task-error">{{ $message }}</div>
    @enderror
</div>
