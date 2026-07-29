<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import SearchableSelect from '@/components/flowtrack/SearchableSelect.vue';
import { money } from '@/lib/flowtrack';

const props = defineProps<{ clients: any[]; users: any[]; countries: string[]; filters: any; canManage: boolean; canViewFinancials: boolean }>();
const modalOpen = ref(false);
const editing = ref<any>(null);
const filter = reactive({ search: props.filters.search || '', country: props.filters.country || '' });
const form = useForm({
    name: '', country: '', contact_name: '', email: '', phone: '', account_manager_id: null as number | null,
    preferred_language: 'en', outstanding_balance: 0, notes: '',
});

const managerOptions = computed(() => [
    { value: null, label: 'Unassigned' },
    ...props.users.map((user) => ({ value: user.id, label: user.name })),
]);
const languageOptions = [
    { value: 'en', label: 'English' },
    { value: 'zh', label: 'Simplified Chinese' },
];
const displayedClientCode = computed(() => {
    if (editing.value?.code) return editing.value.code;
    const max = props.clients.reduce((highest, client) => {
        const match = String(client.code || '').match(/^CL-(\d+)$/i);
        return match ? Math.max(highest, Number(match[1])) : highest;
    }, 0);
    return `CL-${String(max + 1).padStart(3, '0')}`;
});

function openClient(client: any | null = null) {
    editing.value = client;
    form.defaults({
        name: client?.name || '', country: client?.country || '', contact_name: client?.contact_name || '',
        email: client?.email || '', phone: client?.phone || '', account_manager_id: client?.account_manager_id || null,
        preferred_language: client?.preferred_language || 'en', outstanding_balance: Number(client?.outstanding_balance || 0), notes: client?.notes || '',
    });
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
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

    <FlowModal :open="modalOpen" :title="editing ? 'Edit Client' : 'Create New Client'" subtitle="Client details are shared across jobs, documents and reporting." @close="modalOpen = false">
        <form class="ft-form-grid ft-client-form-stack" @submit.prevent="save">
            <div class="ft-field full ft-client-code">
                <label>Client code</label>
                <input :value="displayedClientCode" readonly aria-readonly="true" tabindex="-1" />
                <span class="ft-code-lock-note">Automatically generated by FlowTrack and permanently locked.</span>
            </div>
            <div class="ft-field full"><label>Client name</label><input v-model="form.name" required placeholder="Company or organisation name" /><span v-if="form.errors.name" class="ft-error">{{ form.errors.name }}</span></div>
            <div class="ft-field full"><label>Country</label><input v-model="form.country" placeholder="e.g. United States" /></div>
            <div class="ft-field full"><label>Primary contact</label><input v-model="form.contact_name" placeholder="Contact person" /></div>
            <div class="ft-field full"><label>Email</label><input v-model="form.email" type="email" placeholder="contact@company.com" /></div>
            <div class="ft-field full"><label>Phone</label><input v-model="form.phone" placeholder="Phone number" /></div>
            <div class="ft-field full"><label>Account manager</label><SearchableSelect v-model="form.account_manager_id" :options="managerOptions" search-placeholder="Search account managers…" /></div>
            <div class="ft-field full"><label>Preferred language</label><SearchableSelect v-model="form.preferred_language" :options="languageOptions" search-placeholder="Search languages…" /></div>
            <div class="ft-field full"><label>Outstanding balance</label><input v-model.number="form.outstanding_balance" type="number" min="0" step="0.01" /></div>
            <div class="ft-field full"><label>Notes</label><textarea v-model="form.notes" rows="3" placeholder="Commercial notes, preferences or account instructions…"></textarea></div>
        </form>
        <template #footer><button class="ft-ghost" @click="modalOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="save">{{ form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Create Client' }}</button></template>
    </FlowModal>
</template>
