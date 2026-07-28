<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FlowBadge from './FlowBadge.vue';
import FlowDrawer from './FlowDrawer.vue';
import FlowProgress from './FlowProgress.vue';
import FlowModal from './FlowModal.vue';
import CreateTaskModal from './CreateTaskModal.vue';
import { formatDate, initials, money } from '@/lib/flowtrack';

const props = defineProps<{ open: boolean; job: any | null; options: any }>();
const emit = defineEmits<{ close: []; task: [id: number] }>();
const page = usePage<any>();
const tab = ref('Overview');
const tabs = ['Overview', 'Workflow', 'Tasks', 'Documents', 'Activity'];
const move = useForm({ phase_id: null as number | null, reason: '' });
const teamOpen = ref(false);
const taskCreateOpen = ref(false);
const teamForm = useForm({ member_ids: [] as number[] });
const approvalForm = useForm({ notes: '' });

watch(() => props.job?.id, () => {
    tab.value = 'Overview';
    move.phase_id = props.job?.current_phase_id || null;
    move.reason = '';
    teamForm.member_ids = (props.job?.members || []).map((member: any) => member.user_id);
    approvalForm.reset();
    approvalForm.clearErrors();
});

const phaseHistory = computed(() => {
    const histories = new Map((props.job?.phase_history || []).map((item: any) => [item.workflow_phase_id, item]));
    return (props.job?.workflow?.phases || []).map((phase: any) => ({ ...phase, history: histories.get(phase.id) }));
});
const selectedPhase = computed(() => props.job?.workflow?.phases?.find((phase: any) => phase.id === move.phase_id));
const canManage = computed(() => {
    const userId = page.props.auth?.user?.id;
    const permissions: string[] = page.props.auth?.permissions || [];
    return permissions.includes('jobs.manage-all')
        || props.job?.owner_id === userId
        || props.job?.coordinator_id === userId
        || (props.job?.members || []).some((member: any) => member.user_id === userId && member.access_level === 'manager');
});
const canManageTasks = computed(() => {
    const userId = page.props.auth?.user?.id;
    const permissions: string[] = page.props.auth?.permissions || [];
    return canManage.value
        || (props.job?.members || []).some((member: any) => member.user_id === userId && member.can_manage_tasks);
});
const needsReason = computed(() => {
    if (!selectedPhase.value || !props.job?.current_phase) return false;
    return selectedPhase.value.sequence > props.job.current_phase.sequence + 1;
});

const currentRequiredTasks = computed(() => (props.job?.tasks || []).filter((task: any) =>
    task.workflow_phase_id === props.job?.current_phase_id && task.pack_item?.is_required,
));
const completedRequiredTasks = computed(() => currentRequiredTasks.value.filter((task: any) => Boolean(task.completed_at)).length);
const requiredTasksDone = computed(() => completedRequiredTasks.value === currentRequiredTasks.value.length);
const requiredDocumentPresent = computed(() => {
    const categoryId = props.job?.current_phase?.document_category_id;
    return !categoryId || (props.job?.documents || []).some((document: any) => document.document_category_id === categoryId);
});
const currentApproval = computed(() => (props.job?.phase_approvals || []).find((approval: any) => approval.workflow_phase_id === props.job?.current_phase_id && approval.status === 'approved'));
const phaseWorkReady = computed(() => requiredTasksDone.value && requiredDocumentPresent.value);

function approvePhase() {
    if (!props.job?.current_phase_id) return;
    approvalForm.post(`/flowtrack/jobs/${props.job.id}/phases/${props.job.current_phase_id}/approve`, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['selectedJob'] }),
    });
}

function movePhase() {
    if (!props.job) return;
    move.patch(`/flowtrack/board/jobs/${props.job.id}/phase`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
            router.reload();
        },
    });
}

function saveTeam() {
    if (!props.job) return;
    teamForm.put(`/flowtrack/jobs/${props.job.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            teamOpen.value = false;
            router.reload({ only: ['selectedJob'] });
        },
    });
}
</script>

<template>
    <FlowDrawer :open="open" @close="emit('close')">
        <template v-if="job">
            <div class="ft-drawer-head">
                <div><div class="ft-muted ft-small">{{ job.code }} <span v-if="job.order_code">· {{ job.order_code }}</span></div><h2 style="font-size: 18px; margin: 4px 0">{{ job.title }}</h2><FlowBadge :value="job.health" health /> <FlowBadge :value="job.status" /></div>
                <button class="ft-close-btn" @click="emit('close')">×</button>
            </div>
            <div class="ft-drawer-body">
                <div class="ft-detail-tabs"><button v-for="item in tabs" :key="item" class="ft-detail-tab" :class="{ active: tab === item }" @click="tab = item">{{ item }}</button></div>

                <section v-if="tab === 'Overview'">
                    <div class="ft-summary-grid">
                        <div class="ft-summary-box"><span>Client</span><b>{{ job.client?.name }}</b></div>
                        <div class="ft-summary-box"><span>Product</span><b>{{ job.product?.name || '—' }}</b></div>
                        <div class="ft-summary-box"><span>Quantity</span><b>{{ Number(job.quantity).toLocaleString() }}</b></div>
                        <div class="ft-summary-box"><span>Amount</span><b>{{ job.can_view_financials ? money(job.quoted_amount, job.currency?.code || 'USD') : 'Restricted' }}</b></div>
                        <div class="ft-summary-box"><span>Delivery</span><b>{{ formatDate(job.delivery_date) }}</b></div>
                        <div class="ft-summary-box"><span>Current Phase</span><b>{{ job.current_phase?.name }}</b></div>
                    </div>
                    <div style="margin: 18px 0"><div class="ft-section-head"><h3>Overall Progress</h3><b>{{ job.progress }}%</b></div><FlowProgress :value="job.progress" /></div>
                    <div class="ft-source-note"><b>Next action:</b> {{ job.next_action || 'Review current phase requirements.' }}<br /><span class="ft-muted"><b>Milestone:</b> {{ job.milestone || '—' }}</span></div>
                    <div v-if="job.start_handling !== 'normal'" class="ft-stage-preview"><b>Mid-workflow start</b><div class="ft-help">Started at {{ job.started_phase?.name }}. {{ job.start_reason }}</div></div>
                    <div class="ft-section-head" style="margin-top: 20px"><h3>Assigned Team</h3><button v-if="canManage" class="ft-mini-btn" @click="teamOpen = true">Manage Team</button></div>
                    <div v-for="member in job.members" :key="member.id" class="ft-attention-item" style="cursor: default"><span class="ft-avatar">{{ member.user?.initials || initials(member.user?.name) }}</span><div><div class="ft-item-title">{{ member.user?.name }}</div><div class="ft-item-meta">{{ member.access_level }} · {{ member.can_manage_tasks ? 'Task management' : 'Assigned access' }}</div></div><FlowBadge :value="member.can_view_financials ? 'Financial access' : 'Operational access'" /></div>
                </section>

                <section v-else-if="tab === 'Workflow'">
                    <div class="ft-timeline">
                        <div v-for="phase in phaseHistory" :key="phase.id" class="ft-step" :class="phase.history?.status"><div class="ft-step-dot">{{ phase.history?.status === 'completed' ? '✓' : phase.sequence }}</div><div class="ft-step-label">{{ phase.short_name }}</div></div>
                    </div>
                    <div class="ft-card ft-section-card" style="margin-bottom: 14px">
                        <div class="ft-section-head"><h3>Current Phase Controls</h3><FlowBadge :value="job.current_phase?.task_pack?.name || 'No Task Pack'" /></div>
                        <div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ requiredTasksDone ? '✓' : '!' }}</span><div style="flex: 1"><b>Required tasks</b><div class="ft-muted ft-small">{{ completedRequiredTasks }} of {{ currentRequiredTasks.length }} completed</div></div><FlowBadge :value="requiredTasksDone ? 'Complete' : 'Pending'" /></div>
                        <div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ requiredDocumentPresent ? '✓' : '!' }}</span><div style="flex: 1"><b>Required document</b><div class="ft-muted ft-small">{{ job.current_phase?.document_category?.name || 'No document gate' }}</div></div><FlowBadge :value="requiredDocumentPresent ? 'Available' : 'Missing'" /></div>
                        <div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ !job.current_phase?.requires_approval || currentApproval ? '✓' : '!' }}</span><div style="flex: 1"><b>Phase approval</b><div class="ft-muted ft-small">{{ !job.current_phase?.requires_approval ? 'No approval gate' : currentApproval ? `Approved by ${currentApproval.approved_by?.name || 'authorised user'}` : 'Approval required' }}</div></div><FlowBadge :value="!job.current_phase?.requires_approval ? 'Not Required' : currentApproval ? 'Approved' : 'Pending'" /></div>
                        <div v-if="canManage && job.current_phase?.requires_approval && !currentApproval" class="ft-field" style="margin-top: 14px"><label>Approval note (optional)</label><textarea v-model="approvalForm.notes" rows="2" placeholder="Approval reference or decision note"></textarea><span v-if="approvalForm.errors.approval" class="ft-error">{{ approvalForm.errors.approval }}</span><button class="ft-secondary" :disabled="approvalForm.processing || !phaseWorkReady" @click="approvePhase">{{ approvalForm.processing ? 'Approving…' : 'Approve Current Phase' }}</button><div v-if="!phaseWorkReady" class="ft-help">Complete required tasks and upload the required document before approval.</div></div>
                    </div>
                    <div v-if="canManage" class="ft-card ft-section-card">
                        <div class="ft-section-head"><h3>Move Job Phase</h3><FlowBadge :value="job.current_phase?.name" /></div>
                        <div class="ft-field"><label>Target phase</label><select v-model="move.phase_id"><option v-for="phase in job.workflow?.phases" :key="phase.id" :value="phase.id">{{ phase.sequence }} · {{ phase.name }}{{ !phase.is_skippable ? ' · Required' : '' }}</option></select><span v-if="move.errors.phase_id" class="ft-error">{{ move.errors.phase_id }}</span></div>
                        <div v-if="needsReason" class="ft-field"><label>Reason for skipping phases</label><textarea v-model="move.reason" rows="3" required></textarea></div>
                        <button class="ft-primary" :disabled="move.processing || move.phase_id === job.current_phase_id" @click="movePhase">{{ move.processing ? 'Updating…' : 'Update Phase' }}</button>
                    </div>
                </section>

                <section v-else-if="tab === 'Tasks'">
                    <div class="ft-section-head"><h3>Job Tasks</h3><div class="ft-actions"><span class="ft-muted ft-small">{{ job.tasks?.length || 0 }} tasks</span><button v-if="canManageTasks" class="ft-mini-btn" @click="taskCreateOpen = true">＋ Add Task</button></div></div>
                    <div v-for="task in job.tasks" :key="task.id" class="ft-attention-item" @click="emit('task', task.id)"><span class="ft-avatar">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span><div><div class="ft-item-title">{{ task.title }}</div><div class="ft-item-meta">{{ task.code }} · {{ task.assignee?.name || 'Unassigned' }} · {{ formatDate(task.due_date) }}</div></div><FlowBadge :value="task.status?.name" /></div>
                    <div v-if="!job.tasks?.length" class="ft-empty">No tasks have been created for this job.</div>
                </section>

                <section v-else-if="tab === 'Documents'">
                    <div class="ft-section-head"><h3>Document Archive</h3><a class="ft-link" href="/flowtrack/documents">Open Archive</a></div>
                    <div v-for="document in job.documents" :key="document.id" class="ft-attention-item" style="cursor: default"><div class="ft-doc-icon" style="margin: 0">PDF</div><div><div class="ft-item-title">{{ document.name }}</div><div class="ft-item-meta">{{ document.code }} · {{ document.category?.name || 'Uncategorised' }} · {{ document.versions?.length || 0 }} version(s)</div></div><FlowBadge :value="document.is_final ? 'Final' : 'Working'" /></div>
                    <div v-if="!job.documents?.length" class="ft-empty">No documents uploaded.</div>
                </section>

                <section v-else>
                    <div class="ft-source-note">Job activity is stored as an immutable workspace audit trail. Phase moves, task changes, uploads and access changes are recorded by the backend.</div>
                    <div v-for="history in [...(job.phase_history || [])].reverse()" :key="history.id" class="ft-activity"><span class="ft-signal" :class="history.status === 'skipped' ? '' : 'green'"></span><div><b>{{ history.phase?.name }} · {{ history.status }}</b><div class="ft-muted ft-small">{{ history.notes || (history.entered_at ? `Entered ${new Date(history.entered_at).toLocaleString()}` : 'Pending') }}</div></div></div>
                </section>
            </div>
        </template>
    </FlowDrawer>

    <CreateTaskModal :open="taskCreateOpen" :job="job" :options="options" @close="taskCreateOpen = false" />

    <FlowModal :open="teamOpen" title="Manage Assigned Team" subtitle="Only selected project members, owners, coordinators and administrators can access this job." @close="teamOpen = false">
        <div class="ft-field"><label>Project members</label><select v-model="teamForm.member_ids" multiple style="min-height: 220px"><option v-for="user in options?.users || []" :key="user.id" :value="user.id">{{ user.name }}</option></select><div class="ft-help">The job owner and coordinator are retained automatically with manager access.</div><span v-if="teamForm.errors.member_ids" class="ft-error">{{ teamForm.errors.member_ids }}</span></div>
        <template #footer><button class="ft-ghost" @click="teamOpen = false">Cancel</button><button class="ft-primary" :disabled="teamForm.processing" @click="saveTeam">Save Team</button></template>
    </FlowModal>
</template>
