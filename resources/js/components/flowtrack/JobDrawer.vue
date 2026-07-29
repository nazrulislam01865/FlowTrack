<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FlowBadge from './FlowBadge.vue';
import FlowDrawer from './FlowDrawer.vue';
import FlowProgress from './FlowProgress.vue';
import FlowModal from './FlowModal.vue';
import CreateTaskModal from './CreateTaskModal.vue';
import { formatDate, initials, money, records } from '@/lib/flowtrack';

const props = defineProps<{ open: boolean; job: any | null; options: any }>();
const emit = defineEmits<{ close: []; task: [id: number] }>();
const page = usePage<any>();
const tab = ref('Overview');
const tabs = ['Overview', 'Workflow', 'Tasks', 'Documents', 'Activity'];
const move = useForm({ phase_id: null as number | null, reason: '' });
const teamOpen = ref(false);
const taskCreateOpen = ref(false);
const deleteOpen = ref(false);
const deleteConfirmed = ref(false);
const teamForm = useForm({ member_ids: [] as number[] });

watch(() => props.job?.id, () => {
    tab.value = 'Overview';
    move.phase_id = props.job?.current_phase_id || null;
    move.reason = '';
    teamForm.member_ids = (props.job?.members || []).map((member: any) => member.user_id);
    deleteOpen.value = false;
    deleteConfirmed.value = false;
});

const phaseHistory = computed(() => {
    const histories = new Map((props.job?.phase_history || []).map((item: any) => [item.workflow_phase_id, item]));
    return (props.job?.workflow?.phases || []).map((phase: any) => ({ ...phase, history: histories.get(phase.id) }));
});
const selectedPhase = computed(() => props.job?.workflow?.phases?.find((phase: any) => Number(phase.id) === Number(move.phase_id)));
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
const canDeleteJob = computed(() => (page.props.auth?.permissions || []).includes('jobs.manage-all'));
const canUploadDocuments = computed(() => {
    const permissions: string[] = page.props.auth?.permissions || [];
    return permissions.includes('documents.link') || permissions.includes('documents.manage');
});
const needsReason = computed(() => {
    if (!selectedPhase.value || !props.job?.current_phase) return false;
    return selectedPhase.value.sequence > props.job.current_phase.sequence + 1;
});

const currentRequiredTasks = computed(() => (props.job?.tasks || []).filter((task: any) =>
    Number(task.workflow_phase_id) === Number(props.job?.current_phase_id) && task.pack_item?.is_required,
));
const completedRequiredTasks = computed(() => currentRequiredTasks.value.filter((task: any) => Boolean(task.completed_at)).length);
const requiredTasksDone = computed(() => completedRequiredTasks.value === currentRequiredTasks.value.length);
const documentCategories = computed(() => records(props.options, 'document_category'));
const currentDocumentRequirements = computed(() => {
    const phase = props.job?.current_phase;
    const requirements: any[] = [];
    const categoryById = new Map(documentCategories.value.map((category: any) => [Number(category.id), category]));

    if (phase?.document_category_id) {
        const category = phase.document_category || categoryById.get(Number(phase.document_category_id));
        requirements.push({
            id: Number(phase.document_category_id),
            phaseId: Number(phase.id),
            taskPackItemId: null,
            name: category?.name || 'Required document',
            source: `Workflow Phase: ${phase.name}`,
            requiredForReadiness: true,
        });
    }

    for (const item of phase?.task_pack?.items || []) {
        if (!item.document_category_id) continue;
        const category = item.document_category || categoryById.get(Number(item.document_category_id));
        requirements.push({
            id: Number(item.document_category_id),
            phaseId: Number(phase.id),
            taskPackItemId: Number(item.id),
            name: category?.name || 'Required document',
            source: `Task: ${item.title}`,
            requiredForReadiness: Boolean(item.is_required),
        });
    }

    return [...new Map(requirements.map((item) => [
        `${item.phaseId}:${item.taskPackItemId || 0}:${item.id}`,
        item,
    ])).values()];
});
const readinessDocumentRequirements = computed(() => currentDocumentRequirements.value.filter((item: any) => item.requiredForReadiness));
const missingDocumentRequirements = computed(() => readinessDocumentRequirements.value.filter((requirement: any) =>
    !(props.job?.documents || []).some((document: any) =>
        Number(document.workflow_phase_id) === Number(requirement.phaseId)
        && Number(document.document_category_id) === Number(requirement.id)
        && Number(document.task_pack_item_id || 0) === Number(requirement.taskPackItemId || 0),
    ),
));
const requiredDocumentPresent = computed(() => missingDocumentRequirements.value.length === 0);
const currentPhaseHistory = computed(() => (props.job?.phase_history || []).find((item: any) => Number(item.workflow_phase_id) === Number(props.job?.current_phase_id)));
const phaseReadinessDetails = computed(() => currentPhaseHistory.value?.readiness_details || {});
const phaseReadiness = computed(() => currentPhaseHistory.value?.readiness_status || 'in_progress');

function openDocumentUpload() {
    if (!props.job) return;
    router.get('/flowtrack/documents', { job: props.job.id, upload: 1 });
}

function deleteJob() {
    if (!props.job || !deleteConfirmed.value) return;
    router.delete(`/flowtrack/jobs/${props.job.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteOpen.value = false;
            deleteConfirmed.value = false;
            emit('close');
        },
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
                <div class="ft-actions"><button v-if="canDeleteJob" class="ft-ghost" @click="deleteOpen = true">Job Actions</button><button class="ft-close-btn" @click="emit('close')">×</button></div>
            </div>
            <div class="ft-drawer-body">
                <div class="ft-detail-tabs"><button v-for="item in tabs" :key="item" class="ft-detail-tab" :class="{ active: tab === item }" @click="tab = item">{{ item }}</button></div>

                <section v-if="tab === 'Overview'">
                    <div class="ft-summary-grid">
                        <div class="ft-summary-box"><span>Client</span><b>{{ job.client?.name }}</b></div>
                        <div class="ft-summary-box"><span>Products</span><b>{{ job.items?.length || (job.product ? 1 : 0) }} line{{ (job.items?.length || (job.product ? 1 : 0)) === 1 ? '' : 's' }}</b></div>
                        <div class="ft-summary-box"><span>Total Quantity</span><b>{{ Number(job.quantity).toLocaleString() }}</b></div>
                        <div class="ft-summary-box"><span>Amount</span><b>{{ job.can_view_financials ? money(job.quoted_amount, job.currency?.code || 'USD') : 'Restricted' }}</b></div>
                        <div class="ft-summary-box"><span>Delivery</span><b>{{ formatDate(job.delivery_date) }}</b></div>
                        <div class="ft-summary-box"><span>Current Phase</span><b>{{ job.current_phase?.name }}</b></div>
                    </div>
                    <div class="ft-card" style="margin-top: 16px; overflow: hidden">
                        <div class="ft-section-head" style="padding: 12px 14px; margin: 0; background: #f8fafc"><h3>Product Lines</h3><span class="ft-muted ft-small">{{ Number(job.quantity).toLocaleString() }} total units</span></div>
                        <div v-if="job.items?.length">
                            <div v-for="item in job.items" :key="item.id" class="ft-attention-item" style="margin: 0; border-width: 1px 0 0; border-radius: 0; cursor: default">
                                <span class="ft-avatar">{{ item.product?.code?.slice(0, 2) || 'PR' }}</span>
                                <div><div class="ft-item-title">{{ item.product?.name || 'Deleted product' }}</div><div class="ft-item-meta">{{ item.product_category?.name || 'Uncategorised' }}</div></div>
                                <b>{{ Number(item.quantity).toLocaleString() }} units</b>
                            </div>
                        </div>
                        <div v-else class="ft-attention-item" style="margin: 0; border-width: 1px 0 0; border-radius: 0; cursor: default">
                            <span class="ft-avatar">PR</span><div><div class="ft-item-title">{{ job.product?.name || 'Unspecified product' }}</div><div class="ft-item-meta">{{ job.product_category?.name || 'Uncategorised' }}</div></div><b>{{ Number(job.quantity).toLocaleString() }} units</b>
                        </div>
                    </div>
                    <div style="margin: 18px 0"><div class="ft-section-head"><h3>Overall Progress</h3><b>{{ job.progress }}%</b></div><FlowProgress :value="job.progress" /></div>
                    <div v-if="job.health_reason" class="ft-source-note" style="margin-bottom: 12px"><b>Job health:</b> {{ job.health_reason }}</div><div class="ft-source-note"><b>Next action:</b> {{ job.next_action || 'Review current phase requirements.' }}<br /><span class="ft-muted"><b>Milestone:</b> {{ job.milestone || '—' }}</span></div>
                    <div v-if="job.start_handling !== 'normal'" class="ft-stage-preview"><b>Mid-workflow start</b><div class="ft-help">Started at {{ job.started_phase?.name }}. {{ job.start_reason }}</div></div>
                    <div class="ft-section-head" style="margin-top: 20px"><h3>Assigned Team</h3><button v-if="canManage" class="ft-mini-btn" @click="teamOpen = true">Manage Team</button></div>
                    <div v-for="member in job.members" :key="member.id" class="ft-attention-item" style="cursor: default"><span class="ft-avatar">{{ member.user?.initials || initials(member.user?.name) }}</span><div><div class="ft-item-title">{{ member.user?.name }}</div><div class="ft-item-meta">{{ member.access_level }} · {{ member.can_manage_tasks ? 'Task management' : 'Assigned access' }}</div></div><FlowBadge :value="member.can_view_financials ? 'Financial access' : 'Operational access'" /></div>
                </section>

                <section v-else-if="tab === 'Workflow'">
                    <div class="ft-timeline">
                        <div v-for="phase in phaseHistory" :key="phase.id" class="ft-step" :class="phase.history?.status"><div class="ft-step-dot">{{ phase.history?.status === 'completed' ? '✓' : phase.sequence }}</div><div class="ft-step-label">{{ phase.short_name }}</div></div>
                    </div>
                    <div class="ft-card ft-section-card" style="margin-bottom: 14px">
                        <div class="ft-section-head"><h3>Current Phase Controls</h3><div class="ft-actions"><FlowBadge :value="phaseReadiness" /><FlowBadge :value="job.current_phase?.auto_advance_on_ready ? 'Auto Move' : 'Manual Move'" /></div></div><div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ phaseReadiness === 'ready' ? '✓' : '!' }}</span><div style="flex:1"><b>Backend phase readiness</b><div class="ft-muted ft-small">{{ phaseReadinessDetails.blockers?.join(' ') || 'Every configured gate is satisfied.' }}</div></div><FlowBadge :value="phaseReadiness" /></div>
                        <div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ requiredTasksDone ? '✓' : '!' }}</span><div style="flex: 1"><b>Required tasks</b><div class="ft-muted ft-small">{{ completedRequiredTasks }} of {{ currentRequiredTasks.length }} completed</div></div><FlowBadge :value="requiredTasksDone ? 'Complete' : 'Pending'" /></div>
                        <div class="ft-check-row"><span class="ft-avatar" style="width: 24px; height: 24px; font-size: 10px">{{ requiredDocumentPresent ? '✓' : '!' }}</span><div style="flex: 1"><b>Required documents</b><div class="ft-muted ft-small">{{ currentDocumentRequirements.length ? currentDocumentRequirements.map((item) => item.name).join(', ') : 'No document gate' }}</div></div><button v-if="canUploadDocuments && currentDocumentRequirements.length && !requiredDocumentPresent" class="ft-secondary" @click="openDocumentUpload(true)">Open Document Upload</button><FlowBadge v-else :value="requiredDocumentPresent ? 'Available' : 'Missing'" /></div>
                    </div>
                    <div v-if="canManage" class="ft-card ft-section-card">
                        <div class="ft-section-head"><h3>Move Job Phase</h3><FlowBadge :value="job.current_phase?.name" /></div>
                        <div class="ft-field"><label>Target phase</label><select v-model.number="move.phase_id"><option v-for="phase in job.workflow?.phases" :key="phase.id" :value="phase.id">{{ phase.sequence }} · {{ phase.name }}{{ !phase.is_skippable ? ' · Required' : '' }}</option></select><span v-if="move.errors.phase_id" class="ft-error">{{ move.errors.phase_id }}</span></div>
                        <div v-if="needsReason" class="ft-field"><label>Reason for skipping phases</label><textarea v-model="move.reason" rows="3" required></textarea></div>
                        <button class="ft-primary" :disabled="move.processing || Number(move.phase_id) === Number(job.current_phase_id)" @click="movePhase">{{ move.processing ? 'Updating…' : 'Update Phase' }}</button>
                    </div>
                </section>

                <section v-else-if="tab === 'Tasks'">
                    <div class="ft-section-head"><h3>Job Tasks</h3><div class="ft-actions"><span class="ft-muted ft-small">{{ job.tasks?.length || 0 }} tasks</span><button v-if="canManageTasks" class="ft-mini-btn" @click="taskCreateOpen = true">＋ Add Task</button></div></div>
                    <div v-for="task in job.tasks" :key="task.id" class="ft-attention-item" @click="emit('task', task.id)"><span class="ft-avatar">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span><div><div class="ft-item-title">{{ task.title }}</div><div class="ft-item-meta">{{ task.code }} · {{ task.assignee?.name || 'Unassigned' }} · {{ formatDate(task.due_date) }}</div></div><FlowBadge :value="task.status?.name" /></div>
                    <div v-if="!job.tasks?.length" class="ft-empty">No tasks have been created for this job.</div>
                </section>

                <section v-else-if="tab === 'Documents'">
                    <div class="ft-section-head"><h3>Document Archive</h3><div class="ft-actions"><a class="ft-link" href="/flowtrack/documents">Open Archive</a><button v-if="canUploadDocuments && currentDocumentRequirements.length" class="ft-primary" @click="openDocumentUpload(false)">Open Documents Page</button></div></div>
                    <div v-for="document in job.documents" :key="document.id" class="ft-attention-item" style="cursor: default"><div class="ft-doc-icon" style="margin: 0">PDF</div><div><div class="ft-item-title">{{ document.name }}</div><div class="ft-item-meta">{{ document.code }} · {{ document.category?.name || 'Uncategorised' }} · {{ document.versions?.length || 0 }} version(s)</div></div><FlowBadge :value="document.is_final ? 'Final' : 'Working'" /></div>
                    <div v-if="!job.documents?.length" class="ft-empty">No documents uploaded.</div>
                </section>

                <section v-else>
                    <div class="ft-source-note">Job activity is stored as an immutable workspace audit trail. Phase moves, task changes, uploads and access changes are recorded by the backend.</div>
                    <div v-for="event in job.transition_events || []" :key="`event-${event.id}`" class="ft-activity"><span class="ft-signal green"></span><div><b>{{ String(event.transition_type).replaceAll('.', ' ') }} · {{ event.from_value || '—' }} → {{ event.to_value || '—' }}</b><div class="ft-muted ft-small">{{ event.reason || event.source }} · {{ event.actor?.name || 'System' }} · {{ event.occurred_at ? new Date(event.occurred_at).toLocaleString() : '' }}</div></div></div><div v-for="history in [...(job.phase_history || [])].reverse()" :key="history.id" class="ft-activity"><span class="ft-signal" :class="history.status === 'skipped' ? '' : 'green'"></span><div><b>{{ history.phase?.name }} · {{ history.status }} · {{ history.readiness_status || 'pending' }}</b><div class="ft-muted ft-small">{{ history.notes || (history.entered_at ? `Entered ${new Date(history.entered_at).toLocaleString()}` : 'Pending') }}</div></div></div>
                </section>
            </div>
        </template>
    </FlowDrawer>

    <CreateTaskModal :open="taskCreateOpen" :job="job" :options="options" @close="taskCreateOpen = false" />

    <FlowModal :open="teamOpen" title="Manage Assigned Team" subtitle="Only selected project members, owners, coordinators and administrators can access this job." @close="teamOpen = false">
        <div class="ft-field"><label>Project members</label><select v-model="teamForm.member_ids" multiple style="min-height: 220px"><option v-for="user in options?.users || []" :key="user.id" :value="user.id">{{ user.name }}</option></select><div class="ft-help">The job owner and coordinator are retained automatically with manager access.</div><span v-if="teamForm.errors.member_ids" class="ft-error">{{ teamForm.errors.member_ids }}</span></div>
        <template #footer><button class="ft-ghost" @click="teamOpen = false">Cancel</button><button class="ft-primary" :disabled="teamForm.processing" @click="saveTeam">Save Team</button></template>
    </FlowModal>

    <FlowModal :open="deleteOpen" title="Delete this Job?" subtitle="Review the impact before permanently deleting the Job." @close="deleteOpen = false">
        <div class="ft-card" style="padding: 16px; border-color: #fecaca; background: #fff7f7"><div class="ft-muted ft-small">{{ job?.code }}</div><h3 style="margin: 5px 0 8px">{{ job?.title }}</h3><div class="ft-small">Deleting this Job also removes <b>{{ job?.tasks?.length || 0 }} Tasks</b> and <b>{{ job?.documents?.length || 0 }} document records</b> connected to it.</div></div>
        <label class="ft-check-row" style="margin-top: 16px"><input v-model="deleteConfirmed" type="checkbox" /> <span>I understand that this action cannot be undone.</span></label>
        <template #footer><button class="ft-ghost" @click="deleteOpen = false">Keep Job</button><button class="ft-danger" :disabled="!deleteConfirmed" @click="deleteJob">Delete Job Permanently</button></template>
    </FlowModal>
</template>
