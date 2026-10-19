        <div class="table-scroll" wire:loading.class="is-loading" wire:target="search,client,owner,phase,metricFilter,setMetricFilter,dateFrom,dateTo,stageQuick,stageSupplier,stageAssignee,stageUrgency,stageCarrier,stageClient,clearFilters,gotoPage,previousPage,nextPage">
            <table @class(['orders-modern-table', 'orders-modern-table--overview' => $sequence === 0])>
                <thead><tr>@foreach($headers as $header)<th @class(['client-product-column' => $sequence === 0 && $loop->index === 1])>{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($jobs as $job)
                        @php
                            $row = $rows[(int) $job->id] ?? [];
                            $detailUrl = route('jobs.index', ['open' => $job->id]);
                            $financeUrl = route('jobs.index', ['open' => $job->id, 'tab' => 'finance']);
                            $activeTaskColor = data_get($row, 'active_task_color');
                            $phaseSequence = (int) data_get($row, 'phase_sequence', 0);
                            $hasCompletedTask = (bool) data_get($row, 'has_completed_task', false);
                            $clientLabel = trim((string) data_get($row, 'client'));
                            $productSummary = collect([
                                data_get($row, 'product'),
                                data_get($row, 'product_detail'),
                            ])->filter(fn ($value) => filled($value))->implode(' · ');
                            $clientCode = strtoupper(trim((string) data_get($row, 'client_code', '')));
                            $clientName = strtoupper(trim((string) data_get($row, 'client', '')));
                            $clientRowTone = ($clientCode === 'IID' || preg_match('/\bIID\b/i', $clientName))
                                ? 'iid'
                                : (($clientCode === 'NEP' || preg_match('/\bNEP\b/i', $clientName)) ? 'nep' : '');
                            $isOnHold = (bool) data_get($row, 'is_on_hold', false);
                            $useClientBaseTone = ! $isOnHold && $phaseSequence === 1 && ! $hasCompletedTask && $clientRowTone !== '';

                            // Hold is an Order-wide operational state, so its orange
                            // tone must take precedence over client/task/stage colors.
                            $rowColor = $isOnHold
                                ? '#FF6A00'
                                : ($useClientBaseTone
                                    ? null
                                    : ($sequence > 0 ? data_get($row, 'stage_filter_color') : $activeTaskColor));
                            $rowStyle = \App\Support\MasterColor::taskRowStyle($rowColor);
                            $rowClass = 'order-row';
                            if ($isOnHold) {
                                $rowClass .= ' has-task-color is-on-hold';
                            } elseif ($useClientBaseTone) {
                                $rowClass .= ' ft-client-row-'.$clientRowTone;
                            } elseif (filled($rowColor)) {
                                $rowClass .= ' has-task-color';
                            }
                        @endphp
                        @if($sequence === 0)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td class="client-product-column" data-label="Client & Product"><div class="client-product-cell"><x-ui.client-logo :client="$job->client" :name="$clientLabel ?: 'Client'" :size="34" /><div class="client-product-copy"><b title="{{ $clientLabel }}">{{ $clientLabel }}</b><small title="{{ $productSummary }}">{{ $productSummary }}</small></div></div></td>
                                <td data-label="Current stage"><span class="stage-chip" style="--stage:{{ data_get($row,'phase_color') }}">{{ data_get($row,'phase_name') }}</span></td>
                                <td data-label="Current status"><span class="row-status {{ $isOnHold ? 'hold' : (filled(data_get($row,'flag')) ? 'attn' : 'good') }}">{{ data_get($row,'status') }}</span>@if(filled(data_get($row,'flag')))<span class="order-cell-ref">⚑ {{ data_get($row,'flag') }}</span>@endif</td>
                                <td data-label="Owner & delivery"><div class="owner-delivery"><x-ui.avatar :name="data_get($row,'owner','Unassigned')" :src="data_get($row,'owner_avatar')" :size="28" /><div><b>{{ data_get($row,'owner') }}</b><small>{{ data_get($row,'delivery') ? 'CRDD '.$formatDate(data_get($row,'delivery')) : 'No delivery date' }}</small></div></div></td>
                                <td data-label="Progress"><div class="row-progress"><span class="row-progress-track"><i style="width:{{ data_get($row,'progress',0) }}%"></i></span><b>{{ data_get($row,'progress',0) }}%</b></div></td>
                            </tr>
                        @elseif($sequence === 1)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Client"><b>{{ data_get($row,'client') }}</b><span class="stage-table-note">{{ data_get($row,'title') }}</span></td>
                                <td data-label="Product & supplier"><b>{{ data_get($row,'product') }}</b><span class="stage-table-note">{{ data_get($row,'product_detail') }}</span><span class="stage-table-note">Supplier: {{ data_get($row,'supplier') }}</span></td>
                                <td data-label="Purchase order"><div class="stage-doc"><span class="stage-doc-icon">PO</span><div><b>{{ data_get($row,'po_status') }}</b><span class="stage-table-note">{{ data_get($row,'po_document')?->name ?: 'Not uploaded' }}</span>@if(data_get($row,'po_document'))<div class="cell-controls"><a class="cell-link" href="{{ route('documents.open', data_get($row,'po_document')) }}" target="_blank" rel="noopener" x-on:click.stop>View PO</a>@if(auth()->user()->canModule('documents','export'))<a class="cell-link download" href="{{ route('documents.download', data_get($row,'po_document')) }}" x-on:click.stop>Download</a>@endif</div>@endif</div></div></td>
                                <td data-label="Owner & CRDD">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'CRDD', 'formatDate' => $formatDate])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @elseif($sequence === 2)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Product & supplier"><b>{{ data_get($row,'product') }}</b><span class="stage-table-note">{{ data_get($row,'product_detail') }}</span><span class="stage-table-note">Supplier: {{ data_get($row,'supplier') }}</span></td>
                                <td data-label="Artwork"><b>{{ data_get($row,'art_version',0) ? 'V'.data_get($row,'art_version').' · ' : '' }}{{ data_get($row,'art_status') }}</b><span class="stage-table-note">{{ data_get($row,'art_document') ? 'Latest artwork' : 'No artwork uploaded' }}</span>@if(data_get($row,'art_document'))<div class="cell-controls"><a class="cell-link" href="{{ route('documents.open', data_get($row,'art_document')) }}" target="_blank" rel="noopener" x-on:click.stop>View latest</a>@if(auth()->user()->canModule('documents','export'))<a class="cell-link download" href="{{ route('documents.download', data_get($row,'art_document')) }}" x-on:click.stop>Download</a>@endif</div>@endif</td>
                                <td data-label="Approval"><b>{{ data_get($row,'client_approval') }}</b><span class="stage-table-note">Sample: {{ data_get($row,'sample_status') }}</span></td>
                                <td data-label="Owner & due date">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'Due', 'formatDate' => $formatDate])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @elseif($sequence === 3)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Product & supplier"><b>{{ data_get($row,'product') }}</b><span class="stage-table-note">Supplier: {{ data_get($row,'supplier') }}</span></td>
                                <td data-label="Quantity"><b>{{ number_format((int) data_get($row,'quantity',0)) }} pcs</b></td>
                                <td data-label="Production"><span class="row-status {{ str_contains(strtolower((string)data_get($row,'production_status')), 'issue') ? 'attn' : 'good' }}">{{ data_get($row,'production_status') }}</span><div class="cell-controls"><a class="cell-link" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>Update production</a></div></td>
                                <td data-label="Open issue"><span class="{{ data_get($row,'production_issue') === 'No open issue' ? 'stage-ok' : 'stage-alert' }}">{{ data_get($row,'production_issue') }}</span>@if(data_get($row,'production_issue') !== 'No open issue')<div class="cell-controls"><a class="cell-link" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>View issue</a><a class="cell-action danger" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>Resolve</a></div>@endif</td>
                                <td data-label="Owner & CRDD">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'CRDD', 'formatDate' => $formatDate])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @elseif($sequence === 4)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Product & supplier"><b>{{ data_get($row,'product') }}</b><span class="stage-table-note">Supplier: {{ data_get($row,'supplier') }}</span></td>
                                <td data-label="QC inspection"><b>{{ data_get($row,'qc_inspection') }}</b><span class="stage-table-note">Checked / total</span></td>
                                <td data-label="QC status"><span class="row-status {{ str_contains(strtolower((string)data_get($row,'qc_status')), 'issue') ? 'attn' : 'good' }}">{{ data_get($row,'qc_status') }}</span><div class="cell-controls"><a class="cell-link" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'qc_issue') === 'None' ? 'Open QC check' : 'Review QC' }}</a></div></td>
                                <td data-label="QC issue"><span class="{{ data_get($row,'qc_issue') === 'None' ? 'stage-ok' : 'stage-alert' }}">{{ data_get($row,'qc_issue') }}</span>@if(data_get($row,'qc_issue') !== 'None')<div class="cell-controls"><a class="cell-link" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>View issue</a><a class="cell-action danger" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>Resolve</a></div>@endif</td>
                                <td data-label="Owner & due date">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'Due', 'formatDate' => $formatDate])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @elseif($sequence === 5)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }} · {{ data_get($row,'created') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Client & product"><b>{{ data_get($row,'client') }}</b><span class="stage-table-note">{{ data_get($row,'product') }}</span></td>
                                <td data-label="Shipping urgency"><span class="urgency-badge {{ data_get($row,'urgency_tone') }}">{{ data_get($row,'urgency') }}</span></td>
                                <td data-label="Shipping label"><b>{{ data_get($row,'label_status') }}</b><span class="stage-table-note">Carrier: {{ data_get($row,'carrier') }}</span>@if(data_get($row,'label_document'))<div class="cell-controls"><a class="cell-link" href="{{ route('documents.open', data_get($row,'label_document')) }}" target="_blank" rel="noopener" x-on:click.stop>View label</a>@if(auth()->user()->canModule('documents','export'))<a class="cell-link download" href="{{ route('documents.download', data_get($row,'label_document')) }}" x-on:click.stop>Download</a>@endif</div>@endif</td>
                                <td data-label="Tracking"><b>{{ data_get($row,'tracking') }}</b>@if(data_get($row,'tracking') === 'Pending')<div class="cell-controls"><a class="cell-action" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>Add tracking number</a></div>@endif</td>
                                <td data-label="Owner & delivery">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'Delivery', 'formatDate' => $formatDate])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @elseif($sequence === 6)
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Client"><b>{{ data_get($row,'client') }}</b><span class="stage-table-note">{{ data_get($row,'title') }}</span></td>
                                <td data-label="Invoice"><b>{{ data_get($row,'invoice_number') }}</b>@if(data_get($row,'invoice'))<div class="cell-controls"><a class="cell-link" href="{{ route('invoices.pdf.open', data_get($row,'invoice')) }}" target="_blank" rel="noopener" x-on:click.stop>View invoice</a><a class="cell-link download" href="{{ route('invoices.pdf.download', data_get($row,'invoice')) }}" x-on:click.stop>Download</a></div>@endif</td>
                                <td class="money-cell" data-label="Amount"><b>{{ $money(data_get($row,'invoice_amount',0)) }}</b></td>
                                <td data-label="Invoice status"><span class="row-status {{ strtolower((string)data_get($row,'invoice_status')) === 'pending' ? 'attn' : 'good' }}">{{ data_get($row,'invoice_status') }}</span>@if(str_contains(strtolower((string)data_get($row,'invoice_status')), 'prepared'))<div class="cell-controls"><a class="cell-action" href="{{ $financeUrl }}" wire:navigate x-on:click.stop>Send invoice</a></div>@endif</td>
                                <td data-label="Owner & due date">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'Due', 'formatDate' => $formatDate, 'overrideDate' => data_get($row,'invoice_due')])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @else
                            <tr class="{{ $rowClass }}" style="{{ $rowStyle }}" x-data x-on:click="window.location.href='{{ $detailUrl }}'">
                                <td data-label="Order"><a class="order-cell-id" href="{{ $detailUrl }}" wire:navigate x-on:click.stop>{{ data_get($row,'order') }}</a><span class="order-cell-ref">{{ data_get($row,'reference') }}</span>@if($isOnHold)<span class="order-hold-list-badge"><span aria-hidden="true">Ⅱ</span> On Hold</span>@endif</td>
                                <td data-label="Client"><b>{{ data_get($row,'client') }}</b><span class="stage-table-note">{{ data_get($row,'title') }}</span></td>
                                <td data-label="Invoice"><b>{{ data_get($row,'invoice_number') }}</b><span class="stage-table-note">{{ $money(data_get($row,'invoice_amount',0)) }}</span>@if(data_get($row,'invoice'))<div class="cell-controls"><a class="cell-link" href="{{ route('invoices.pdf.open', data_get($row,'invoice')) }}" target="_blank" rel="noopener" x-on:click.stop>View invoice</a><a class="cell-link download" href="{{ route('invoices.pdf.download', data_get($row,'invoice')) }}" x-on:click.stop>Download</a></div>@endif</td>
                                <td class="money-cell" data-label="Payment"><b>{{ $money(data_get($row,'paid_amount',0)) }}</b><span class="stage-table-note">Balance {{ $money(data_get($row,'balance',0)) }}</span>@if((float)data_get($row,'balance',0) > 0)<div class="cell-controls"><a class="cell-action" href="{{ $financeUrl }}" wire:navigate x-on:click.stop>{{ str_contains(strtolower((string)data_get($row,'payment_status')), 'partial') ? 'Record balance' : 'Record payment' }}</a></div>@endif</td>
                                <td data-label="Payment status"><span class="row-status {{ str_contains(strtolower((string)data_get($row,'payment_status')), 'partial') ? 'attn' : 'good' }}">{{ data_get($row,'payment_status') }}</span></td>
                                <td data-label="Owner & due date">@include('components.orders.prototype-owner-cell', ['row' => $row, 'dateLabel' => 'Due', 'formatDate' => $formatDate, 'overrideDate' => data_get($row,'invoice_due')])</td>
                                <td data-label="Next action">@include('components.orders.prototype-next-action', ['row' => $row, 'detailUrl' => $detailUrl])</td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ count($headers) }}" class="empty-list"><b>No matching {{ $selectedStage ? $stageName.' ' : '' }}orders</b><br>Change the search or filters to see more orders.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
