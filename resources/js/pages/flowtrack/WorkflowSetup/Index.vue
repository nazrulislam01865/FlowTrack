<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import { records } from '@/lib/flowtrack';

const props = defineProps<{ workflows: any[]; taskPacks: any[]; options: any }>();
const selectedId = ref(props.workflows[0]?.id || null);
const createOpen = ref(false);
const phaseOpen = ref(false);
const editingPhase = ref<any>(null);
const selected = computed(() => props.workflows.find((workflow) => workflow.id === selectedId.value) || props.workflows[0]);

const createForm = useForm({ name: '', code: '', description: '', base_workflow_id: null as number | null });
const phaseForm = useForm({
    name: '', short_name: '', task_pack_id: null as number | null, document_category_id: null as number | null,
    allow_job_start: false, is_skippable: false, requires_approval: false, is_active: true, entry_condition: '', exit_condition: '',
});

function createWorkflow() {
    createForm.post('/flowtrack/workflow-setup', { preserveScroll: true, onSuccess: () => { createOpen.value = false; createForm.reset(); } });
}
function addPhase() {
    editingPhase.value = null;
    phaseForm.defaults({ name: '', short_name: '', task_pack_id: null, document_category_id: null, allow_job_start: false, is_skippable: false, requires_approval: false, is_active: true, entry_condition: '', exit_condition: '' });
    phaseForm.reset(); phaseForm.clearErrors(); phaseOpen.value = true;
}
function editPhase(phase: any) {
    editingPhase.value = phase;
    phaseForm.defaults({
        name: phase.name, short_name: phase.short_name, task_pack_id: phase.task_pack_id, document_category_id: phase.document_category_id,
        allow_job_start: phase.allow_job_start, is_skippable: phase.is_skippable, requires_approval: phase.requires_approval,
        is_active: phase.is_active, entry_condition: phase.entry_condition || '', exit_condition: phase.exit_condition || '',
    });
    phaseForm.reset(); phaseForm.clearErrors(); phaseOpen.value = true;
}
function savePhase() {
    const options = { preserveScroll: true, onSuccess: () => (phaseOpen.value = false) };
    if (editingPhase.value) phaseForm.put(`/flowtrack/workflow-phases/${editingPhase.value.id}`, options);
    else phaseForm.post(`/flowtrack/workflow-setup/${selected.value.id}/phases`, options);
}
function movePhase(index: number, direction: number) {
    const phases = [...selected.value.phases];
    const target = index + direction;
    if (target < 0 || target >= phases.length) return;
    [phases[index], phases[target]] = [phases[target], phases[index]];
    router.patch(`/flowtrack/workflow-setup/${selected.value.id}/reorder`, { phase_ids: phases.map((phase) => phase.id) }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Workflow Setup" />
    <FlowPageHeader title="Workflow Setup" subtitle="Configure phase order, start rules, skip controls, approvals, documents and automatic task packs">
        <button class="ft-primary" @click="createOpen = true">＋ New Workflow</button>
    </FlowPageHeader>

    <div class="ft-config-summary"><div class="ft-card ft-config-stat"><span>Workflow Templates</span><b>{{ workflows.length }}</b></div><div class="ft-card ft-config-stat"><span>Active Templates</span><b>{{ workflows.filter((item) => item.is_active).length }}</b></div><div class="ft-card ft-config-stat"><span>Task Packs</span><b>{{ taskPacks.length }}</b></div><div class="ft-card ft-config-stat"><span>Configured Phases</span><b>{{ workflows.reduce((sum, item) => sum + item.phases.length, 0) }}</b></div></div>

    <section class="ft-workflow-layout">
        <aside class="ft-card ft-side-list"><button v-for="workflow in workflows" :key="workflow.id" :class="{ active: workflow.id === selectedId }" @click="selectedId = workflow.id"><b>{{ workflow.name }}</b><small>{{ workflow.code }} · {{ workflow.phases.length }} phases · v{{ workflow.version }}</small></button></aside>
        <article v-if="selected" class="ft-card">
            <div class="ft-section-card" style="background: linear-gradient(110deg,#fbfdfc,#eff5ff); border-bottom: 1px solid var(--ft-line)">
                <div class="ft-page-head" style="margin: 0"><div><div class="ft-muted ft-small">{{ selected.code }} · WORKFLOW TEMPLATE</div><h2 style="font-size: 21px; margin: 5px 0">{{ selected.name }}</h2><p>{{ selected.description }}</p></div><div class="ft-actions"><button class="ft-secondary" @click="router.patch(`/flowtrack/workflow-setup/${selected.id}/publish`)">Publish v{{ selected.version + 1 }}</button><FlowBadge :value="selected.is_active ? 'Active' : 'Draft'" /></div></div>
            </div>
            <div class="ft-section-card"><div class="ft-section-head"><div class="ft-source-note" style="flex: 1; margin: 0">Publishing changes applies the configured sequence to new jobs. Existing jobs retain their phase history and task records.</div><button class="ft-primary" @click="addPhase">＋ Add Phase</button></div><div class="ft-table-wrap" style="margin-top: 14px"><table class="ft-config-table"><thead><tr><th>Seq.</th><th>Phase</th><th>Task Pack</th><th>Required Document</th><th>Allow Start</th><th>Skippable</th><th>Approval</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="(phase, index) in selected.phases" :key="phase.id"><td><b>{{ phase.sequence }}</b></td><td><b>{{ phase.name }}</b><div class="ft-muted ft-small">Entry: {{ phase.entry_condition || '—' }}<br />Exit: {{ phase.exit_condition || '—' }}</div></td><td>{{ phase.task_pack?.name || 'None' }}<div class="ft-muted ft-small">{{ phase.task_pack?.items?.length || 0 }} tasks</div></td><td>{{ phase.document_category?.name || 'None' }}</td><td><FlowBadge :value="phase.allow_job_start ? 'Allowed' : 'No'" /></td><td><FlowBadge :value="phase.is_skippable ? 'Optional' : 'Required'" /></td><td><FlowBadge :value="phase.requires_approval ? 'Approval' : 'No gate'" /></td><td><FlowBadge :value="phase.is_active ? 'Active' : 'Disabled'" /></td><td><div class="ft-sequence-controls"><button class="ft-mini-btn" @click="movePhase(index, -1)">↑</button><button class="ft-mini-btn" @click="movePhase(index, 1)">↓</button><button class="ft-mini-btn" @click="editPhase(phase)">Edit</button><button class="ft-mini-btn" @click="router.delete(`/flowtrack/workflow-phases/${phase.id}`, { preserveScroll: true })">Delete</button></div></td></tr></tbody></table></div></div>
        </article>
    </section>

    <FlowModal :open="createOpen" title="Create Workflow" subtitle="Create a blank workflow or duplicate an existing template." @close="createOpen = false">
        <div class="ft-form-grid"><div class="ft-field"><label>Name</label><input v-model="createForm.name" required /></div><div class="ft-field"><label>Code</label><input v-model="createForm.code" required placeholder="e.g. FASTTRACK" /></div><div class="ft-field full"><label>Description</label><textarea v-model="createForm.description" rows="3"></textarea></div><div class="ft-field full"><label>Start from</label><select v-model="createForm.base_workflow_id"><option :value="null">Blank workflow</option><option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">Duplicate {{ workflow.name }}</option></select></div></div>
        <template #footer><button class="ft-ghost" @click="createOpen = false">Cancel</button><button class="ft-primary" :disabled="createForm.processing" @click="createWorkflow">Create Workflow</button></template>
    </FlowModal>

    <FlowModal :open="phaseOpen" :title="editingPhase ? 'Configure Workflow Phase' : 'Add Workflow Phase'" subtitle="These controls are enforced by the backend workflow engine." @close="phaseOpen = false">
        <div class="ft-form-grid"><div class="ft-field"><label>Phase name</label><input v-model="phaseForm.name" /></div><div class="ft-field"><label>Short name</label><input v-model="phaseForm.short_name" /></div><div class="ft-field"><label>Task pack</label><select v-model="phaseForm.task_pack_id"><option :value="null">No automatic tasks</option><option v-for="pack in taskPacks" :key="pack.id" :value="pack.id">{{ pack.name }}</option></select></div><div class="ft-field"><label>Document category</label><select v-model="phaseForm.document_category_id"><option :value="null">No required category</option><option v-for="category in records(options, 'document_category')" :key="category.id" :value="category.id">{{ category.name }}</option></select></div><div class="ft-field full"><label>Entry condition</label><input v-model="phaseForm.entry_condition" /></div><div class="ft-field full"><label>Exit condition</label><input v-model="phaseForm.exit_condition" /></div><label class="ft-check-row"><input v-model="phaseForm.allow_job_start" type="checkbox" /> Jobs may start here</label><label class="ft-check-row"><input v-model="phaseForm.is_skippable" type="checkbox" /> Phase may be skipped</label><label class="ft-check-row"><input v-model="phaseForm.requires_approval" type="checkbox" /> Approval required</label><label class="ft-check-row"><input v-model="phaseForm.is_active" type="checkbox" /> Phase active</label></div>
        <template #footer><button class="ft-ghost" @click="phaseOpen = false">Cancel</button><button class="ft-primary" :disabled="phaseForm.processing" @click="savePhase">Save Phase</button></template>
    </FlowModal>
</template>
