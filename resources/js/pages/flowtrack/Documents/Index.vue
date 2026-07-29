<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowDrawer from '@/components/flowtrack/FlowDrawer.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import FlowPagination from '@/components/flowtrack/FlowPagination.vue';
import { formatDate, initials, records } from '@/lib/flowtrack';

const props = defineProps<{
    documents: any;
    jobs: any[];
    options: any;
    filters: any;
    documentType: string;
    selectedJobId?: number | null;
    openUpload?: boolean;
    canLinkDocuments?: boolean;
    canUploadDocuments?: boolean;
}>();
const uploadOpen = ref(false);
const selected = ref<any>(null);
const selectedRequirementKey = ref('');
const filter = reactive({ search: props.filters.search || '', category: props.filters.category || '', client: props.filters.client || '' });
const form = useForm({
    job_id: props.selectedJobId || props.jobs[0]?.id || null,
    document_id: null as number | null,
    workflow_phase_id: null as number | null,
    task_pack_item_id: null as number | null,
    document_category_id: null as number | null,
    file: null as File | null,
    is_final: false,
    notes: '',
    advance_phase: true,
});

const selectedJob = computed(() => props.jobs.find((job) => Number(job.id) === Number(form.job_id)) || null);
const requirements = computed(() => (selectedJob.value?.document_requirements || []).map((requirement: any) => ({
    key: requirement.requirement_key,
    phaseId: Number(requirement.workflow_phase_id),
    phaseName: requirement.workflow_phase_name,
    taskPackItemId: requirement.task_pack_item_id ? Number(requirement.task_pack_item_id) : null,
    categoryId: Number(requirement.category_id),
    categoryName: requirement.category_name,
    source: requirement.source_label || requirement.sources?.[0] || 'Workflow requirement',
    requiredForReadiness: requirement.required_for_readiness !== false,
})));
const jobsWithRequirements = computed(() => props.jobs.filter((job) => (job.document_requirements || []).length));
const selectedRequirement = computed(() => requirements.value.find((item: any) => item.key === selectedRequirementKey.value) || null);

function applyRequirement(requirement: any | null) {
    selectedRequirementKey.value = requirement?.key || '';
    form.workflow_phase_id = requirement?.phaseId || null;
    form.task_pack_item_id = requirement?.taskPackItemId || null;
    form.document_category_id = requirement?.categoryId || null;
}

watch(() => form.job_id, () => applyRequirement(requirements.value[0] || null));
watch(selectedRequirementKey, () => applyRequirement(selectedRequirement.value));

function openUpload(document: any | null = null, preferredJobId: number | null = null) {
    form.reset();
    form.clearErrors();
    const jobId = preferredJobId || document?.flow_job_id || props.selectedJobId || jobsWithRequirements.value[0]?.id || null;
    form.job_id = jobId;
    form.document_id = document?.id || null;
    form.is_final = Boolean(document?.is_final);
    form.advance_phase = Boolean(props.canLinkDocuments);
    const job = props.jobs.find((item) => Number(item.id) === Number(jobId));
    const allowed = (job?.document_requirements || []).map((requirement: any) => ({
        key: requirement.requirement_key,
        phaseId: Number(requirement.workflow_phase_id),
        phaseName: requirement.workflow_phase_name,
        taskPackItemId: requirement.task_pack_item_id ? Number(requirement.task_pack_item_id) : null,
        categoryId: Number(requirement.category_id),
        categoryName: requirement.category_name,
        source: requirement.source_label || requirement.sources?.[0] || 'Workflow requirement',
    }));
    const match = allowed.find((item: any) =>
        Number(item.phaseId) === Number(document?.workflow_phase_id)
        && Number(item.categoryId) === Number(document?.document_category_id)
        && Number(item.taskPackItemId || 0) === Number(document?.task_pack_item_id || 0),
    ) || allowed[0] || null;
    applyRequirement(match);
    uploadOpen.value = true;
}
function canUploadVersion(document: any): boolean {
    const job = props.jobs.find((item) => Number(item.id) === Number(document?.flow_job_id));
    return (job?.document_requirements || []).some((requirement: any) =>
        Number(requirement.workflow_phase_id) === Number(document?.workflow_phase_id)
        && Number(requirement.category_id) === Number(document?.document_category_id)
        && Number(requirement.task_pack_item_id || 0) === Number(document?.task_pack_item_id || 0),
    );
}
function upload() {
    form.post('/flowtrack/documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadOpen.value = false;
            router.get('/flowtrack/documents', {}, { replace: true, preserveScroll: true });
        },
    });
}
function apply() { router.get('/flowtrack/documents', filter, { preserveState: true, replace: true }); }
function chooseFile(event: Event) { form.file = (event.target as HTMLInputElement).files?.[0] || null; }

onMounted(async () => {
    if (props.openUpload && props.selectedJobId) {
        await nextTick();
        openUpload(null, Number(props.selectedJobId));
    }
});
</script>

<template>
    <Head title="Document Archive" />
    <FlowPageHeader title="Document Archive" subtitle="Upload and link required files to the exact Job, client, Workflow Phase and Task Pack requirement">
        <button v-if="canUploadDocuments" class="ft-primary" :disabled="!jobsWithRequirements.length" @click="openUpload()">＋ Upload & Link Required Document</button>
    </FlowPageHeader>

    <label v-if="canUploadDocuments" class="ft-upload-zone" @click="jobsWithRequirements.length && openUpload()"><b>Upload from the Documents page</b><div class="ft-muted ft-small" style="margin-top: 5px">Only document requirements configured for the selected Job’s current Workflow Phase are available.</div></label>
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
            <div class="ft-muted" style="font-size: 10px; line-height: 1.6">{{ document.category?.name || 'Uncategorised' }} <FlowBadge :value="document.is_final ? 'Final' : 'Working'" /><br />{{ document.job?.code }} · {{ document.job?.client?.name }}<br />{{ document.phase?.name || 'Unlinked phase' }}<span v-if="document.task_pack_item"> · {{ document.task_pack_item.title }}</span><br />{{ document.versions?.length || 0 }} version(s) · {{ document.uploader?.name }}</div>
        </article>
        <div v-if="!documents.data.length" class="ft-card ft-empty">No documents match the selected filters.</div>
    </section>
    <FlowPagination :meta="documents" />

    <FlowModal :open="uploadOpen" :title="form.document_id ? 'Upload New Version' : 'Upload & Link Required Document'" subtitle="This operation is performed only from Documents and links the file to the selected Job’s active workflow requirement." @close="uploadOpen = false">
        <div class="ft-form-grid">
            <div class="ft-field full"><label>Job and client</label><select v-model="form.job_id"><option v-for="job in jobsWithRequirements" :key="job.id" :value="job.id">{{ job.code }} · {{ job.title }} · {{ job.client?.name }}</option></select><span v-if="!jobsWithRequirements.length" class="ft-error">No visible Job currently requires a document.</span></div>
            <div class="ft-field full"><label>Current Workflow Phase</label><input :value="selectedJob?.current_phase_name || 'No active phase'" readonly /></div>
            <div class="ft-field full"><label>Exact required document</label><input v-if="requirements.length === 1" :value="`${requirements[0].categoryName} · ${requirements[0].source}`" readonly /><select v-else v-model="selectedRequirementKey"><option v-for="requirement in requirements" :key="requirement.key" :value="requirement.key">{{ requirement.categoryName }} · {{ requirement.source }}</option></select><div class="ft-help">The category, Workflow Phase and Task Pack item are locked to this requirement.</div><span v-if="form.errors.document_requirement || form.errors.document_category_id" class="ft-error">{{ form.errors.document_requirement || form.errors.document_category_id }}</span></div>
            <div class="ft-field full"><label>File</label><input type="file" required @change="chooseFile" /><span v-if="form.errors.file" class="ft-error">{{ form.errors.file }}</span></div>
            <div class="ft-field full"><label>Version notes</label><textarea v-model="form.notes" rows="3"></textarea></div>
            <label class="ft-check-row" style="border: 0"><input v-model="form.is_final" type="checkbox" /> Mark as final</label>
            <label v-if="canLinkDocuments" class="ft-check-row full"><input v-model="form.advance_phase" type="checkbox" /> When all current-phase Tasks and documents are complete, continue the Job to the next Phase and activate that Phase’s Task Pack.</label>
        </div>
        <template #footer><button class="ft-ghost" @click="uploadOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing || !form.file || !form.document_category_id || !form.workflow_phase_id" @click="upload">{{ form.processing ? 'Uploading…' : (form.advance_phase ? 'Upload, Link & Continue' : 'Upload & Link') }}</button></template>
    </FlowModal>

    <FlowDrawer :open="Boolean(selected)" @close="selected = null">
        <template v-if="selected"><div class="ft-drawer-head"><div><div class="ft-muted ft-small">{{ selected.code }} · {{ selected.category?.name }}</div><h2 style="font-size: 18px; margin: 4px 0">{{ selected.name }}</h2><FlowBadge :value="selected.is_final ? 'Final Document' : 'Working Document'" /></div><button class="ft-close-btn" @click="selected = null">×</button></div><div class="ft-drawer-body"><div class="ft-card" style="height: 260px; display: grid; place-items: center; background: #f5f7fa"><div style="text-align: center"><div class="ft-doc-icon" style="margin: auto auto 10px">FILE</div><b>Secure document storage</b><div class="ft-muted ft-small">Linked to {{ selected.phase?.name || 'a Workflow Phase' }}.</div></div></div><div class="ft-summary-grid" style="margin-top: 16px"><div class="ft-summary-box"><span>Related Job</span><b>{{ selected.job?.code }}</b></div><div class="ft-summary-box"><span>Client</span><b>{{ selected.job?.client?.name }}</b></div><div class="ft-summary-box"><span>Workflow Phase</span><b>{{ selected.phase?.name || '—' }}</b></div><div class="ft-summary-box"><span>Task Requirement</span><b>{{ selected.task_pack_item?.title || 'Phase requirement' }}</b></div><div class="ft-summary-box"><span>Uploaded</span><b>{{ formatDate(selected.created_at) }}</b></div></div><div class="ft-section-head" style="margin-top: 20px"><h3>Version History</h3><button v-if="canUploadVersion(selected)" class="ft-primary" @click="openUpload(selected); selected = null">＋ New Version</button></div><div v-for="version in selected.versions" :key="version.id" class="ft-attention-item" style="cursor: default"><span class="ft-avatar">{{ version.uploader?.initials || initials(version.uploader?.name) }}</span><div><div class="ft-item-title">{{ version.original_name }}</div><div class="ft-item-meta">Version {{ version.version }} · {{ version.mime_type }} · {{ Math.round(version.size / 1024) }} KB</div></div><div class="ft-actions"><FlowBadge :value="version.version === selected.versions[0]?.version ? 'Current' : 'Archived'" /><a class="ft-mini-btn" :href="`/flowtrack/documents/versions/${version.id}/download`" @click.stop>Download</a></div></div></div></template>
    </FlowDrawer>
</template>
