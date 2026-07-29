<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FlowModal from './FlowModal.vue';
import SearchableMultiSelect from './SearchableMultiSelect.vue';
import SearchableSelect from './SearchableSelect.vue';
import { records } from '@/lib/flowtrack';

type ProductLine = {
    product_id: number | null;
    product_category_id: number | null;
    quantity: number;
};

const props = defineProps<{ open: boolean; options: any }>();
const emit = defineEmits<{ close: [] }>();

const blankProductLine = (categoryId: number | null = null, quantity = 1): ProductLine => ({
    product_id: null,
    product_category_id: categoryId,
    quantity,
});

const form = useForm({
    client_id: null as number | null,
    workflow_template_id: null as number | null,
    start_phase_id: null as number | null,
    product_category_id: null as number | null,
    products: [blankProductLine()] as ProductLine[],
    currency_id: null as number | null,
    priority_id: null as number | null,
    owner_id: null as number | null,
    coordinator_id: null as number | null,
    title: '',
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
const categories = computed(() => records(props.options, 'product_category'));
const products = computed(() => records(props.options, 'product').filter((product: any) => !form.product_category_id || product.parent_id === form.product_category_id));
const totalQuantity = computed(() => form.products.reduce((total, item) => total + Math.max(0, Number(item.quantity) || 0), 0));

const clientOptions = computed(() => (props.options?.clients || []).map((client: any) => ({ value: client.id, label: `${client.code} · ${client.name}` })));
const workflowOptions = computed(() => workflows.value.map((workflow: any) => ({ value: workflow.id, label: `${workflow.code} · ${workflow.name}` })));
const phaseOptions = computed(() => phases.value.map((phase: any) => ({ value: phase.id, label: `${phase.sequence} · ${phase.name}` })));
const priorityOptions = computed(() => records(props.options, 'priority').map((item: any) => ({ value: item.id, label: item.name })));
const categoryOptions = computed(() => [{ value: null, label: 'Select category' }, ...categories.value.map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` }))]);
const productOptions = computed(() => [{ value: null, label: products.value.length ? 'Select product' : 'No products available in this category', disabled: !products.value.length }, ...products.value.map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` }))]);
const currencyOptions = computed(() => records(props.options, 'currency').map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` })));
const userOptions = computed(() => (props.options?.users || []).map((user: any) => ({ value: user.id, label: user.name })));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const workflow = workflows.value[0];
        const categoryId = categories.value[0]?.id || null;
        form.defaults({
            client_id: props.options?.clients?.[0]?.id || null,
            workflow_template_id: workflow?.id || null,
            start_phase_id: workflow?.phases?.find((phase: any) => phase.allow_job_start)?.id || null,
            product_category_id: categoryId,
            products: [blankProductLine(categoryId)],
            currency_id: records(props.options, 'currency').find((item: any) => item.code === 'USD')?.id || null,
            priority_id: records(props.options, 'priority').find((item: any) => item.code === 'MED')?.id || null,
            owner_id: props.options?.users?.[0]?.id || null,
            coordinator_id: props.options?.users?.[0]?.id || null,
            title: '',
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

watch(
    () => form.product_category_id,
    (categoryId) => {
        const allowedProductIds = new Set(products.value.map((product: any) => Number(product.id)));
        form.products = form.products.map((line) => ({
            ...line,
            product_category_id: categoryId,
            product_id: line.product_id && allowedProductIds.has(Number(line.product_id)) ? line.product_id : null,
        }));
    },
);

function addProduct(): void {
    form.products.push(blankProductLine(form.product_category_id));
}

function removeProduct(index: number): void {
    if (form.products.length === 1) return;
    form.products.splice(index, 1);
}

function productError(index: number, field: 'product_id' | 'quantity'): string | undefined {
    return (form.errors as Record<string, string>)[`products.${index}.${field}`];
}

function submit(draft = false): void {
    form.draft = draft;
    form.products = form.products.map((line) => ({
        product_id: line.product_id,
        product_category_id: form.product_category_id,
        quantity: Math.max(1, Number(line.quantity) || 1),
    }));
    form.post('/flowtrack/jobs', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <FlowModal :open="open" title="Create New Job" subtitle="Enter each field in order and add every requested product." @close="emit('close')">
        <form class="ft-form-grid ft-job-form-stack" @submit.prevent="submit(false)">
            <div v-if="form.errors.job" class="ft-error full" style="padding: 10px; border-radius: 8px; background: #fff0ef">{{ form.errors.job }}</div>

            <div class="ft-field full">
                <label>Client</label>
                <SearchableSelect v-model="form.client_id" :options="clientOptions" placeholder="Select client" search-placeholder="Search clients…" />
                <span v-if="form.errors.client_id" class="ft-error">{{ form.errors.client_id }}</span>
            </div>

            <div class="ft-field full">
                <label>Job title</label>
                <input v-model="form.title" required placeholder="e.g. Conference merchandise order" />
                <span v-if="form.errors.title" class="ft-error">{{ form.errors.title }}</span>
            </div>

            <div class="ft-field full">
                <label>Workflow template</label>
                <SearchableSelect v-model="form.workflow_template_id" :options="workflowOptions" placeholder="Select workflow template" search-placeholder="Search workflow templates…" />
            </div>

            <div class="ft-field full">
                <label>Starting phase</label>
                <SearchableSelect v-model="form.start_phase_id" :options="phaseOptions" placeholder="Select starting phase" search-placeholder="Search starting phases…" />
                <span v-if="form.errors.start_phase_id" class="ft-error">{{ form.errors.start_phase_id }}</span>
            </div>

            <div class="ft-field full">
                <label>Priority</label>
                <SearchableSelect v-model="form.priority_id" :options="priorityOptions" placeholder="Select priority" search-placeholder="Search priorities…" />
            </div>

            <div class="ft-field full">
                <label>Product category</label>
                <SearchableSelect v-model="form.product_category_id" :options="categoryOptions" placeholder="Select category" search-placeholder="Search product categories…" />
            </div>

            <div class="ft-field full ft-product-lines-field">
                <div class="ft-product-lines-heading">
                    <div>
                        <label>Products & quantities</label>
                        <div class="ft-help">Product and Quantity stay on the same line. Add more rows as needed.</div>
                    </div>
                    <div class="ft-product-total">{{ totalQuantity.toLocaleString() }} total units</div>
                </div>

                <div class="ft-product-line-labels"><span>Product</span><span>Quantity</span><span></span></div>
                <div class="ft-product-lines">
                    <div v-for="(line, index) in form.products" :key="index" class="ft-product-line">
                        <div>
                            <SearchableSelect v-model="line.product_id" :options="productOptions" placeholder="Select product" search-placeholder="Search products…" />
                            <span v-if="productError(index, 'product_id')" class="ft-error">{{ productError(index, 'product_id') }}</span>
                        </div>
                        <div>
                            <input v-model.number="line.quantity" type="number" min="1" max="1000000000" required />
                            <span v-if="productError(index, 'quantity')" class="ft-error">{{ productError(index, 'quantity') }}</span>
                        </div>
                        <button type="button" class="ft-remove-product" :disabled="form.products.length === 1" :aria-label="`Remove product ${index + 1}`" @click="removeProduct(index)">×</button>
                    </div>
                </div>
                <button type="button" class="ft-secondary ft-add-product" @click="addProduct">＋ Add another product</button>
                <span v-if="form.errors.products" class="ft-error">{{ form.errors.products }}</span>
            </div>

            <div class="ft-field full">
                <label>Quoted amount</label>
                <input v-model.number="form.quoted_amount" type="number" min="0" step="0.01" />
            </div>

            <div class="ft-field full">
                <label>Currency</label>
                <SearchableSelect v-model="form.currency_id" :options="currencyOptions" placeholder="Select currency" search-placeholder="Search currencies…" />
            </div>

            <div class="ft-field full">
                <label>Delivery date</label>
                <input v-model="form.delivery_date" type="date" required />
            </div>

            <div class="ft-field full">
                <label>Job owner</label>
                <SearchableSelect v-model="form.owner_id" :options="userOptions" placeholder="Select job owner" search-placeholder="Search users…" />
            </div>

            <div class="ft-field full">
                <label>Coordinator</label>
                <SearchableSelect v-model="form.coordinator_id" :options="userOptions" placeholder="Select coordinator" search-placeholder="Search users…" />
            </div>

            <div class="ft-field full">
                <label>Additional assigned members</label>
                <SearchableMultiSelect v-model="form.member_ids" :options="userOptions" placeholder="Select additional members" search-placeholder="Search members…" />
                <div class="ft-help">Project access is granted only to selected members, owners, coordinators, and administrators.</div>
            </div>

            <div v-if="selectedPhase?.sequence > 1" class="ft-field full">
                <label>Reason for starting at {{ selectedPhase.name }}</label>
                <textarea v-model="form.start_reason" rows="3" required placeholder="Reference the prior confirmed order, existing artwork, PO, or other reason for skipping earlier phases."></textarea>
                <span v-if="form.errors.start_reason" class="ft-error">{{ form.errors.start_reason }}</span>
            </div>

            <div class="ft-source-note full">Tasks from <b>{{ selectedPhase?.task_pack?.name || 'the selected phase task pack' }}</b> will be created automatically after the Job is saved.</div>
        </form>
        <template #footer>
            <button class="ft-ghost" type="button" @click="emit('close')">Cancel</button>
            <button class="ft-secondary" type="button" :disabled="form.processing" @click="submit(true)">Save Draft</button>
            <button class="ft-primary" type="button" :disabled="form.processing" @click="submit(false)">{{ form.processing ? 'Creating…' : 'Create Job' }}</button>
        </template>
    </FlowModal>
</template>
