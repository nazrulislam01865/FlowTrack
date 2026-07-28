<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FlowModal from './FlowModal.vue';
import { records } from '@/lib/flowtrack';

const props = defineProps<{ open: boolean; options: any }>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({
    client_id: null as number | null,
    workflow_template_id: null as number | null,
    start_phase_id: null as number | null,
    product_category_id: null as number | null,
    product_id: null as number | null,
    currency_id: null as number | null,
    priority_id: null as number | null,
    owner_id: null as number | null,
    coordinator_id: null as number | null,
    title: '',
    quantity: 1,
    quoted_amount: 0,
    delivery_date: '',
    start_reason: '',
    member_ids: [] as number[],
    draft: false,
});

const workflows = computed(() => props.options?.workflows || []);
const selectedWorkflow = computed(() => workflows.value.find((item: any) => item.id === form.workflow_template_id));
const phases = computed(() => selectedWorkflow.value?.phases?.filter((phase: any) => phase.allow_job_start) || []);
const selectedPhase = computed(() => phases.value.find((phase: any) => phase.id === form.start_phase_id));
const products = computed(() => records(props.options, 'product').filter((product: any) => !form.product_category_id || product.parent_id === form.product_category_id));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const workflow = workflows.value[0];
        form.defaults({
            client_id: props.options?.clients?.[0]?.id || null,
            workflow_template_id: workflow?.id || null,
            start_phase_id: workflow?.phases?.find((phase: any) => phase.allow_job_start)?.id || null,
            product_category_id: records(props.options, 'product_category')[0]?.id || null,
            product_id: null,
            currency_id: records(props.options, 'currency').find((item: any) => item.code === 'USD')?.id || null,
            priority_id: records(props.options, 'priority').find((item: any) => item.code === 'MED')?.id || null,
            owner_id: props.options?.users?.[0]?.id || null,
            coordinator_id: props.options?.users?.[0]?.id || null,
            title: '',
            quantity: 1,
            quoted_amount: 0,
            delivery_date: '',
            start_reason: '',
            member_ids: [],
            draft: false,
        });
        form.reset();
        form.clearErrors();
    },
);

watch(
    () => form.workflow_template_id,
    () => {
        form.start_phase_id = phases.value[0]?.id || null;
    },
);

function submit(draft = false) {
    form.draft = draft;
    form.post('/flowtrack/jobs', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <FlowModal :open="open" title="Create New Job" subtitle="Start from the appropriate workflow phase and activate its task pack." @close="emit('close')">
        <form class="ft-form-grid" @submit.prevent="submit(false)">
            <div v-if="form.errors.job" class="ft-error full" style="padding: 10px; border-radius: 8px; background: #fff0ef">{{ form.errors.job }}</div>
            <div class="ft-field full"><label>Job title</label><input v-model="form.title" required placeholder="e.g. 5,000 conference drawstring backpacks" /><span v-if="form.errors.title" class="ft-error">{{ form.errors.title }}</span></div>
            <div class="ft-field"><label>Client</label><select v-model="form.client_id" required><option v-for="client in options.clients" :key="client.id" :value="client.id">{{ client.code }} · {{ client.name }}</option></select></div>
            <div class="ft-field"><label>Workflow template</label><select v-model="form.workflow_template_id" required><option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">{{ workflow.code }} · {{ workflow.name }}</option></select></div>
            <div class="ft-field"><label>Starting phase</label><select v-model="form.start_phase_id" required><option v-for="phase in phases" :key="phase.id" :value="phase.id">{{ phase.sequence }} · {{ phase.name }}</option></select><span v-if="form.errors.start_phase_id" class="ft-error">{{ form.errors.start_phase_id }}</span></div>
            <div class="ft-field"><label>Priority</label><select v-model="form.priority_id"><option v-for="item in records(options, 'priority')" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
            <div class="ft-field"><label>Product category</label><select v-model="form.product_category_id"><option :value="null">Select category</option><option v-for="item in records(options, 'product_category')" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
            <div class="ft-field"><label>Product</label><select v-model="form.product_id"><option :value="null">Select product</option><option v-for="item in products" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }}</option></select></div>
            <div class="ft-field"><label>Quantity</label><input v-model.number="form.quantity" type="number" min="1" required /></div>
            <div class="ft-field"><label>Quoted amount</label><input v-model.number="form.quoted_amount" type="number" min="0" step="0.01" /></div>
            <div class="ft-field"><label>Currency</label><select v-model="form.currency_id"><option v-for="item in records(options, 'currency')" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }}</option></select></div>
            <div class="ft-field"><label>Delivery date</label><input v-model="form.delivery_date" type="date" /></div>
            <div class="ft-field"><label>Job owner</label><select v-model="form.owner_id"><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select></div>
            <div class="ft-field"><label>Coordinator</label><select v-model="form.coordinator_id"><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select></div>
            <div class="ft-field full"><label>Additional assigned members</label><select v-model="form.member_ids" multiple style="min-height: 100px"><option v-for="user in options.users" :key="user.id" :value="user.id">{{ user.name }}</option></select><div class="ft-help">Project access is granted only to selected members, owners, coordinators, and administrators.</div></div>
            <div v-if="selectedPhase?.sequence > 1" class="ft-field full"><label>Reason for starting at {{ selectedPhase.name }}</label><textarea v-model="form.start_reason" rows="3" required placeholder="Reference the prior approved order, existing artwork, PO, or other reason for skipping earlier phases."></textarea><span v-if="form.errors.start_reason" class="ft-error">{{ form.errors.start_reason }}</span></div>
            <div class="ft-source-note full">Tasks from <b>{{ selectedPhase?.task_pack?.name || 'the selected phase task pack' }}</b> will be created automatically after the job is saved.</div>
        </form>
        <template #footer>
            <button class="ft-ghost" type="button" @click="emit('close')">Cancel</button>
            <button class="ft-secondary" type="button" :disabled="form.processing" @click="submit(true)">Save Draft</button>
            <button class="ft-primary" type="button" :disabled="form.processing" @click="submit(false)">{{ form.processing ? 'Creating…' : 'Create Job' }}</button>
        </template>
    </FlowModal>
</template>
