<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import CreateJobModal from '@/components/flowtrack/CreateJobModal.vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowPagination from '@/components/flowtrack/FlowPagination.vue';
import FlowProgress from '@/components/flowtrack/FlowProgress.vue';
import JobDrawer from '@/components/flowtrack/JobDrawer.vue';
import TaskDrawer from '@/components/flowtrack/TaskDrawer.vue';
import { formatDate, initials } from '@/lib/flowtrack';

const props = defineProps<{ jobs: any; filters: any; options: any; selectedJob: any | null }>();
const createOpen = ref(false);
const taskOpen = ref(false);
const taskId = ref<number | null>(null);
const filter = reactive({
    search: props.filters.search || '',
    phase: props.filters.phase || '',
    health: props.filters.health || '',
    client: props.filters.client || '',
});

const openCreate = () => (createOpen.value = true);

onMounted(() => {
    window.addEventListener('flowtrack:create-job', openCreate);
    if (new URLSearchParams(window.location.search).get('create') === '1') createOpen.value = true;
});
onBeforeUnmount(() => window.removeEventListener('flowtrack:create-job', openCreate));

function applyFilters() {
    router.get('/flowtrack/jobs', filter, { preserveState: true, preserveScroll: true, replace: true });
}
function clearFilters() {
    Object.assign(filter, { search: '', phase: '', health: '', client: '' });
    router.get('/flowtrack/jobs');
}
function openTask(id: number) {
    taskId.value = id;
    taskOpen.value = true;
}
function productSummary(job: any): string {
    const items = job.items || [];
    if (!items.length) return job.product?.name || '—';
    if (items.length === 1) return items[0].product?.name || 'Deleted product';
    return `${items[0].product?.name || 'Deleted product'} + ${items.length - 1} more`;
}
function printPage() { window.print(); }
</script>

<template>
    <Head title="Jobs" />
    <FlowPageHeader title="Jobs" subtitle="One continuous record from request to collection">
        <button class="ft-ghost" @click="printPage">Export</button>
        <button class="ft-primary" @click="createOpen = true">＋ New Job</button>
    </FlowPageHeader>

    <div class="ft-saved-views">
        <Link href="/flowtrack/jobs" class="ft-view-chip" :class="{ active: !filters.health && !filters.phase }">All Active</Link>
        <Link href="/flowtrack/jobs?health=at_risk" class="ft-view-chip" :class="{ active: filters.health === 'at_risk' }">At Risk</Link>
        <Link href="/flowtrack/jobs?health=blocked" class="ft-view-chip" :class="{ active: filters.health === 'blocked' }">Blocked</Link>
        <Link href="/flowtrack/jobs?health=needs_attention" class="ft-view-chip" :class="{ active: filters.health === 'needs_attention' }">Needs Attention</Link>
    </div>

    <form class="ft-card ft-toolbar" @submit.prevent="applyFilters">
        <input v-model="filter.search" class="grow" placeholder="Search Job ID, client, product…" />
        <select v-model="filter.phase"><option value="">All phases</option><template v-for="workflow in options.workflows" :key="workflow.id"><option v-for="phase in workflow.phases" :key="phase.id" :value="phase.id">{{ phase.name }}</option></template></select>
        <select v-model="filter.health"><option value="">All health</option><option value="on_track">On Track</option><option value="needs_attention">Needs Attention</option><option value="at_risk">At Risk</option><option value="delayed">Delayed</option><option value="blocked">Blocked</option><option value="completed">Completed</option></select>
        <select v-model="filter.client"><option value="">All clients</option><option v-for="client in options.clients" :key="client.id" :value="client.id">{{ client.name }}</option></select>
        <button class="ft-secondary" type="submit">Apply</button><button class="ft-ghost" type="button" @click="clearFilters">Clear</button>
    </form>

    <div class="ft-card ft-table-wrap">
        <table class="ft-table">
            <thead><tr><th>Job / Order</th><th>Client</th><th>Product</th><th>Phase</th><th>Coordinator</th><th>Delivery</th><th>Progress</th><th>Health</th><th></th></tr></thead>
            <tbody>
                <tr v-for="job in jobs.data" :key="job.id">
                    <td><Link class="ft-job-link" :href="`/flowtrack/jobs/${job.id}`" preserve-scroll>{{ job.code }}</Link><div class="ft-muted ft-small">{{ job.order_code || 'Quotation request' }}</div></td>
                    <td><b>{{ job.client?.name }}</b><div class="ft-muted ft-small ft-truncate" style="max-width: 230px">{{ job.title }}</div></td>
                    <td>{{ productSummary(job) }}<div class="ft-muted ft-small">{{ job.items?.length || (job.product ? 1 : 0) }} product line{{ (job.items?.length || (job.product ? 1 : 0)) === 1 ? '' : 's' }} · {{ Number(job.quantity).toLocaleString() }} units</div></td>
                    <td><FlowBadge :value="job.current_phase?.short_name" /><div class="ft-muted ft-small" style="margin-top: 4px">{{ job.status.replaceAll('_', ' ') }}</div></td>
                    <td><div class="ft-person"><span class="ft-avatar">{{ job.coordinator?.initials || initials(job.coordinator?.name) }}</span><span>{{ job.coordinator?.name || 'Unassigned' }}</span></div></td>
                    <td>{{ formatDate(job.delivery_date) }}</td>
                    <td style="min-width: 120px"><FlowProgress :value="job.progress" /><div class="ft-muted ft-small" style="margin-top: 4px">{{ job.progress }}%</div></td>
                    <td><FlowBadge :value="job.health" health :title="job.health_reason || 'Calculated from current-phase Tasks and delivery dates.'" /></td>
                    <td><Link class="ft-mini-btn" :href="`/flowtrack/jobs/${job.id}`" preserve-scroll>Open</Link></td>
                </tr>
                <tr v-if="!jobs.data.length"><td colspan="9"><div class="ft-empty">No jobs match the selected filters.</div></td></tr>
            </tbody>
        </table>
        <FlowPagination :meta="jobs" />
    </div>

    <CreateJobModal :open="createOpen" :options="options" @close="createOpen = false" />
    <JobDrawer :open="Boolean(selectedJob)" :job="selectedJob" :options="options" @close="router.get('/flowtrack/jobs', filters, { preserveScroll: true })" @task="openTask" />
    <TaskDrawer :open="taskOpen" :task-id="taskId" :options="options" @close="taskOpen = false" />
</template>
