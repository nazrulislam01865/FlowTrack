    @if($showModal && !in_array($group, ['product', 'product_category'], true))
        <div class="overlay livewire-overlay" wire:click.self="close"></div>
        <div class="modal livewire-modal ft-master-modal" data-ft-feedback-scope="form">
            <div class="modal-head">
                <div>
                    <h2>{{ $editId ? 'Edit' : 'Add' }} {{ ucfirst($singularLabel) }}</h2>
                    <p>{{ $editId ? 'Update this master data record.' : 'Create a new '.$singularLabel.' for FlowTrack.' }}</p>
                </div>
                <button class="close-btn" wire:click="close" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label>Code</label>
                        <div class="ft-admin-locked">{{ $code }}</div>
                        <small class="small muted">{{ $editId ? 'System code is permanently locked.' : 'Automatically generated and permanently locked.' }}</small>
                        @error('code')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    @if($group !== 'remote_area')
                        <div class="field">
                            <label>{{ $group === 'phone_country_code' ? 'Phone code *' : ($group === 'task_pack_work_calendar' ? 'Calendar name *' : 'Name *') }}</label>
                            <input wire:model="name" @if($group === 'phone_country_code') placeholder="e.g. +880" inputmode="tel" @elseif($group === 'task_pack_work_calendar') placeholder="e.g. Workspace hours" @endif>
                            @error('name')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    @if($group === 'remote_area')
                        @php
                            $originSurchargeOptions = ['No', 'Extended Area Surcharge', 'Pickup Area Surcharge', 'Pickup Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'];
                            $destinationSurchargeOptions = ['No', 'Extended Area Surcharge', 'Delivery Area Surcharge', 'Delivery Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'];
                        @endphp
                        <div class="field">
                            <label>Carrier *</label>
                            <input wire:model.blur="remoteAreaCarrier" maxlength="80" placeholder="e.g. UPS">
                            @error('remoteAreaCarrier')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Country *</label>
                            <input wire:model.blur="remoteAreaCountry" maxlength="120" autocomplete="country-name" placeholder="e.g. United States">
                            @error('remoteAreaCountry')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>IATA code *</label>
                            <input wire:model.blur="remoteAreaIataCode" maxlength="2" autocapitalize="characters" placeholder="e.g. US">
                            @error('remoteAreaIataCode')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>City <span class="small muted">(optional when postal code is used)</span></label>
                            <input wire:model.blur="remoteAreaCity" maxlength="120" autocomplete="address-level2" placeholder="e.g. Kuala Belait">
                            @error('remoteAreaCity')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Postal Code From <span class="small muted">(or use City)</span></label>
                            <input wire:model.blur="remoteAreaPostalCodeFrom" maxlength="32" autocomplete="postal-code" placeholder="e.g. 68862">
                            @error('remoteAreaPostalCodeFrom')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Postal Code To <span class="small muted">(optional for one code)</span></label>
                            <input wire:model.blur="remoteAreaPostalCodeTo" maxlength="32" autocomplete="postal-code" placeholder="e.g. 68866">
                            @error('remoteAreaPostalCodeTo')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Origin surcharge *</label>
                            <select wire:model="remoteAreaOriginSurcharge">
                                @foreach($originSurchargeOptions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                            </select>
                            @error('remoteAreaOriginSurcharge')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Destination surcharge *</label>
                            <select wire:model="remoteAreaDestinationSurcharge">
                                @foreach($destinationSurchargeOptions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                            </select>
                            @error('remoteAreaDestinationSurcharge')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Extra charge <span class="small muted">(optional)</span></label>
                            <input type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" wire:model.blur="remoteAreaExtraCharge" placeholder="0.00">
                            <small class="small muted">Existing FlowTrack billing amount. The UPS sheet defines surcharge type, not the monetary rate.</small>
                            @error('remoteAreaExtraCharge')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Display label</label>
                            <div class="ft-admin-locked">Generated automatically from country and postal range / city.</div>
                        </div>
                    @endif

                    @if($group === 'task_pack_work_calendar')
                        @php
                            $workCalendarDays = [
                                'monday' => 'Monday',
                                'tuesday' => 'Tuesday',
                                'wednesday' => 'Wednesday',
                                'thursday' => 'Thursday',
                                'friday' => 'Friday',
                                'saturday' => 'Saturday',
                                'sunday' => 'Sunday',
                            ];
                        @endphp
                        <section class="field full ft-work-calendar-editor" aria-label="Work calendar schedule">
                            <div class="ft-work-calendar-editor-head">
                                <div class="ft-work-calendar-editor-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                                </div>
                                <div>
                                    <strong>Working schedule</strong>
                                    <small>Set the working-day range and daily working hours used by this calendar.</small>
                                </div>
                            </div>

                            <div class="ft-work-calendar-grid">
                                <div class="ft-work-calendar-field">
                                    <label>Day from *</label>
                                    <select wire:model.live="workCalendarDayFrom">
                                        @foreach($workCalendarDays as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                    @error('workCalendarDayFrom')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="ft-work-calendar-field">
                                    <label>Day to *</label>
                                    <select wire:model.live="workCalendarDayTo">
                                        @foreach($workCalendarDays as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                    @error('workCalendarDayTo')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="ft-work-calendar-field">
                                    <label>Time from *</label>
                                    <input type="time" wire:model.live="workCalendarTimeFrom">
                                    @error('workCalendarTimeFrom')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="ft-work-calendar-field">
                                    <label>Time to *</label>
                                    <input type="time" wire:model.live="workCalendarTimeTo">
                                    @error('workCalendarTimeTo')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <div class="ft-work-calendar-preview">
                                <span>Calendar preview</span>
                                <strong>{{ $workCalendarDays[$workCalendarDayFrom] ?? ucfirst($workCalendarDayFrom) }} → {{ $workCalendarDays[$workCalendarDayTo] ?? ucfirst($workCalendarDayTo) }}</strong>
                                <i></i>
                                <strong>{{ $workCalendarTimeFrom ?: '—' }} → {{ $workCalendarTimeTo ?: '—' }}</strong>
                            </div>
                        </section>
                    @endif

                    @if($group === 'inquiry_task_status')
                        <div class="field">
                            <label>Inquiry status auto *</label>
                            <select wire:model="autoInquiryStatus">
                                <option value="To do">To do</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="__task_status__">Use task status name</option>
                            </select>
                            <small class="small muted">This value automatically becomes the parent Inquiry status while this task is current.</small>
                            @error('autoInquiryStatus')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label>Attention flag</label>
                            <select wire:model.boolean="requiresAttention">
                                <option value="0">Not needed</option>
                                <option value="1">Requires attention</option>
                            </select>
                            <small class="small muted">When enabled, the task shows an Attention required link and asks for a reason.</small>
                            @error('requiresAttention')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    @if($group === 'order_task_status')
                        <div class="field">
                            <label>Automatic Order Task Flag</label>
                            <select wire:model="orderTaskFlagId">
                                <option value="">No flag</option>
                                @foreach($orderTaskFlagOptions as $flag)
                                    <option value="{{ $flag->id }}">{{ $flag->name }}</option>
                                @endforeach
                            </select>
                            <small class="small muted">When a task uses this status, this flag is applied automatically. An overdue due date overrides this mapping with the system Overdue flag.</small>
                            @error('orderTaskFlagId')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    @if($group === 'order_task_flag')
                        <div class="field">
                            <label>Parent Order Flag *</label>
                            <select wire:model="orderFlagId">
                                <option value="">Select order flag</option>
                                @foreach($orderFlagOptions as $flag)
                                    <option value="{{ $flag->id }}">{{ $flag->name }}</option>
                                @endforeach
                            </select>
                            <small class="small muted">When this task flag is active, the mapped Order Flag is stored on the parent Order.</small>
                            @error('orderFlagId')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    @if($group === 'product')
                        <div class="field full ft-master-product-image-field">
                            <label>Product image</label>
                            <div class="ft-master-product-image-editor">
                                <div class="ft-master-product-image-preview">
                                    @if($productImagePreview)
                                        <img src="{{ $productImagePreview }}" alt="Product image preview">
                                    @else
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65"><path d="M20 12 12 20 4 12V4h8l8 8Z"/><circle cx="8.5" cy="8.5" r="1.2"/></svg>
                                    @endif
                                </div>
                                <div class="ft-master-product-image-actions">
                                    <label class="ft-master-file-button">
                                        <input type="file" wire:model="productImage" accept="image/png,image/jpeg,image/webp">
                                        <span wire:loading.remove wire:target="productImage">{{ $productImagePreview ? 'Replace image' : 'Upload image' }}</span><span wire:loading wire:target="productImage">Preparing…</span>
                                    </label>
                                    @if($existingProductImageUrl && !$removeProductImage && !$productImage)
                                        <button type="button" class="ft-master-remove-image" wire:click="markProductImageForRemoval">Remove</button>
                                    @elseif($removeProductImage)
                                        <button type="button" class="ft-master-remove-image" wire:click="restoreProductImage">Undo remove</button>
                                    @endif
                                    <small>PNG, JPG or WEBP up to 5 MB.</small>
                                </div>
                            </div>
                            @error('productImage')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    @if($group === 'product')
                        <div class="field">
                            <label>Product category</label>
                            <select wire:model="parentId">
                                <option value="">No category</option>
                                @foreach($parents as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                            @error('parentId')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @elseif($group === 'state')
                        <div class="field">
                            <label>Country *</label>
                            <select wire:model="parentId">
                                <option value="">Select country</option>
                                @foreach($parents as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                            @error('parentId')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    <div class="field">
                        <label>Status</label>
                        <select wire:model="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                    <div class="field">
                        <label>Sort order</label>
                        <input type="number" min="0" wire:model="sortOrder">
                    </div>

                    @if($hasColor)
                        <div class="field full">
                            <label>Color *</label>
                            <div class="ft-master-color-picker-row" style="{{ \App\Support\MasterColor::style($color) }}">
                                <x-setup.color-picker model="color" :label="'Choose '.($labels[$group] ?? 'master data').' color'" />
                                <input type="text" maxlength="7" wire:model.blur="color" placeholder="#2563EB" aria-label="Hex color code">
                                <span class="ft-master-color-preview"><i class="ft-master-color-dot"></i><span>This color will be used for {{ $colorUsageLabel }} labels across FlowTrack.</span></span>
                            </div>
                            @error('color')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    <div class="field full">
                        <label>{{ $group === 'phone_country_code' ? 'Country / label' : ($group === 'remote_area' ? 'Notes' : 'Description') }}</label>
                        <textarea wire:model="description" rows="3" @if($group === 'phone_country_code') placeholder="e.g. Bangladesh" @elseif($group === 'remote_area') placeholder="Optional notes about this remote delivery area" @endif></textarea>
                        @error('description')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="ghost" wire:click="close">Cancel</button>
                <button class="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,productImage">Save {{ ucfirst($singularLabel) }}</button>
            </div>
        </div>
    @endif
