<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FlowMetric from '@/components/flowtrack/FlowMetric.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import { money } from '@/lib/flowtrack';

const props = defineProps<{ metrics: any; phaseData: any[]; workload: any[]; delivery: any; ageing: any[] }>();
const maxPhase = Math.max(1, ...props.phaseData.map((item) => Number(item.value)));
const maxWorkload = Math.max(1, ...props.workload.map((item) => Number(item.value)));
const maxAgeing = Math.max(1, ...props.ageing.map((item) => Number(item.value)));

function printReport() {
    window.print();
}

function exportCsv() {
    const rows = [
        ['Metric', 'Value'],
        ['Quotation Conversion', `${props.metrics.quotationConversion}%`],
        ['On-time Jobs', `${props.metrics.onTimeJobs}%`],
        ['Task Completion', `${props.metrics.taskCompletion}%`],
        ['Average Artwork Cycle', `${props.metrics.artworkCycle} days`],
        ['Shipment On Time', `${props.metrics.shipmentOnTime}%`],
        ['Open Receivables', String(props.metrics.openReceivables)],
        [],
        ['Active Jobs by Phase', 'Count'],
        ...props.phaseData.map((item) => [item.label, item.value]),
        [],
        ['Team Workload', 'Open Tasks'],
        ...props.workload.map((item) => [item.label, item.value]),
        [],
        ['Receivable Ageing', 'Amount'],
        ...props.ageing.map((item) => [item.label, item.value]),
    ];
    const csv = rows.map((row) => row.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(',')).join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `flowtrack-report-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Head title="Reports" />
    <FlowPageHeader title="Reports" subtitle="Operational performance, conversion, workload and cash collection">
        <button class="ft-ghost" @click="printReport">Print / PDF</button><button class="ft-primary" @click="exportCsv">Export CSV</button>
    </FlowPageHeader>
    <div class="ft-metrics">
        <FlowMetric label="Quotation Conversion" :value="`${metrics.quotationConversion}%`" foot="Last 90 days" />
        <FlowMetric label="On-time Jobs" :value="`${metrics.onTimeJobs}%`" foot="Completed deliveries" />
        <FlowMetric label="Task Completion" :value="`${metrics.taskCompletion}%`" foot="Within target date" />
        <FlowMetric label="Avg. Artwork Cycle" :value="`${metrics.artworkCycle}d`" foot="Request to approval" />
        <FlowMetric label="Shipment On Time" :value="`${metrics.shipmentOnTime}%`" foot="Recent shipments" />
        <FlowMetric label="Open Receivables" :value="money(metrics.openReceivables)" foot="Across active clients" />
    </div>
    <section class="ft-report-grid">
        <article class="ft-card ft-section-card"><div class="ft-section-head"><h3>Active Jobs by Phase</h3><span class="ft-muted ft-small">Current portfolio</span></div><div class="ft-bars"><div v-for="item in phaseData" :key="item.label" class="ft-bar-row"><span>{{ item.label }}</span><div class="ft-bar"><span :style="{ width: `${Number(item.value) / maxPhase * 100}%` }"></span></div><b>{{ item.value }}</b></div></div></article>
        <article class="ft-card ft-section-card"><div class="ft-section-head"><h3>Team Workload</h3><span class="ft-muted ft-small">Open tasks</span></div><div class="ft-bars"><div v-for="item in workload" :key="item.label" class="ft-bar-row"><span>{{ item.label }}</span><div class="ft-bar"><span :style="{ width: `${Number(item.value) / maxWorkload * 100}%` }"></span></div><b>{{ item.value }}</b></div></div></article>
        <article class="ft-card ft-section-card"><div class="ft-section-head"><h3>Delivery Performance</h3></div><div class="ft-kpi-list"><div class="ft-kpi"><b>{{ delivery.delivered }}</b><span>Delivered this month</span></div><div class="ft-kpi"><b>{{ delivery.onTime }}</b><span>Delivered on time</span></div><div class="ft-kpi"><b>{{ delivery.supplierDelay }}</b><span>Supplier delays</span></div><div class="ft-kpi"><b>{{ delivery.clientDelay }}</b><span>Client dependencies</span></div></div></article>
        <article class="ft-card ft-section-card"><div class="ft-section-head"><h3>Receivable Ageing</h3></div><div class="ft-bars"><div v-for="item in ageing" :key="item.label" class="ft-bar-row"><span>{{ item.label }}</span><div class="ft-bar"><span :style="{ width: `${Number(item.value) / maxAgeing * 100}%` }"></span></div><b>{{ money(item.value) }}</b></div></div></article>
    </section>
</template>
