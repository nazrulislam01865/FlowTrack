<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import { initials, money } from '@/lib/flowtrack';

const props = defineProps<{ clients: any[]; users: any[]; countries: string[]; filters: any; canManage: boolean; canViewFinancials: boolean }>();
const modalOpen = ref(false);
const editing = ref<any>(null);
const filter = reactive({ search: props.filters.search || '', country: props.filters.country || '' });
const form = useForm({
    name: '', code: '', country: '', contact_name: '', email: '', phone: '', account_manager_id: null as number | null,
    preferred_language: 'en', outstanding_balance: 0, notes: '',
});

function openClient(client: any | null = null) {
    editing.value = client;
    form.defaults({
        name: client?.name || '', code: client?.code || '', country: client?.country || '', contact_name: client?.contact_name || '',
        email: client?.email || '', phone: client?.phone || '', account_manager_id: client?.account_manager_id || props.users[0]?.id || null,
        preferred_language: client?.preferred_language || 'en', outstanding_balance: Number(client?.outstanding_balance || 0), notes: client?.notes || '',
    });
    form.reset(); form.clearErrors(); modalOpen.value = true;
}
function save() {
    const options = { preserveScroll: true, onSuccess: () => (modalOpen.value = false) };
    if (editing.value) form.put(`/flowtrack/clients/${editing.value.id}`, options);
    else form.post('/flowtrack/clients', options);
}
function apply() { router.get('/flowtrack/clients', filter, { preserveState: true, replace: true }); }
function attentionCount(client: any) { return Number(client.attention_jobs_count || 0); }
</script>

<template>
    <Head title="Clients" />
    <FlowPageHeader title="Clients" subtitle="Contacts, active jobs, documents and outstanding balances">
        <button v-if="canManage" class="ft-primary" @click="openClient()">＋ New Client</button>
    </FlowPageHeader>

    <form class="ft-card ft-toolbar" @submit.prevent="apply">
        <input v-model="filter.search" class="grow" placeholder="Search client, country or manager…" />
        <select v-model="filter.country"><option value="">All countries</option><option v-for="country in countries" :key="country">{{ country }}</option></select>
        <button class="ft-secondary">Apply</button>
    </form>

    <section class="ft-client-grid">
        <article v-for="client in clients" :key="client.id" class="ft-card ft-client-card" :style="{ cursor: canManage ? 'pointer' : 'default' }" @click="canManage && openClient(client)">
            <div class="ft-client-head" style="justify-content: flex-start"><div class="ft-company-logo">{{ client.code }}</div><div><h3 style="font-size: 14px; margin: 0 0 4px">{{ client.name }}</h3><div class="ft-small ft-muted">{{ client.country || 'No country' }} · {{ client.contact_name || 'No contact' }}</div></div></div>
            <div class="ft-client-stats">
                <div class="ft-client-stat"><b>{{ client.active_jobs_count }}</b><span>Active Jobs</span></div>
                <div class="ft-client-stat"><b>{{ attentionCount(client) }}</b><span>Attention</span></div>
                <div class="ft-client-stat"><b>{{ canViewFinancials ? money(client.outstanding_balance).replace('$', '') : '—' }}</b><span>Outstanding</span></div>
            </div>
            <div class="ft-small ft-muted" style="margin-top: 12px">Account manager: {{ client.account_manager?.name || 'Unassigned' }}</div>
        </article>
        <div v-if="!clients.length" class="ft-card ft-empty">No clients match the selected filters.</div>
    </section>

    <FlowModal :open="modalOpen" :title="editing ? 'Edit Client' : 'Create Client'" subtitle="Client details are shared across jobs, documents and reporting." @close="modalOpen = false">
        <form class="ft-form-grid" @submit.prevent="save">
            <div class="ft-field"><label>Client name</label><input v-model="form.name" required /><span v-if="form.errors.name" class="ft-error">{{ form.errors.name }}</span></div>
            <div class="ft-field"><label>Code</label><input v-model="form.code" placeholder="Auto-generated when blank" /></div>
            <div class="ft-field"><label>Country</label><input v-model="form.country" /></div>
            <div class="ft-field"><label>Primary contact</label><input v-model="form.contact_name" /></div>
            <div class="ft-field"><label>Email</label><input v-model="form.email" type="email" /></div>
            <div class="ft-field"><label>Phone</label><input v-model="form.phone" /></div>
            <div class="ft-field"><label>Account manager</label><select v-model="form.account_manager_id"><option :value="null">Unassigned</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option></select></div>
            <div class="ft-field"><label>Outstanding balance</label><input v-model.number="form.outstanding_balance" type="number" min="0" step="0.01" /></div>
            <div class="ft-field full"><label>Notes</label><textarea v-model="form.notes" rows="3"></textarea></div>
        </form>
        <template #footer><button class="ft-ghost" @click="modalOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="save">{{ form.processing ? 'Saving…' : 'Save Client' }}</button></template>
    </FlowModal>
</template>
