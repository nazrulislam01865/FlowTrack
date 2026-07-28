<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';

const props = defineProps<{ records: any[]; type: string; types: any[]; parents: any[]; nextCode: string }>();
const search = ref('');
const modalOpen = ref(false);
const editing = ref<any>(null);
const form = useForm({ type: props.type, parent_id: null as number | null, name: '', description: '', metadata: {} as Record<string, unknown>, status: 'active', sort_order: 0 });
const filtered = computed(() => props.records.filter((record) => `${record.code} ${record.name} ${record.description || ''}`.toLowerCase().includes(search.value.toLowerCase())));
const currentLabel = computed(() => props.types.find((item) => item.value === props.type)?.label || 'Master Data');

function changeType(type: string) { router.get('/flowtrack/master-data', { type }); }
function openRecord(record: any | null = null) {
    editing.value = record;
    form.defaults({ type: props.type, parent_id: record?.parent_id || null, name: record?.name || '', description: record?.description || '', metadata: record?.metadata || {}, status: record?.status || 'active', sort_order: Number(record?.sort_order || 0) });
    form.reset(); form.clearErrors(); modalOpen.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (modalOpen.value = false) };
    editing.value ? form.put(`/flowtrack/master-data/${editing.value.id}`, options) : form.post('/flowtrack/master-data', options);
}
function printPage() { window.print(); }
</script>

<template>
    <Head title="Master Data" />
    <FlowPageHeader title="Master Data" subtitle="Shared lists used by jobs, tasks, workflow configuration, documents and reporting"><button class="ft-primary" @click="openRecord()">＋ Add Record</button></FlowPageHeader>
    <div class="ft-config-summary"><div class="ft-card ft-config-stat"><span>Current Category</span><b style="font-size: 15px">{{ currentLabel }}</b></div><div class="ft-card ft-config-stat"><span>Total Records</span><b>{{ records.length }}</b></div><div class="ft-card ft-config-stat"><span>Active</span><b>{{ records.filter((record) => record.status === 'active').length }}</b></div><div class="ft-card ft-config-stat"><span>Inactive</span><b>{{ records.filter((record) => record.status !== 'active').length }}</b></div></div>
    <section class="ft-master-layout">
        <aside class="ft-card ft-side-list"><button v-for="item in types" :key="item.value" :class="{ active: item.value === type }" @click="changeType(item.value)"><b>{{ item.label }}</b><small>Configure reusable records</small></button></aside>
        <article class="ft-card">
            <div class="ft-toolbar"><input v-model="search" class="grow" :placeholder="`Search ${currentLabel.toLowerCase()}…`" /><button class="ft-ghost" @click="printPage">Export</button></div>
            <div class="ft-table-wrap"><table class="ft-table" style="min-width: 760px"><thead><tr><th>Code</th><th>Name</th><th>Parent / Category</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="record in filtered" :key="record.id"><td><b>{{ record.code }}</b></td><td>{{ record.name }}</td><td>{{ record.parent?.name || '—' }}</td><td>{{ record.description || '—' }}</td><td><FlowBadge :value="record.status" /></td><td><div class="ft-actions"><button class="ft-mini-btn" @click="openRecord(record)">Edit</button><button class="ft-mini-btn" @click="router.patch(`/flowtrack/master-data/${record.id}/toggle`, {}, { preserveScroll: true })">{{ record.status === 'active' ? 'Deactivate' : 'Activate' }}</button></div></td></tr><tr v-if="!filtered.length"><td colspan="6"><div class="ft-empty">No records found.</div></td></tr></tbody></table></div>
        </article>
    </section>

    <FlowModal :open="modalOpen" :title="editing ? `Edit ${currentLabel}` : `Add ${currentLabel}`" subtitle="Codes are generated automatically for each section and cannot be changed." @close="modalOpen = false">
        <div class="ft-form-grid"><div class="ft-field"><label>Code</label><input :value="editing?.code || nextCode" readonly aria-readonly="true" /><span class="ft-muted ft-small">Automatically generated and permanently locked.</span></div><div class="ft-field"><label>Name</label><input v-model="form.name" required /></div><div v-if="type === 'product'" class="ft-field full"><label>Product category</label><select v-model="form.parent_id"><option :value="null">No category</option><option v-for="parent in parents" :key="parent.id" :value="parent.id">{{ parent.code }} · {{ parent.name }}</option></select></div><div class="ft-field full"><label>Description</label><textarea v-model="form.description" rows="3"></textarea></div><div class="ft-field"><label>Status</label><select v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="ft-field"><label>Display order</label><input v-model.number="form.sort_order" type="number" min="0" /></div></div>
        <template #footer><button class="ft-ghost" @click="modalOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="save">{{ form.processing ? 'Saving…' : 'Save Record' }}</button></template>
    </FlowModal>
</template>
