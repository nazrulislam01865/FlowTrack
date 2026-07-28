<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import FlowBadge from './FlowBadge.vue';
import FlowDrawer from './FlowDrawer.vue';
import { dueClass, formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{ open: boolean; taskId: number | null; options: any }>();
const emit = defineEmits<{ close: [] }>();
const task = ref<any>(null);
const loading = ref(false);
const form = useForm({
    task_status_id: null as number | null,
    assignee_id: null as number | null,
    progress: 0,
    attention_required: false,
    blocker_reason: '',
    completed_checklist_ids: [] as number[],
    comment: '',
});

watch(
    () => [props.open, props.taskId],
    async ([open, id]) => {
        if (!open || !id) return;
        loading.value = true;
        try {
            const response = await fetch(`/flowtrack/tasks/${id}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Unable to load task.');
            task.value = await response.json();
            form.task_status_id = task.value.task_status_id;
            form.assignee_id = task.value.assignee_id;
            form.progress = task.value.progress;
            form.attention_required = task.value.attention_required;
            form.blocker_reason = task.value.blocker_reason || '';
            form.completed_checklist_ids = task.value.checklist_items.filter((item: any) => item.is_completed).map((item: any) => item.id);
            form.comment = '';
            form.clearErrors();
        } finally {
            loading.value = false;
        }
    },
);


function progressForStatus(statusId: number | null): number {
    const status = records(props.options, 'task_status').find((item: any) => item.id === statusId);
    const code = String(status?.code || status?.name || '').toUpperCase();
    if (['CMP', 'COMPLETED'].includes(code)) return 100;
    if (['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL'].includes(code)) return 65;
    if (['IP', 'IN PROGRESS', 'BLK', 'BLOCKED', 'HOLD', 'ON HOLD'].includes(code)) return 50;
    if (['REV', 'REVISION REQUIRED'].includes(code)) return 35;
    if (code === 'READY') return 10;
    return 0;
}

watch(
    () => form.task_status_id,
    (statusId) => {
        form.progress = progressForStatus(statusId);
    },
);

function save() {
    if (!task.value) return;
    form.put(`/flowtrack/tasks/${task.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
            router.reload({ only: ['tasks'] });
        },
    });
}
</script>

<template>
    <FlowDrawer :open="open" @close="emit('close')">
        <div class="ft-drawer-head">
            <div v-if="task"><div class="ft-muted ft-small">{{ task.code }} · {{ task.job?.code }}</div><h2 style="font-size: 18px; margin: 4px 0">{{ task.title }}</h2><FlowBadge :value="task.status?.name" /> <FlowBadge :value="task.priority?.name" /></div>
            <button class="ft-close-btn" @click="emit('close')">×</button>
        </div>
        <div class="ft-drawer-body">
            <div v-if="loading" class="ft-empty">Loading task…</div>
            <template v-else-if="task">
                <div class="ft-summary-grid">
                    <div class="ft-summary-box"><span>Job</span><b>{{ task.job?.title }}</b></div>
                    <div class="ft-summary-box"><span>Assignee</span><b>{{ task.assignee?.name || 'Unassigned' }}</b></div>
                    <div class="ft-summary-box"><span>Due Date</span><b class="ft-due" :class="dueClass(task.due_date)">{{ formatDate(task.due_date) }}</b></div>
                    <div class="ft-summary-box"><span>Workflow Phase</span><b>{{ task.phase?.name || 'General' }}</b></div>
                    <div class="ft-summary-box"><span>Parent Task</span><b>{{ task.parent?.code || 'None' }}</b></div>
                    <div class="ft-summary-box"><span>Dependency</span><b>{{ task.dependency?.code || 'None' }}</b></div>
                </div>
                <div v-if="task.start_rule" class="ft-source-note"><b>Start rule:</b> {{ task.start_rule }}</div>
                <div class="ft-form-grid" style="margin-top: 18px">
                    <div class="ft-field"><label>Status</label><select v-model="form.task_status_id"><option v-for="status in records(options, 'task_status')" :key="status.id" :value="status.id">{{ status.name }}</option></select></div>
                    <div class="ft-field"><label>Assignee</label><select v-model="form.assignee_id"><option :value="null">Unassigned</option><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select></div>
                    <div class="ft-field full"><label>Progress: <b>{{ form.progress }}%</b></label><input v-model.number="form.progress" type="range" min="0" max="100" disabled /><div class="ft-help">Progress updates automatically from the selected status.</div></div>
                </div>
                <div class="ft-section-head"><h3>Checklist</h3><span class="ft-muted ft-small">{{ form.completed_checklist_ids.length }}/{{ task.checklist_items.length }} complete</span></div>
                <label v-for="item in task.checklist_items" :key="item.id" class="ft-check-row"><input v-model="form.completed_checklist_ids" type="checkbox" :value="item.id" /> {{ item.label }}</label>
                <div class="ft-field" style="margin-top: 18px"><label>Blocker / attention reason</label><textarea v-model="form.blocker_reason" rows="3" placeholder="Required when the task is blocked or attention is needed"></textarea><span v-if="form.errors.blocker_reason" class="ft-error">{{ form.errors.blocker_reason }}</span></div>
                <label class="ft-check-row"><input v-model="form.attention_required" type="checkbox" /> Mark as attention needed</label>
                <div class="ft-field" style="margin-top: 18px"><label>Update comment</label><textarea v-model="form.comment" rows="3" placeholder="Add progress, a decision, blocker, or next action"></textarea></div>
                <div class="ft-section-head" style="margin-top: 18px"><h3>Activity & Comments</h3></div>
                <div v-for="comment in task.comments" :key="comment.id" class="ft-comment"><span class="ft-avatar">{{ comment.user?.initials || initials(comment.user?.name) }}</span><div><b>{{ comment.user?.name || 'System' }}</b><div style="margin-top: 4px">{{ comment.body }}</div><div class="ft-muted ft-small" style="margin-top: 4px">{{ new Date(comment.created_at).toLocaleString() }}</div></div></div>
                <button class="ft-primary" style="width: 100%; margin-top: 18px" :disabled="form.processing" @click="save">{{ form.processing ? 'Saving…' : 'Save Task Update' }}</button>
            </template>
        </div>
    </FlowDrawer>
</template>
