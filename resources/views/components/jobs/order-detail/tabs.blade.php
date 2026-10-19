@props(['job', 'detailTab', 'canViewFinance' => false, 'canCreateFinance' => false, 'redoContext' => []])
<nav class="page-tabs ft-order-prototype-tabs" aria-label="Order detail tabs">
    <button type="button" class="page-tab {{ $detailTab === 'overview' ? 'active' : '' }}" wire:click="setDetailTab('overview')">Overview</button>
    @php($linkedInquiryCount = max((int) ($job->linked_inquiries_count ?? 0), $job->source_inquiry_id ? 1 : 0))
    <button type="button" class="page-tab {{ $detailTab === 'inquiry' ? 'active' : '' }}" wire:click="setDetailTab('inquiry')">Inquiry &nbsp;<span class="status-pill">{{ $linkedInquiryCount }}</span></button>
    @if($canViewFinance)
        <button type="button" class="page-tab {{ $detailTab === 'finance' ? 'active' : '' }}" wire:click="setDetailTab('finance')">Invoices &amp; Payments</button>
    @endif
    @if((bool) ($redoContext['hasRedo'] ?? false))
        <button type="button" class="page-tab {{ $detailTab === 'redo' ? 'active' : '' }}" wire:click="setDetailTab('redo')">Redo <span class="ft-redo-tab-count">{{ (int) ($redoContext['redoCount'] ?? 0) }}</span></button>
    @endif
</nav>
@if($detailTab === 'finance' && $canCreateFinance)
    <div class="finance-tab-actions">
        <button type="button" class="btn" wire:click="openRecordPayment">Record Payment</button>
        <button type="button" class="btn primary" wire:click="openCreateInvoice">＋ Create Invoice</button>
    </div>
@endif
