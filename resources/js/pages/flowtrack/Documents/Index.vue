<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowDrawer from '@/components/flowtrack/FlowDrawer.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowPagination from '@/components/flowtrack/FlowPagination.vue';
import { formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{ documents: any; jobs: any[]; options: any; filters: any; documentType: string }>();
const uploadOpen = ref(false);
const selected = ref<any>(null);
const filter = reactive({ search: props.filters.search || '', category: props.filters.category || '', client: props.filters.client || '' });
const form = useForm({
    job_id: props.jobs[0]?.id || null,
    document_id: null as number | null,
    document_category_id: null as number | null,
    file: null as File | null,
    is_final: false,
    notes: '',
});

function openUpload(document: any | null = null) {
    form.reset(); form.clearErrors();
    form.job_id = document?.flow_job_id || props.jobs[0]?.id || null;
    form.document_id = document?.id || null;
    form.document_category_id = document?.document_category_id || records(props.options, props.documentType)[0]?.id || null;
    uploadOpen.value = true;
}
function upload() {
    form.post('/flowtrack/documents', { forceFormData: true, preserveScroll: true, onSuccess: () => (uploadOpen.value = false) });
}
function apply() { router.get('/flowtrack/documents', filter, { preserveState: true, replace: true }); }
function chooseFile(event: Event) { form.file = (event.target as HTMLInputElement).files?.[0] || null; }
</script>

<template>
    <Head title="Document Archive" />
    <FlowPageHeader title="Document Archive" subtitle="Files remain connected to their client, job, phase, category and version">
        <button class="ft-primary" @click="openUpload()">＋ Upload Document</button>
    </FlowPageHeader>

    <label class="ft-upload-zone" @click="openUpload()"><b>Drop documents here or click to upload</b><div class="ft-muted ft-small" style="margin-top: 5px">Choose a job and document category. New uploads can also become versions of an existing document.</div></label>
    <form class="ft-card ft-toolbar" @submit.prevent="apply">
        <input v-model="filter.search" class="grow" placeholder="Search file name, job or client…" />
        <select v-model="filter.category"><option value="">All categories</option><option v-for="category in records(options, documentType)" :key="category.id" :value="category.id">{{ category.name }}</option></select>
        <select v-model="filter.client"><option value="">All clients</option><option v-for="client in options.clients" :key="client.id" :value="client.id">{{ client.name }}</option></select>
        <button class="ft-secondary">Apply</button>
    </form>

    <section class="ft-doc-grid">
        <article v-for="document in documents.data" :key="document.id" class="ft-card ft-doc-card" @click="selected = document">
            <div class="ft-doc-icon">{{ document.versions?.[0]?.mime_type === 'application/pdf' ? 'PDF' : 'FILE' }}</div>
            <h4 class="ft-truncate" style="font-size: 12px; margin: 0 0 6px">{{ document.name }}</h4>
            <div class="ft-muted" style="font-size: 10px; line-height: 1.6">{{ document.category?.name || 'Uncategorised' }} <FlowBadge :value="document.is_final ? 'Final' : 'Working'" /><br />{{ document.job?.code }} · {{ document.job?.client?.name }}<br />{{ document.versions?.length || 0 }} version(s) · {{ document.uploader?.name }}</div>
        </article>
        <div v-if="!documents.data.length" class="ft-card ft-empty">No documents match the selected filters.</div>
    </section>
    <FlowPagination :meta="documents" />

    <FlowModal :open="uploadOpen" :title="form.document_id ? 'Upload New Version' : 'Upload Document'" subtitle="Files are stored by job and version; document metadata remains in the database." @close="uploadOpen = false">
        <div class="ft-form-grid">
            <div class="ft-field full"><label>Job</label><select v-model="form.job_id"><option v-for="job in jobs" :key="job.id" :value="job.id">{{ job.code }} · {{ job.title }}</option></select></div>
            <div class="ft-field"><label>Category</label><select v-model="form.document_category_id"><option :value="null">Uncategorised</option><option v-for="category in records(options, documentType)" :key="category.id" :value="category.id">{{ category.name }}</option></select></div>
            <label class="ft-check-row" style="border: 0"><input v-model="form.is_final" type="checkbox" /> Mark as final</label>
            <div class="ft-field full"><label>File</label><input type="file" required @change="chooseFile" /><span v-if="form.errors.file" class="ft-error">{{ form.errors.file }}</span></div>
            <div class="ft-field full"><label>Version notes</label><textarea v-model="form.notes" rows="3"></textarea></div>
        </div>
        <template #footer><button class="ft-ghost" @click="uploadOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing || !form.file" @click="upload">{{ form.processing ? 'Uploading…' : 'Upload' }}</button></template>
    </FlowModal>

    <FlowDrawer :open="Boolean(selected)" @close="selected = null">
        <template v-if="selected"><div class="ft-drawer-head"><div><div class="ft-muted ft-small">{{ selected.code }} · {{ selected.category?.name }}</div><h2 style="font-size: 18px; margin: 4px 0">{{ selected.name }}</h2><FlowBadge :value="selected.is_final ? 'Final Document' : 'Working Document'" /></div><button class="ft-close-btn" @click="selected = null">×</button></div><div class="ft-drawer-body"><div class="ft-card" style="height: 260px; display: grid; place-items: center; background: #f5f7fa"><div style="text-align: center"><div class="ft-doc-icon" style="margin: auto auto 10px">FILE</div><b>Secure document storage</b><div class="ft-muted ft-small">Preview integration can be added for supported file types.</div></div></div><div class="ft-summary-grid" style="margin-top: 16px"><div class="ft-summary-box"><span>Related Job</span><b>{{ selected.job?.code }}</b></div><div class="ft-summary-box"><span>Client</span><b>{{ selected.job?.client?.name }}</b></div><div class="ft-summary-box"><span>Uploaded</span><b>{{ formatDate(selected.created_at) }}</b></div></div><div class="ft-section-head" style="margin-top: 20px"><h3>Version History</h3><button class="ft-primary" @click="openUpload(selected); selected = null">＋ New Version</button></div><div v-for="version in selected.versions" :key="version.id" class="ft-attention-item" style="cursor: default"><span class="ft-avatar">{{ version.uploader?.initials || initials(version.uploader?.name) }}</span><div><div class="ft-item-title">{{ version.original_name }}</div><div class="ft-item-meta">Version {{ version.version }} · {{ version.mime_type }} · {{ Math.round(version.size / 1024) }} KB</div></div><div class="ft-actions"><FlowBadge :value="version.version === selected.versions[0]?.version ? 'Current' : 'Archived'" /><a class="ft-mini-btn" :href="`/flowtrack/documents/versions/${version.id}/download`" @click.stop>Download</a></div></div></div></template>
    </FlowDrawer>
</template>
