<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowMetric from '@/components/flowtrack/FlowMetric.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowProgress from '@/components/flowtrack/FlowProgress.vue';
import TaskDrawer from '@/components/flowtrack/TaskDrawer.vue';
import { dueClass, formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{ tasks: any[]; filters: any; options: any }>();
const selectedTask = ref<number | null>(null);
const filter = reactive({ search: props.filters.search || '', status: props.filters.status || '', assignee: props.filters.assignee || '' });
const today = new Date().toISOString().slice(0, 10);

const metrics = computed(() => ({
    today: props.tasks.filter((task) => task.due_date?.slice(0, 10) === today).length,
    overdue: props.tasks.filter((task) => task.due_date?.slice(0, 10) < today).length,
    attention: props.tasks.filter((task) => task.attention_required).length,
    waiting: props.tasks.filter((task) => /Waiting/.test(task.status?.name || '')).length,
    completed: props.tasks.filter((task) => task.completed_at).length,
    upcoming: props.tasks.filter((task) => task.due_date?.slice(0, 10) > today).length,
}));

function apply() {
    router.get('/flowtrack/my-work', filter, { preserveState: true, preserveScroll: true, replace: true });
}
function printPage() { window.print(); }
</script>

<template>
    <Head title="My Work" />
    <FlowPageHeader title="My Work" subtitle="What needs your action now">
        <button class="ft-ghost" @click="printPage">Calendar / Print</button>
        <a class="ft-primary" href="/flowtrack/board">Open Board</a>
    </FlowPageHeader>

    <div class="ft-metrics">
        <FlowMetric label="Due Today" :value="metrics.today" foot="Tasks requiring action" />
        <FlowMetric label="Overdue" :value="metrics.overdue" foot="Past their due date" />
        <FlowMetric label="Blocked / Attention" :value="metrics.attention" foot="Need support" />
        <FlowMetric label="Waiting Review" :value="metrics.waiting" foot="Client or supplier" />
        <FlowMetric label="Completed" :value="metrics.completed" foot="Current result set" />
        <FlowMetric label="Upcoming" :value="metrics.upcoming" foot="Scheduled next" />
    </div>

    <form class="ft-card ft-toolbar" @submit.prevent="apply">
        <input v-model="filter.search" class="grow" placeholder="Search assigned tasks…" />
        <select v-model="filter.status"><option value="">All statuses</option><option v-for="status in records(options, 'task_status')" :key="status.id" :value="status.id">{{ status.name }}</option></select>
        <select v-model="filter.assignee"><option value="">My assignments</option><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select>
        <button class="ft-secondary">Apply</button>
    </form>

    <section class="ft-task-grid">
        <article v-for="task in tasks" :key="task.id" class="ft-card ft-task-card" @click="selectedTask = task.id">
            <div class="ft-task-top"><span class="ft-badge gray">{{ task.code }}</span><FlowBadge :value="task.status?.name" /></div>
            <h4 style="margin: 9px 0 6px; font-size: 14px">{{ task.title }}</h4>
            <p class="ft-muted ft-small">{{ task.job?.code }} · {{ task.job?.client?.name }} · {{ task.phase?.short_name }}</p>
            <div style="margin-top: 12px"><FlowProgress :value="task.progress" /></div>
            <div class="ft-task-footer" style="margin-top: 14px"><div class="ft-person"><span class="ft-avatar">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span>{{ task.assignee?.name || 'Unassigned' }}</div><span class="ft-due ft-small" :class="dueClass(task.due_date)">{{ formatDate(task.due_date) }}</span></div>
        </article>
        <div v-if="!tasks.length" class="ft-card ft-empty">No tasks match the selected filters.</div>
    </section>

    <TaskDrawer :open="Boolean(selectedTask)" :task-id="selectedTask" :options="options" @close="selectedTask = null" />
</template>
