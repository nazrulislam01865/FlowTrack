<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FlowModal from './FlowModal.vue';
import SearchableSelect from './SearchableSelect.vue';
import { records } from '@/lib/flowtrack';

type ProductLine = { product_category_id: number | null; product_id: number | null; quantity: number };
const props = defineProps<{ open: boolean; job: any | null; options: any }>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({
    client_id: null as number | null,
    title: '',
    request_description: '',
    products: [] as ProductLine[],
    currency_id: null as number | null,
    priority_id: null as number | null,
    owner_id: null as number | null,
    coordinator_id: null as number | null,
    quoted_amount: null as number | null,
    delivery_date: '',
});

const categories = computed(() => records(props.options, 'product_category'));
const products = computed(() => records(props.options, 'product'));
const priorities = computed(() => records(props.options, 'priority'));
const clientOptions = computed(() => (props.options?.clients || []).map((client: any) => ({ value: client.id, label: `${client.code} · ${client.name}` })));
const categoryOptions = computed(() => [{ value: null, label: 'Select category' }, ...categories.value.map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` }))]);
const currencyOptions = computed(() => records(props.options, 'currency').map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` })));
const userOptions = computed(() => (props.options?.users || []).map((user: any) => ({ value: user.id, label: user.name })));

function productOptions(categoryId: number | null) {
    const available = products.value.filter((item: any) => Number(item.parent_id) === Number(categoryId));
    return [{ value: null, label: categoryId ? (available.length ? 'Select product' : 'No products available in this category') : 'Select category first', disabled: !available.length }, ...available.map((item: any) => ({ value: item.id, label: `${item.code} · ${item.name}` }))];
}

watch(() => props.open, (open) => {
    if (!open || !props.job) return;
    form.client_id = props.job.client_id;
    form.title = props.job.title || '';
    form.request_description = props.job.request_description || '';
    form.products = (props.job.items || []).map((item: any) => ({
        product_category_id: item.product_category_id,
        product_id: item.product_id,
        quantity: Number(item.quantity) || 1,
    }));
    if (!form.products.length) form.products = [{ product_category_id: props.job.product_category_id, product_id: props.job.product_id, quantity: Number(props.job.quantity) || 1 }];
    form.currency_id = props.job.currency_id;
    form.priority_id = props.job.priority_id;
    form.owner_id = props.job.owner_id;
    form.coordinator_id = props.job.coordinator_id;
    form.quoted_amount = props.job.can_view_financials ? Number(props.job.quoted_amount || 0) : null;
    form.delivery_date = props.job.delivery_date || '';
    form.clearErrors();
});

function onCategoryChanged(line: ProductLine) {
    if (!products.value.some((item: any) => Number(item.id) === Number(line.product_id) && Number(item.parent_id) === Number(line.product_category_id))) line.product_id = null;
}
function addProduct() { form.products.push({ product_category_id: null, product_id: null, quantity: 1 }); }
function removeProduct(index: number) { if (form.products.length > 1) form.products.splice(index, 1); }
function productError(index: number, field: keyof ProductLine) { return (form.errors as Record<string, string>)[`products.${index}.${field}`]; }
function submit() {
    if (!props.job) return;
    form.put(`/flowtrack/jobs/${props.job.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <FlowModal :open="open" title="Edit Job" subtitle="Update Job details without changing its active Workflow or current Phase." @close="emit('close')">
        <form class="ft-form-grid ft-job-form-stack" @submit.prevent="submit">
            <div v-if="form.errors.job" class="ft-error full" style="padding:10px;border-radius:8px;background:#fff0ef">{{ form.errors.job }}</div>
            <div class="ft-field full"><label>Job code</label><input :value="job?.code" readonly /></div>
            <div class="ft-field full"><label>Client</label><SearchableSelect v-model="form.client_id" :options="clientOptions" placeholder="Select client" search-placeholder="Search clients…" /></div>
            <div class="ft-field full"><label>Job title</label><input v-model="form.title" required /><span v-if="form.errors.title" class="ft-error">{{ form.errors.title }}</span></div>
            <div class="ft-field full"><label>Request description</label><textarea v-model="form.request_description" rows="3" placeholder="Specifications, target price, printing or customization requirements…" /></div>

            <div class="ft-field full ft-product-lines-field">
                <div class="ft-product-lines-heading"><div><label>Products & quantities</label><div class="ft-help">Category, Product and Quantity stay linked on each row.</div></div></div>
                <div class="ft-product-line-labels"><span>Product category</span><span>Product</span><span>Quantity</span><span></span></div>
                <div class="ft-product-lines">
                    <div v-for="(line, index) in form.products" :key="index" class="ft-product-line">
                        <div><SearchableSelect v-model="line.product_category_id" :options="categoryOptions" placeholder="Select category" search-placeholder="Search categories…" @update:model-value="onCategoryChanged(line)" /><span v-if="productError(index, 'product_category_id')" class="ft-error">{{ productError(index, 'product_category_id') }}</span></div>
                        <div><SearchableSelect v-model="line.product_id" :options="productOptions(line.product_category_id)" placeholder="Select product" search-placeholder="Search products…" :disabled="!line.product_category_id" /><span v-if="productError(index, 'product_id')" class="ft-error">{{ productError(index, 'product_id') }}</span></div>
                        <div><input v-model.number="line.quantity" type="number" min="1" max="1000000000" /><span v-if="productError(index, 'quantity')" class="ft-error">{{ productError(index, 'quantity') }}</span></div>
                        <button type="button" class="ft-remove-product" :disabled="form.products.length === 1" @click="removeProduct(index)">×</button>
                    </div>
                </div>
                <button type="button" class="ft-secondary ft-add-product" @click="addProduct">＋ Add another product</button>
            </div>

            <div class="ft-field full"><label>Delivery date</label><input v-model="form.delivery_date" type="date" required /></div>
            <div class="ft-field full"><label>Priority</label><div class="ft-job-priority-options"><label v-for="priority in priorities" :key="priority.id" class="ft-job-priority-option"><input v-model="form.priority_id" type="radio" :value="priority.id" /><span class="ft-job-priority-check">✓</span><span>{{ priority.name }}</span></label></div></div>
            <div class="ft-field full"><label>Job assignee</label><SearchableSelect v-model="form.owner_id" :options="userOptions" placeholder="Select assignee" search-placeholder="Search users…" /></div>
            <div class="ft-field full"><label>Coordinator</label><SearchableSelect v-model="form.coordinator_id" :options="userOptions" placeholder="Select coordinator" search-placeholder="Search users…" /></div>
            <div v-if="job?.can_view_financials" class="ft-field full"><label>Quoted amount</label><input v-model.number="form.quoted_amount" type="number" min="0" step="0.01" /></div>
            <div v-if="job?.can_view_financials" class="ft-field full"><label>Currency</label><SearchableSelect v-model="form.currency_id" :options="currencyOptions" placeholder="Select currency" search-placeholder="Search currencies…" /></div>
            <div class="ft-field full"><label>Workflow</label><input :value="job?.workflow?.name" readonly /><div class="ft-help">Use the Workflow tab to move the Job between configured Phases.</div></div>
        </form>
        <template #footer><button class="ft-ghost" type="button" @click="emit('close')">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="submit">{{ form.processing ? 'Saving…' : 'Save Job Changes' }}</button></template>
    </FlowModal>
</template>
