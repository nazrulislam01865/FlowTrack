<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CreateJobModal from '@/components/flowtrack/CreateJobModal.vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowMetric from '@/components/flowtrack/FlowMetric.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowProgress from '@/components/flowtrack/FlowProgress.vue';
import { formatDate, initials, money } from '@/lib/flowtrack';

const props = defineProps<{ metrics: any; attentionJobs: any[]; phaseCounts: Record<string, number>; upcomingDeliveries: any[]; workload: any[]; activities: any[]; options: any }>();
const createOpen = ref(false);

const maxPhase = computed(() => Math.max(1, ...Object.values(props.phaseCounts || {}).map(Number)));
const maxWorkload = computed(() => Math.max(1, ...props.workload.map((item) => Number(item.total))));

function openJob(id: number) {
    router.get(`/flowtrack/jobs/${id}`);
}
function printPage() { window.print(); }
</script>

<template>
    <Head title="Management Dashboard" />
    <FlowPageHeader title="Management Dashboard" :subtitle="`${new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })} · Exceptions, workload and delivery health`">
        <button class="ft-ghost" @click="printPage">Export Summary</button>
        <button class="ft-primary" @click="createOpen = true">＋ New Job</button>
    </FlowPageHeader>

    <section class="ft-metrics">
        <Link href="/flowtrack/jobs" style="text-decoration: none"><FlowMetric label="Active Jobs" :value="metrics.activeJobs" foot="Across all phases" clickable /></Link>
        <Link href="/flowtrack/jobs?health=at_risk" style="text-decoration: none"><FlowMetric label="Needs Attention" :value="metrics.needsAttention" foot="Risk, delay or blocker" clickable /></Link>
        <Link href="/flowtrack/my-work" style="text-decoration: none"><FlowMetric label="Overdue Tasks" :value="metrics.overdueTasks" foot="Require immediate update" clickable /></Link>
        <Link href="/flowtrack/my-work" style="text-decoration: none"><FlowMetric label="Blocked Tasks" :value="metrics.blockedTasks" foot="Require resolution" clickable /></Link>
        <Link href="/flowtrack/board" style="text-decoration: none"><FlowMetric label="Shipping Now" :value="metrics.shippingNow" foot="Ready or in transit" clickable /></Link>
        <Link href="/flowtrack/clients" style="text-decoration: none"><FlowMetric label="Outstanding" :value="metrics.canViewFinancials ? money(metrics.outstanding) : 'Restricted'" foot="Open client balance" clickable /></Link>
    </section>

    <section class="ft-grid-2">
        <article class="ft-card ft-section-card">
            <div class="ft-section-head"><h3>Needs Attention</h3><Link class="ft-link" href="/flowtrack/jobs">View all Jobs</Link></div>
            <div class="ft-attention-list">
                <div v-for="job in attentionJobs" :key="job.id" class="ft-attention-item" @click="openJob(job.id)">
                    <span class="ft-signal" :class="job.health === 'blocked' ? 'purple' : job.health === 'needs_attention' ? '' : 'red'"></span>
                    <div><div class="ft-item-title">{{ job.next_action || job.title }}</div><div class="ft-item-meta">{{ job.code }} · {{ job.client?.name }} · {{ job.current_phase?.short_name }}</div></div>
                    <FlowBadge :value="job.health" health />
                </div>
                <div v-if="!attentionJobs.length" class="ft-empty">No jobs currently require attention.</div>
            </div>
        </article>

        <article class="ft-card ft-section-card">
            <div class="ft-section-head"><h3>Jobs by Phase</h3><Link class="ft-link" href="/flowtrack/board">Open Board</Link></div>
            <div class="ft-phase-list">
                <div v-for="(total, phase) in phaseCounts" :key="phase" class="ft-phase-row">
                    <span>{{ phase }}</span><FlowProgress :value="Math.max(8, Number(total) / maxPhase * 100)" /><b>{{ total }}</b>
                </div>
            </div>
        </article>
    </section>

    <section class="ft-grid-3">
        <article class="ft-card ft-section-card">
            <div class="ft-section-head"><h3>Team Workload</h3><Link class="ft-link" href="/flowtrack/reports">Details</Link></div>
            <div v-for="person in workload" :key="person.id" class="ft-workload-row">
                <div class="ft-person"><span class="ft-avatar">{{ person.initials || initials(person.name) }}</span><span class="ft-truncate">{{ person.name }}</span></div>
                <FlowProgress :value="Number(person.total) / maxWorkload * 100" /><b>{{ person.total }}</b>
            </div>
        </article>

        <article class="ft-card ft-section-card">
            <div class="ft-section-head"><h3>Upcoming Deliveries</h3><Link class="ft-link" href="/flowtrack/jobs">View Jobs</Link></div>
            <table class="ft-mini-table"><thead><tr><th>Job</th><th>Client</th><th>Delivery</th></tr></thead><tbody>
                <tr v-for="job in upcomingDeliveries" :key="job.id" style="cursor: pointer" @click="openJob(job.id)"><td><b>{{ job.code.slice(-5) }}</b><div class="ft-muted ft-small ft-truncate" style="max-width: 125px">{{ job.title }}</div></td><td>{{ job.client?.name?.split(' ')[0] }}</td><td>{{ formatDate(job.delivery_date) }}</td></tr>
            </tbody></table>
        </article>

        <article class="ft-card ft-section-card">
            <div class="ft-section-head"><h3>Recent Activity</h3><Link class="ft-link" href="/flowtrack/notifications">All Activity</Link></div>
            <div v-for="activity in activities" :key="activity.id" class="ft-activity"><span class="ft-avatar">{{ activity.user?.initials || initials(activity.user?.name) }}</span><div><div class="ft-small"><b>{{ activity.description }}</b></div><div class="ft-muted ft-small" style="margin-top: 4px">{{ new Date(activity.created_at).toLocaleString() }}</div></div></div>
            <div v-if="!activities.length" class="ft-empty">No recent activity yet.</div>
        </article>
    </section>

    <CreateJobModal :open="createOpen" :options="options" @close="createOpen = false" />
</template>
