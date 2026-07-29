<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CreateJobModal from '@/components/flowtrack/CreateJobModal.vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import TaskDrawer from '@/components/flowtrack/TaskDrawer.vue';
import { dueClass, formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{ jobs: any[]; tasks: any[]; options: any; boardAccess: { isAdministrator: boolean; taskVisibility: string } }>();
const mode = ref<'tasks' | 'jobs'>('tasks');
const workflowId = ref(props.options.workflows?.[0]?.id || null);
const createOpen = ref(false);
const taskId = ref<number | null>(null);
const dragging = ref<{ type: 'task' | 'job'; id: number } | null>(null);
const pendingJobMove = ref<any>(null);
const reason = ref('');

const taskStatuses = computed(() => records(props.options, 'task_status').filter((status: any) => String(status.status || 'active').toLowerCase() === 'active'));
const workflow = computed(() => props.options.workflows.find((item: any) => Number(item.id) === Number(workflowId.value)));
const phases = computed(() => (workflow.value?.phases || []).filter((phase: any) => phase.is_active !== false).slice().sort((a: any, b: any) => Number(a.sequence) - Number(b.sequence)));
const workflowJobs = computed(() => props.jobs.filter((job) => Number(job.workflow_template_id) === Number(workflowId.value)));

const tasksForStatus = (statusId: number) => props.tasks.filter((task) => Number(task.task_status_id) === Number(statusId));
const jobsForPhase = (phaseId: number) => workflowJobs.value.filter((job) => Number(job.current_phase_id) === Number(phaseId));

function dragStart(type: 'task' | 'job', id: number) {
    if (type === 'task') {
        const task = props.tasks.find((item) => Number(item.id) === Number(id));
        if (!task?.can_update) return;
    }
    dragging.value = { type, id };
}

function statusMatches(status: any, values: string[]): boolean {
    const allowed = values.map((value) => value.trim().toUpperCase());
    return allowed.includes(String(status?.code || '').trim().toUpperCase())
        || allowed.includes(String(status?.name || '').trim().toUpperCase());
}

function dropTask(statusId: number) {
    if (dragging.value?.type !== 'task') return;
    const draggedTask = props.tasks.find((item) => Number(item.id) === Number(dragging.value?.id));
    if (!draggedTask?.can_update) { dragging.value = null; return; }
    const targetStatus = taskStatuses.value.find((status: any) => Number(status.id) === Number(statusId));
    const draggedTaskId = dragging.value.id;
    dragging.value = null;

    if (!targetStatus) return;
    if (statusMatches(targetStatus, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
        taskId.value = draggedTaskId;
        return;
    }

    router.patch(`/flowtrack/board/tasks/${draggedTaskId}/status`, { task_status_id: Number(statusId) }, { preserveScroll: true });
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

    <div v-if="!boardAccess.isAdministrator" class="ft-source-note" style="margin-bottom: 14px"><b>Assignment-based board</b><div class="ft-small ft-muted" style="margin-top: 4px">Task Board shows only Tasks assigned to you. Job Phase Board shows only Jobs connected to your assignments.</div></div>

    <div class="ft-board-tabs"><button class="ft-tab-btn" :class="{ active: mode === 'tasks' }" @click="mode = 'tasks'">Task Board</button><button class="ft-tab-btn" :class="{ active: mode === 'jobs' }" @click="mode = 'jobs'">Job Phase Board</button></div>

    <div v-if="mode === 'jobs'" class="ft-card ft-toolbar"><label class="ft-small"><b>Workflow:</b></label><select v-model.number="workflowId"><option v-for="item in options.workflows" :key="item.id" :value="item.id">{{ item.name }}</option></select><span class="ft-help">Only phases from the selected workflow are shown.</span></div>

    <section v-if="mode === 'tasks'" class="ft-kanban">
        <article v-for="status in taskStatuses" :key="status.id" class="ft-kanban-col">
            <header class="ft-kanban-head"><span>{{ status.name }}</span><span class="ft-count">{{ tasksForStatus(status.id).length }}</span></header>
            <div class="ft-kanban-list" @dragover.prevent @drop="dropTask(status.id)">
                <div v-for="task in tasksForStatus(status.id)" :key="task.id" class="ft-kanban-card" :draggable="Boolean(task.can_update)" :class="{ 'is-readonly': !task.can_update }" @dragstart="dragStart('task', task.id)" @click="taskId = task.id">
                    <FlowBadge :value="task.priority?.name" /><div class="ft-kanban-card-title">{{ task.title }}</div><div class="ft-kanban-job">{{ task.job?.code }} · {{ task.phase?.short_name }}</div><div class="ft-kanban-meta"><span class="ft-avatar" style="width: 25px; height: 25px; font-size: 9px">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span><span class="ft-due ft-small" :class="dueClass(task.due_date)">{{ formatDate(task.due_date) }}</span></div>
                </div>
            </div>
        </article>
    </section>

    <section v-else class="ft-kanban">
        <article v-for="phase in phases" :key="phase.id" class="ft-kanban-col">
            <header class="ft-kanban-head"><span>{{ phase.short_name }}</span><span class="ft-count">{{ jobsForPhase(phase.id).length }}</span></header>
            <div class="ft-kanban-list" @dragover.prevent @drop="dropJob(phase)">
                <div v-for="job in jobsForPhase(phase.id)" :key="job.id" class="ft-kanban-card" draggable="true" @dragstart="dragStart('job', job.id)" @click="router.get(`/flowtrack/jobs/${job.id}`)">
                    <FlowBadge :value="job.health" health /><div class="ft-kanban-card-title">{{ job.title }}</div><div class="ft-kanban-job">{{ job.code }} · {{ job.client?.name }}</div><div class="ft-kanban-meta"><span class="ft-avatar" style="width: 25px; height: 25px; font-size: 9px">{{ job.coordinator?.initials || initials(job.coordinator?.name) }}</span><span class="ft-small">{{ job.progress }}%</span></div>
                </div>
            </div>
        </article>
    </section>

    <CreateJobModal :open="createOpen" :options="options" @close="createOpen = false" />
    <TaskDrawer :open="Boolean(taskId)" :task-id="taskId" :options="options" @close="taskId = null" />
    <FlowModal :open="Boolean(pendingJobMove)" title="Confirm phase skip" :subtitle="`Move ${pendingJobMove?.job?.code || ''} to ${pendingJobMove?.phase?.name || ''}`" @close="pendingJobMove = null">
        <div class="ft-source-note">The workflow engine will reject the move if any skipped phase is marked as required.</div>
        <div class="ft-field" style="margin-top: 16px"><label>Reason for skipping phases</label><textarea v-model="reason" rows="4" required placeholder="Provide the authorised business reason and supporting reference."></textarea></div>
        <template #footer><button class="ft-ghost" @click="pendingJobMove = null">Cancel</button><button class="ft-primary" :disabled="!reason.trim()" @click="confirmMove">Move Job</button></template>
    </FlowModal>
</template>
