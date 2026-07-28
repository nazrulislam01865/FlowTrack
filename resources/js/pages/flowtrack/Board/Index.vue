<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CreateJobModal from '@/components/flowtrack/CreateJobModal.vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import TaskDrawer from '@/components/flowtrack/TaskDrawer.vue';
import { dueClass, formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{ jobs: any[]; tasks: any[]; options: any }>();
const mode = ref<'tasks' | 'jobs'>('tasks');
const workflowId = ref(props.options.workflows?.[0]?.id || null);
const createOpen = ref(false);
const taskId = ref<number | null>(null);
const dragging = ref<{ type: 'task' | 'job'; id: number } | null>(null);
const pendingJobMove = ref<any>(null);
const reason = ref('');

const taskStatuses = computed(() => records(props.options, 'task_status'));
const workflow = computed(() => props.options.workflows.find((item: any) => item.id === workflowId.value));
const phases = computed(() => workflow.value?.phases || []);
const workflowJobs = computed(() => props.jobs.filter((job) => job.workflow_template_id === workflowId.value));

function dragStart(type: 'task' | 'job', id: number) { dragging.value = { type, id }; }
function dropTask(statusId: number) {
    if (dragging.value?.type !== 'task') return;
    router.patch(`/flowtrack/board/tasks/${dragging.value.id}/status`, { task_status_id: statusId }, { preserveScroll: true });
    dragging.value = null;
}
function dropJob(phase: any) {
    if (dragging.value?.type !== 'job') return;
    const job = props.jobs.find((item) => item.id === dragging.value?.id);
    if (!job) return;
    if (phase.sequence > (job.current_phase?.sequence || 0) + 1) {
        pendingJobMove.value = { job, phase };
        reason.value = '';
    } else {
        router.patch(`/flowtrack/board/jobs/${job.id}/phase`, { phase_id: phase.id, reason: null }, { preserveScroll: true });
    }
    dragging.value = null;
}
function confirmMove() {
    if (!pendingJobMove.value) return;
    router.patch(`/flowtrack/board/jobs/${pendingJobMove.value.job.id}/phase`, { phase_id: pendingJobMove.value.phase.id, reason: reason.value }, { preserveScroll: true, onSuccess: () => (pendingJobMove.value = null) });
}
</script>

<template>
    <Head title="Workflow Board" />
    <FlowPageHeader title="Workflow Board" subtitle="Drag cards to update a task status or move a job through its configured workflow">
        <button class="ft-primary" @click="createOpen = true">＋ New Job</button>
    </FlowPageHeader>

    <div class="ft-board-tabs"><button class="ft-tab-btn" :class="{ active: mode === 'tasks' }" @click="mode = 'tasks'">Task Board</button><button class="ft-tab-btn" :class="{ active: mode === 'jobs' }" @click="mode = 'jobs'">Job Phase Board</button></div>

    <div v-if="mode === 'jobs'" class="ft-card ft-toolbar"><label class="ft-small"><b>Workflow:</b></label><select v-model="workflowId"><option v-for="item in options.workflows" :key="item.id" :value="item.id">{{ item.name }}</option></select><span class="ft-help">Only phases from the selected workflow are shown.</span></div>

    <section v-if="mode === 'tasks'" class="ft-kanban">
        <article v-for="status in taskStatuses" :key="status.id" class="ft-kanban-col">
            <header class="ft-kanban-head"><span>{{ status.name }}</span><span class="ft-count">{{ tasks.filter((task) => task.task_status_id === status.id).length }}</span></header>
            <div class="ft-kanban-list" @dragover.prevent @drop="dropTask(status.id)">
                <div v-for="task in tasks.filter((item) => item.task_status_id === status.id)" :key="task.id" class="ft-kanban-card" draggable="true" @dragstart="dragStart('task', task.id)" @click="taskId = task.id">
                    <FlowBadge :value="task.priority?.name" /><div class="ft-kanban-card-title">{{ task.title }}</div><div class="ft-kanban-job">{{ task.job?.code }} · {{ task.phase?.short_name }}</div><div class="ft-kanban-meta"><span class="ft-avatar" style="width: 25px; height: 25px; font-size: 9px">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span><span class="ft-due ft-small" :class="dueClass(task.due_date)">{{ formatDate(task.due_date) }}</span></div>
                </div>
            </div>
        </article>
    </section>

    <section v-else class="ft-kanban">
        <article v-for="phase in phases" :key="phase.id" class="ft-kanban-col">
            <header class="ft-kanban-head"><span>{{ phase.short_name }}</span><span class="ft-count">{{ workflowJobs.filter((job) => job.current_phase_id === phase.id).length }}</span></header>
            <div class="ft-kanban-list" @dragover.prevent @drop="dropJob(phase)">
                <div v-for="job in workflowJobs.filter((item) => item.current_phase_id === phase.id)" :key="job.id" class="ft-kanban-card" draggable="true" @dragstart="dragStart('job', job.id)" @click="router.get(`/flowtrack/jobs/${job.id}`)">
                    <FlowBadge :value="job.health" health /><div class="ft-kanban-card-title">{{ job.title }}</div><div class="ft-kanban-job">{{ job.code }} · {{ job.client?.name }}</div><div class="ft-kanban-meta"><span class="ft-avatar" style="width: 25px; height: 25px; font-size: 9px">{{ job.coordinator?.initials || initials(job.coordinator?.name) }}</span><span class="ft-small">{{ job.progress }}%</span></div>
                </div>
            </div>
        </article>
    </section>

    <CreateJobModal :open="createOpen" :options="options" @close="createOpen = false" />
    <TaskDrawer :open="Boolean(taskId)" :task-id="taskId" :options="options" @close="taskId = null" />
    <FlowModal :open="Boolean(pendingJobMove)" title="Confirm phase skip" :subtitle="`Move ${pendingJobMove?.job?.code || ''} to ${pendingJobMove?.phase?.name || ''}`" @close="pendingJobMove = null">
        <div class="ft-source-note">The workflow engine will reject the move if any skipped phase is marked as required.</div>
        <div class="ft-field" style="margin-top: 16px"><label>Reason for skipping phases</label><textarea v-model="reason" rows="4" required placeholder="Provide the approved business reason and supporting reference."></textarea></div>
        <template #footer><button class="ft-ghost" @click="pendingJobMove = null">Cancel</button><button class="ft-primary" :disabled="!reason.trim()" @click="confirmMove">Move Job</button></template>
    </FlowModal>
</template>
