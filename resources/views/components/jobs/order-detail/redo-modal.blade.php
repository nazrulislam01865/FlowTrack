@props(['job', 'context' => [], 'form' => [], 'mentionUsers' => collect()])
@php
    $step = (int) ($form['step'] ?? 1);
    $preview = $form['preview'] ?? [];
    $suppliers = collect($form['supplierOptions'] ?? []);
    $evidence = collect($form['evidence'] ?? []);
    $redoMentionUsers = collect($mentionUsers)->values();
    $currency = (string) ($job->currency ?: 'USD');
    $money = fn ($value) => ($currency === 'USD' ? '$' : $currency.' ').number_format((float) $value, 2);
    $redoIssueSource = (string) ($form['issueSource'] ?? 'Customer');
    $redoIssueCategory = (string) ($form['issueCategory'] ?? 'Artwork / production mismatch');
    $redoAffectedQuantity = (int) ($form['affectedQuantity'] ?? 1);
    $redoIssueDescription = (string) ($form['issueDescription'] ?? '');
    $redoScope = (string) ($form['scope'] ?? 'artwork');
    $redoQuantity = (int) ($form['quantity'] ?? 1);
    $redoSupplierId = $form['supplierId'] ?? null;
    $redoInstructions = (string) ($form['instructions'] ?? '');
    $redoCustomerResolution = (string) ($form['customerResolution'] ?? 'free');
    $redoCustomerAdjustmentType = (string) ($form['customerAdjustmentType'] ?? 'percent');
    $redoCustomerDiscount = (string) ($form['customerDiscount'] ?? '20');
    $redoSupplierAdjustmentType = (string) ($form['supplierAdjustmentType'] ?? 'percent');
    $redoSupplierChargePercent = (string) ($form['supplierChargePercent'] ?? '40');
    $redoDeductFreight = (bool) ($form['deductFreight'] ?? true);
    $redoSupplierFreightAmount = (string) ($form['supplierFreightAmount'] ?? '');
    $redoCourierFreightAmount = (string) ($form['courierFreightAmount'] ?? '');
    $nextSequence = ((int) ($context['redoOrderCount'] ?? $context['redoCount'] ?? 0)) + 1;
    $nextOrderNumber = $job->displayOrderNumber().'-R'.$nextSequence;
    $isDiscountScope = $redoScope === 'discount';
    $scopeLabel = match ($redoScope) {
        'production' => 'Production phase → QC & Dispatch',
        'discount' => 'No workflow restart · financial adjustment only',
        default => 'Artwork phase → Production → QC & Dispatch',
    };
    $supplierUnit = $redoSupplierAdjustmentType === 'pcs' ? ' pcs' : '%';
    $customerLabel = $isDiscountScope || $redoCustomerResolution === 'discount'
        ? ($redoCustomerAdjustmentType === 'pcs'
            ? $redoCustomerDiscount.' pcs missing quantity deduction'
            : $redoCustomerDiscount.'% customer adjustment')
        : 'Free redo';
    $recoveryLabel = $redoSupplierChargePercent.$supplierUnit.' supplier recovery'
        .(($preview['supplierFreight'] ?? 0) > 0 ? ' + '.$money($preview['supplierFreight']).' supplier freight deduction' : '');
@endphp

@if((bool) ($form['show'] ?? false))
    <div class="ft-redo-modalwrap show" wire:key="order-redo-modal" wire:click.self="closeRedoModal">
        <section class="ft-redo-modal" role="dialog" aria-modal="true" aria-labelledby="order-redo-modal-title">
            <header class="ft-redo-modalhead">
                <div>
                    <h2 id="order-redo-modal-title">Initiate redo</h2>
                    <div class="sub">Create a controlled redo or resolve the issue with a customer financial adjustment.</div>
                </div>
                <button type="button" class="ft-redo-close" wire:click="closeRedoModal" aria-label="Close">×</button>
            </header>

            <div class="ft-redo-steps" aria-hidden="true">
                @for($i = 1; $i <= 4; $i++)
                    <i class="ft-redo-step {{ $i <= $step ? 'on' : '' }}"></i>
                @endfor
            </div>
            <div class="ft-redo-stepnames">
                <span class="{{ $step === 1 ? 'on' : '' }}">1 · Issue</span>
                <span class="{{ $step === 2 ? 'on' : '' }}">2 · Scope</span>
                <span class="{{ $step === 3 ? 'on' : '' }}">3 · Commercial</span>
                <span class="{{ $step === 4 ? 'on' : '' }}">4 · Confirm</span>
            </div>

            <div class="ft-redo-modalbody">
                @if($step === 1)
                    <div class="ft-redo-pane show">
                        <h3>Record the redo issue</h3>
                        <p>Capture who reported the issue and why the order needs corrective action.</p>

                        <div class="ft-redo-formgrid">
                            <label class="ft-redo-field">
                                <span>Issue reported by *</span>
                                <select wire:model="redoIssueSource">
                                    <option>Customer</option>
                                    <option>Quality Control</option>
                                    <option>Internal Team</option>
                                </select>
                                @error('redoIssueSource')<small class="validation-error">{{ $message }}</small>@enderror
                            </label>

                            <label class="ft-redo-field">
                                <span>Issue category *</span>
                                <select wire:model="redoIssueCategory">
                                    <option>Artwork / production mismatch</option>
                                    <option>Production quality defect</option>
                                    <option>Incorrect specification</option>
                                    <option>Wrong quantity or size</option>
                                    <option>Shipping damage</option>
                                    <option>Other</option>
                                </select>
                                @error('redoIssueCategory')<small class="validation-error">{{ $message }}</small>@enderror
                            </label>

                            <label class="ft-redo-field">
                                <span>Reported date</span>
                                <input value="{{ app(\App\Services\WorkspaceSettingsService::class)->localToday()->format('M j, Y') }}" disabled>
                            </label>

                            <label class="ft-redo-field">
                                <span>Affected quantity *</span>
                                <input type="number" min="1" max="{{ max(1, (int) $job->quantity) }}" wire:model.blur="redoAffectedQuantity">
                                @error('redoAffectedQuantity')<small class="validation-error">{{ $message }}</small>@enderror
                            </label>

                            {{--
                                Rich text must NOT be wrapped by a <label>. The shared editor
                                inserts a contenteditable element beside the hidden textarea.
                                A wrapping label forwards clicks back to the textarea and steals
                                focus from the contenteditable surface, which makes typing appear
                                broken. Keep the field container non-label and expose an explicit
                                accessible label on the textarea instead.
                            --}}
                            <div class="ft-redo-field wide ft-mention-host">
                                <span id="redo-issue-description-label">Issue description *</span>
                                <textarea
                                    class="ft-mention-input"
                                    data-rich-text
                                    rows="5"
                                    wire:model="redoIssueDescription"
                                    autocomplete="off"
                                    aria-labelledby="redo-issue-description-label"
                                    data-mention-users="{{ json_encode($redoMentionUsers->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
                                    placeholder="Describe the customer/QC issue. Type @ to mention someone, paste an image/screenshot, or use the editor tools."
                                ></textarea>
                                @error('redoIssueDescription')<small class="validation-error">{{ $message }}</small>@enderror
                            </div>

                            <div class="ft-redo-field wide">
                                <span>Evidence</span>
                                <div class="ft-redo-choice selected ft-redo-evidence">
                                    <span aria-hidden="true">📎</span>
                                    <div>
                                        @if($evidence->isNotEmpty())
                                            <b>{{ $evidence->first() }}</b>
                                            <small>Latest artwork attached to the source Order. Archived artwork versions are not shown here.</small>
                                        @else
                                            <b>No latest artwork available</b>
                                            <small>Upload artwork on the source Order before using it as Redo evidence.</small>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @elseif($step === 2)
                    <div class="ft-redo-pane show">
                        <h3>Choose the redo scope</h3>
                        <p>Choose where the workflow restarts, or resolve the issue financially without creating a redo order.</p>

                        <div class="ft-redo-choicegrid">
                            <label class="ft-redo-choice {{ $redoScope === 'artwork' ? 'selected' : '' }}">
                                <input type="radio" value="artwork" wire:model.live="redoScope">
                                <div>
                                    <b>Artwork + production redo</b>
                                    <small>Reopen Artwork, require revised approval, then repeat Production and QC.</small>
                                </div>
                            </label>
                            <label class="ft-redo-choice {{ $redoScope === 'production' ? 'selected' : '' }}">
                                <input type="radio" value="production" wire:model.live="redoScope">
                                <div>
                                    <b>Production-only redo</b>
                                    <small>Keep the existing approved artwork and restart directly from Production.</small>
                                </div>
                            </label>
                            <label class="ft-redo-choice {{ $redoScope === 'discount' ? 'selected' : '' }}">
                                <input type="radio" value="discount" wire:model.live="redoScope">
                                <div>
                                    <b>No redo / customer adjustment</b>
                                    <small>Do not restart workflow. Use either a % customer adjustment or pcs for a missing-quantity deduction.</small>
                                </div>
                            </label>
                        </div>
                        @error('redoScope')<p class="validation-error">{{ $message }}</p>@enderror

                        <div class="ft-redo-formgrid ft-redo-formgrid-spaced">
                            @if($isDiscountScope)
                                <div class="ft-redo-discount-note wide">
                                    <b>No workflow restart</b>
                                    <small>{{ number_format($redoAffectedQuantity) }} affected unit{{ $redoAffectedQuantity === 1 ? '' : 's' }} can be adjusted by % or missing quantity (pcs). No redo Order or redo tasks will be created.</small>
                                </div>
                            @else
                                <label class="ft-redo-field">
                                    <span>Redo quantity</span>
                                    <input type="number" min="1" max="{{ max(1, (int) $job->quantity) }}" wire:model.live.debounce.250ms="redoQuantity">
                                    @error('redoQuantity')<small class="validation-error">{{ $message }}</small>@enderror
                                </label>

                                <label class="ft-redo-field">
                                    <span>Responsible supplier</span>
                                    <select wire:model="redoSupplierId">
                                        <option value="">Supplier not decided</option>
                                        @foreach($suppliers as $supplier)
                                            <option value="{{ $supplier['id'] }}">{{ $supplier['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('redoSupplierId')<small class="validation-error">{{ $message }}</small>@enderror
                                </label>
                            @endif

                            <label class="ft-redo-field wide">
                                <span>{{ $isDiscountScope ? 'Internal note' : 'Internal instructions' }}</span>
                                <textarea rows="4" wire:model="redoInstructions" placeholder="{{ $isDiscountScope ? 'Reason or approval note for the customer adjustment.' : 'Instructions for revised artwork, replacement production and repeat QC.' }}"></textarea>
                                @error('redoInstructions')<small class="validation-error">{{ $message }}</small>@enderror
                            </label>
                        </div>
                    </div>
                @elseif($step === 3)
                    <div class="ft-redo-pane show">
                        <h3>Set customer and supplier adjustments</h3>
                        <p>Choose either percentage or pieces. In no-redo mode, Customer + pcs is treated as missing quantity and its value is deducted from the order total.</p>

                        <div class="ft-redo-formgrid">
                            <div>
                                @if($isDiscountScope)
                                    <div class="ft-redo-fixed-resolution">
                                        <span>Customer resolution</span>
                                        <b>{{ $redoCustomerAdjustmentType === 'pcs' ? 'Missing quantity · no redo' : 'Customer adjustment · no redo' }}</b>
                                        <small>
                                            {{ $redoCustomerAdjustmentType === 'pcs'
                                                ? 'Missing quantity is priced using the current unit value. No replacement Order or workflow restart will be created.'
                                                : 'A percentage adjustment will be recorded. No replacement Order or workflow restart will be created.' }}
                                        </small>
                                    </div>
                                @else
                                    <label class="ft-redo-field">
                                        <span>Customer resolution *</span>
                                        <select wire:model.live="redoCustomerResolution">
                                            <option value="free">Free redo</option>
                                            <option value="discount">Customer adjustment</option>
                                        </select>
                                        @error('redoCustomerResolution')<small class="validation-error">{{ $message }}</small>@enderror
                                    </label>
                                @endif

                                @if($isDiscountScope || $redoCustomerResolution === 'discount')
                                    <div class="ft-redo-adjustment-card" wire:key="redo-{{ $redoScope }}-customer-adjustment">
                                        <div class="ft-redo-adjustment-head">
                                            <div>
                                                <span class="ft-redo-adjustment-title">Customer</span>
                                                <small>{{ $redoCustomerAdjustmentType === 'pcs' ? 'Enter the missing quantity to deduct.' : 'Enter the customer deduction percentage.' }}</small>
                                            </div>
                                            <span class="ft-redo-required-pill">Required</span>
                                        </div>

                                        <div class="ft-redo-adjustment-control">
                                            <div class="ft-redo-adjustment-value">
                                                <span>{{ $redoCustomerAdjustmentType === 'pcs' ? 'Missing qty' : 'Adjustment' }}</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="{{ $redoCustomerAdjustmentType === 'percent' ? 100 : max(1, $isDiscountScope ? $redoAffectedQuantity : $redoQuantity) }}"
                                                    step="{{ $redoCustomerAdjustmentType === 'percent' ? '0.01' : '1' }}"
                                                    inputmode="{{ $redoCustomerAdjustmentType === 'percent' ? 'decimal' : 'numeric' }}"
                                                    wire:model.live.debounce.250ms="redoCustomerDiscount"
                                                    placeholder="{{ $redoCustomerAdjustmentType === 'pcs' ? 'e.g. 10' : 'e.g. 20' }}"
                                                    aria-label="Customer adjustment value"
                                                >
                                            </div>

                                            <div class="ft-redo-unit-toggle" role="group" aria-label="Customer adjustment unit">
                                                <label class="{{ $redoCustomerAdjustmentType === 'percent' ? 'active' : '' }}">
                                                    <input type="radio" name="redo-customer-adjustment-type" value="percent" wire:model.live="redoCustomerAdjustmentType">
                                                    <span>%</span>
                                                </label>
                                                <label class="{{ $redoCustomerAdjustmentType === 'pcs' ? 'active' : '' }}">
                                                    <input type="radio" name="redo-customer-adjustment-type" value="pcs" wire:model.live="redoCustomerAdjustmentType">
                                                    <span>pcs</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="ft-redo-adjustment-note {{ $redoCustomerAdjustmentType === 'pcs' ? 'is-missing' : '' }}">
                                            <span class="ft-redo-adjustment-note-dot" aria-hidden="true"></span>
                                            <span>
                                                @if($redoCustomerAdjustmentType === 'pcs')
                                                    <b>Missing quantity.</b> The selected pcs are deducted from the order total using the current unit value.
                                                @else
                                                    Percentage is calculated from the affected order value.
                                                @endif
                                            </span>
                                        </div>

                                        @error('redoCustomerDiscount')<small class="validation-error">{{ $message }}</small>@enderror
                                        @error('redoCustomerAdjustmentType')<small class="validation-error">{{ $message }}</small>@enderror
                                        <span class="ft-redo-sr-only">pcs (missing qty)</span>
                                    </div>
                                @endif

                                <div class="ft-redo-adjustment-card">
                                    <div class="ft-redo-adjustment-head">
                                        <div>
                                            <span class="ft-redo-adjustment-title">Supplier</span>
                                            <small>{{ $redoSupplierAdjustmentType === 'pcs' ? 'Enter the quantity to recover from the supplier.' : 'Enter the supplier recovery percentage.' }}</small>
                                        </div>
                                    </div>

                                    <div class="ft-redo-adjustment-control">
                                        <div class="ft-redo-adjustment-value">
                                            <span>{{ $redoSupplierAdjustmentType === 'pcs' ? 'Quantity' : 'Recovery' }}</span>
                                            <input
                                                type="number"
                                                min="0"
                                                max="{{ $redoSupplierAdjustmentType === 'percent' ? 100 : max(1, $isDiscountScope ? $redoAffectedQuantity : $redoQuantity) }}"
                                                step="{{ $redoSupplierAdjustmentType === 'percent' ? '0.01' : '1' }}"
                                                inputmode="{{ $redoSupplierAdjustmentType === 'percent' ? 'decimal' : 'numeric' }}"
                                                wire:model.live.debounce.250ms="redoSupplierChargePercent"
                                                placeholder="{{ $redoSupplierAdjustmentType === 'pcs' ? 'e.g. 10' : 'e.g. 40' }}"
                                                aria-label="Supplier adjustment value"
                                            >
                                        </div>

                                        <div class="ft-redo-unit-toggle" role="group" aria-label="Supplier adjustment unit">
                                            <label class="{{ $redoSupplierAdjustmentType === 'percent' ? 'active' : '' }}">
                                                <input type="radio" name="redo-supplier-adjustment-type" value="percent" wire:model.live="redoSupplierAdjustmentType">
                                                <span>%</span>
                                            </label>
                                            <label class="{{ $redoSupplierAdjustmentType === 'pcs' ? 'active' : '' }}">
                                                <input type="radio" name="redo-supplier-adjustment-type" value="pcs" wire:model.live="redoSupplierAdjustmentType">
                                                <span>pcs</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="ft-redo-adjustment-note">
                                        <span class="ft-redo-adjustment-note-dot" aria-hidden="true"></span>
                                        <span>
                                            {{ $redoSupplierAdjustmentType === 'pcs'
                                                ? 'The selected pcs are converted to a recovery amount using the current unit value.'
                                                : 'Percentage is calculated from the affected order value.' }}
                                        </span>
                                    </div>

                                    @error('redoSupplierChargePercent')<small class="validation-error">{{ $message }}</small>@enderror
                                    @error('redoSupplierAdjustmentType')<small class="validation-error">{{ $message }}</small>@enderror
                                </div>

                                <label class="ft-redo-check">
                                    <input type="checkbox" wire:model.live="redoDeductFreight">
                                    <span>
                                        <b>Deduct freight charge</b>
                                        <small>Enter either or both amounts. Blank or 0 means not applicable.</small>
                                    </span>
                                </label>

                                @if($redoDeductFreight)
                                    <div class="ft-redo-formgrid ft-redo-formgrid-spaced ft-redo-freight-fields">
                                        <label class="ft-redo-field">
                                            <span>Supplier freight amount (optional)</span>
                                            <input type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" wire:model.live.debounce.250ms="redoSupplierFreightAmount">
                                            @error('redoSupplierFreightAmount')<small class="validation-error">{{ $message }}</small>@enderror
                                        </label>
                                        <label class="ft-redo-field">
                                            <span>Courier company freight amount (optional)</span>
                                            <input type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" wire:model.live.debounce.250ms="redoCourierFreightAmount">
                                            @error('redoCourierFreightAmount')<small class="validation-error">{{ $message }}</small>@enderror
                                        </label>
                                    </div>
                                @endif
                            </div>

                            <div class="ft-redo-amounts">
                                <h4>Financial preview</h4>
                                <div class="ft-redo-calc"><span>Order total</span><b>{{ $money($preview['orderValue'] ?? 0) }}</b></div>
                                <div class="ft-redo-calc"><span>Affected order value</span><b>{{ $money($preview['affectedValue'] ?? 0) }}</b></div>
                                <div class="ft-redo-calc"><span>Customer</span><b>{{ $redoCustomerResolution === 'discount' ? '-'.$money($preview['customerImpact'] ?? 0) : $money(0) }}</b></div>
                                @if($redoCustomerResolution === 'discount')
                                    <div class="ft-redo-calc adjusted"><span>Order total after deduction</span><b>{{ $money($preview['adjustedOrderValue'] ?? 0) }}</b></div>
                                @endif
                                <div class="ft-redo-calc"><span>Supplier</span><b>{{ $money($preview['supplierCharge'] ?? 0) }}</b></div>
                                <div class="ft-redo-calc"><span>Supplier freight deduction</span><b>{{ ($preview['supplierFreight'] ?? 0) > 0 ? $money($preview['supplierFreight']) : 'Not applicable' }}</b></div>
                                <div class="ft-redo-calc"><span>Courier company freight deduction</span><b>{{ ($preview['courierRecovery'] ?? 0) > 0 ? $money($preview['courierRecovery']) : 'Not applicable' }}</b></div>
                                <div class="ft-redo-calc total"><span>Total supplier recovery</span><b>{{ $money($preview['recovery'] ?? 0) }}</b></div>
                                @if(($preview['courierRecovery'] ?? 0) > 0)
                                    <div class="ft-redo-calc total"><span>Total courier recovery</span><b>{{ $money($preview['courierRecovery']) }}</b></div>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="ft-redo-pane show">
                        <h3>{{ $isDiscountScope ? ($redoCustomerAdjustmentType === 'pcs' ? 'Review missing quantity deduction' : 'Review customer adjustment') : 'Review and create the redo order' }}</h3>
                        <p>
                            {{ $isDiscountScope
                                ? 'The original Order and its workflow remain exactly where they are. Only the customer/supplier financial adjustment will be recorded.'
                                : 'The original Order remains intact. A linked redo Order and audit record will be created.' }}
                        </p>

                        <div class="ft-redo-confirmbox">
                            @unless($isDiscountScope)
                                <div class="ft-redo-confirmrow"><span>New order number</span><b>{{ $nextOrderNumber }}</b></div>
                            @endunless
                            <div class="ft-redo-confirmrow"><span>Action</span><b>{{ $isDiscountScope ? ($redoCustomerAdjustmentType === 'pcs' ? 'Missing quantity · no redo' : 'Customer adjustment · no redo') : '↻ Redo order' }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Original order</span><b>{{ $job->displayOrderNumber() }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Issue</span><b>{{ $redoIssueSource }} · {{ $redoIssueCategory }} · {{ number_format($isDiscountScope ? $redoAffectedQuantity : $redoQuantity) }} units</b></div>
                            <div class="ft-redo-confirmrow"><span>Workflow restart</span><b>{{ $scopeLabel }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Customer</span><b>{{ $customerLabel }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Supplier</span><b>{{ $recoveryLabel }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Supplier freight</span><b>{{ ($preview['supplierFreight'] ?? 0) > 0 ? $money($preview['supplierFreight']) : 'Not applicable' }}</b></div>
                            <div class="ft-redo-confirmrow"><span>Courier company freight</span><b>{{ ($preview['courierRecovery'] ?? 0) > 0 ? $money($preview['courierRecovery']) : 'Not applicable' }}</b></div>
                        </div>

                        <div class="ft-redo-warning">
                            {{ $isDiscountScope
                                ? ($redoCustomerAdjustmentType === 'pcs'
                                    ? 'No redo Order or replacement tasks will be created. The missing quantity value is deducted from the order total in this financial adjustment.'
                                    : 'No redo Order or replacement tasks will be created. The current workflow stays unchanged and the customer adjustment is recorded financially.')
                                : 'Creating the redo will not change the original invoice or payment. Financial adjustments are stored against the redo Order and can be reviewed in Invoices & Payments.' }}
                        </div>
                    </div>
                @endif
            </div>

            <footer class="ft-redo-modalfoot">
                <span class="sub">Step {{ $step }} of 4</span>
                <div class="ft-redo-modal-actions">
                    <button type="button" class="btn" wire:click="previousRedoStep" wire:loading.attr="disabled" wire:target="nextRedoStep,previousRedoStep,createRedoOrder" @disabled($step === 1)>Back</button>
                    <button type="button" class="btn primary" wire:click="nextRedoStep" wire:loading.attr="disabled" wire:target="nextRedoStep,previousRedoStep,createRedoOrder">
                        <span wire:loading.remove wire:target="nextRedoStep,previousRedoStep,createRedoOrder">{{ $step === 4 ? ($isDiscountScope ? 'Record adjustment' : 'Create redo order') : 'Continue' }}</span>
                        <span wire:loading wire:target="nextRedoStep,previousRedoStep,createRedoOrder">{{ $step === 4 ? ($isDiscountScope ? 'Recording...' : 'Creating...') : 'Working...' }}</span>
                    </button>
                </div>
            </footer>
        </section>
    </div>
@endif
