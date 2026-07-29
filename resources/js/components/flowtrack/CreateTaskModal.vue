<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { records } from '@/lib/flowtrack';
import FlowModal from './FlowModal.vue';

const props = defineProps<{ open: boolean; job: any | null; options: any }>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({
    workflow_phase_id: null as number | null,
    parent_id: null as number | null,
    dependency_id: null as number | null,
    assignee_id: null as number | null,
    priority_id: null as number | null,
    title: '',
    description: '',
    start_rule: 'When the selected workflow phase begins',
    due_date: '',
    attention_required: false,
    blocker_reason: '',
    checklist: [] as string[],
    checklist_text: '',
});

watch(
    () => [props.open, props.job?.id],
    ([open]) => {
        if (!open || !props.job) return;
        form.reset();
        form.workflow_phase_id = props.job.current_phase_id;
        form.assignee_id = props.job.coordinator_id;
        form.priority_id = props.job.priority_id;
        form.due_date = props.job.delivery_date || '';
        form.clearErrors();
    },
);

function submit() {
    if (!props.job) return;
    form.checklist = form.checklist_text
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean);

    form.post(`/flowtrack/jobs/${props.job.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
            router.reload({ only: ['selectedJob', 'jobs', 'tasks'] });
        },
    });
}
</script>

<template>
    <FlowModal :open="open" title="Create Job Task" subtitle="Assign structured work inside this Job, similar to a Jira task." @close="emit('close')">
        <div class="ft-form-grid">
            <div class="ft-field full"><label>Task title</label><input v-model="form.title" placeholder="What needs to be completed?" /><span v-if="form.errors.title" class="ft-error">{{ form.errors.title }}</span></div>
            <div class="ft-field"><label>Workflow phase</label><select v-model="form.workflow_phase_id"><option :value="null">General Job task</option><option v-for="phase in job?.workflow?.phases || []" :key="phase.id" :value="phase.id">{{ phase.sequence }} · {{ phase.name }}</option></select></div>
            <div class="ft-field"><label>Assignee</label><select v-model="form.assignee_id"><option :value="null">Unassigned</option><option v-for="user in options?.users || []" :key="user.id" :value="user.id">{{ user.name }}</option></select><span v-if="form.errors.assignee_id" class="ft-error">{{ form.errors.assignee_id }}</span></div>
            <div class="ft-field"><label>Priority</label><select v-model="form.priority_id"><option :value="null">Use Job priority</option><option v-for="priority in records(options, 'priority')" :key="priority.id" :value="priority.id">{{ priority.name }}</option></select></div>
            <div class="ft-field"><label>Due date</label><input v-model="form.due_date" type="date" /></div>
            <div class="ft-field"><label>Parent task</label><select v-model="form.parent_id"><option :value="null">No parent</option><option v-for="task in job?.tasks || []" :key="task.id" :value="task.id">{{ task.code }} · {{ task.title }}</option></select></div>
            <div class="ft-field"><label>Dependency</label><select v-model="form.dependency_id"><option :value="null">No dependency</option><option v-for="task in job?.tasks || []" :key="task.id" :value="task.id">After {{ task.code }} · {{ task.title }}</option></select></div>
            <div class="ft-field full"><label>Starts when</label><input v-model="form.start_rule" placeholder="e.g. After artwork review" /></div>
            <div class="ft-field full"><label>Description</label><textarea v-model="form.description" rows="4" placeholder="Specifications, acceptance criteria, links, or handoff notes"></textarea></div>
            <div class="ft-field full"><label>Checklist items</label><textarea v-model="form.checklist_text" rows="4" placeholder="One checklist item per line"></textarea></div>
            <label class="ft-check-row full"><input v-model="form.attention_required" type="checkbox" /> Mark as attention needed</label>
            <div v-if="form.attention_required" class="ft-field full"><label>Attention / blocker reason</label><textarea v-model="form.blocker_reason" rows="2"></textarea></div>
            <span v-if="form.errors.task" class="ft-error full">{{ form.errors.task }}</span>
        </div>
        <template #footer><button class="ft-ghost" @click="emit('close')">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="submit">{{ form.processing ? 'Creating…' : 'Create Task' }}</button></template>
    </FlowModal>
</template>
