<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';

const props = defineProps<{
    taskPacks: any[];
    memberships: any[];
    priorities: any[];
    departments: any[];
    documentCategories: any[];
    nextTaskPackCode: string;
    metrics: { total: number; active: number; configuredTasks: number; mappedPhases: number };
}>();

const packOpen = ref(false);
const itemOpen = ref(false);
const editingPack = ref<any>(null);
const editingItem = ref<any>(null);
const selectedPack = ref<any>(null);

const packForm = useForm({
    name: '',
    description: '',
    is_active: true,
});

const itemForm = useForm({
    title: '',
    description: '',
    default_assignee_id: null as number | null,
    default_department_id: null as number | null,
    priority_id: null as number | null,
    document_category_id: null as number | null,
    due_offset_days: 1,
    is_required: true,
});


function openPack(pack: any | null = null) {
    editingPack.value = pack;
    packForm.defaults({
        name: pack?.name || '',
        description: pack?.description || '',
        is_active: pack?.is_active ?? true,
    });
    packForm.reset();
    packForm.clearErrors();
    packOpen.value = true;
}

function savePack() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            packOpen.value = false;
            editingPack.value = null;
            packForm.reset();
        },
    };

    if (editingPack.value) {
        packForm.put(`/flowtrack/task-packs/${editingPack.value.id}`, options);
    } else {
        packForm.post('/flowtrack/task-packs', options);
    }
}

function openItem(pack: any, item: any | null = null) {
    selectedPack.value = pack;
    editingItem.value = item;
    itemForm.defaults({
        title: item?.title || '',
        description: item?.description || '',
        default_assignee_id: item?.default_assignee_id || null,
        default_department_id: item?.default_department_id || null,
        priority_id: item?.priority_id || null,
        document_category_id: item?.document_category_id || null,
        due_offset_days: item?.due_offset_days ?? 1,
        is_required: item?.is_required ?? true,
    });
    itemForm.reset();
    itemForm.clearErrors();
    itemOpen.value = true;
}

function saveItem() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            itemOpen.value = false;
            editingItem.value = null;
            selectedPack.value = null;
            itemForm.reset();
        },
    };

    if (editingItem.value) {
        itemForm.put(`/flowtrack/task-pack-items/${editingItem.value.id}`, options);
    } else {
        itemForm.post(`/flowtrack/task-packs/${selectedPack.value.id}/items`, options);
    }
}

function deleteItem(item: any) {
    if (!window.confirm(`Remove “${item.title}” from this Task Pack?`)) return;
    router.delete(`/flowtrack/task-pack-items/${item.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Task Pack Setup" />

    <FlowPageHeader
        title="Task Pack Setup"
        subtitle="Create reusable task sequences that activate whenever a Job enters the mapped workflow phase"
    >
        <button class="ft-primary" @click="openPack()">＋ Add Task Pack</button>
    </FlowPageHeader>

    <div class="ft-config-summary">
        <div class="ft-card ft-config-stat"><span>Total Task Packs</span><b>{{ metrics.total }}</b></div>
        <div class="ft-card ft-config-stat"><span>Active Task Packs</span><b>{{ metrics.active }}</b></div>
        <div class="ft-card ft-config-stat"><span>Configured Tasks</span><b>{{ metrics.configuredTasks }}</b></div>
        <div class="ft-card ft-config-stat"><span>Mapped Phases</span><b>{{ metrics.mappedPhases }}</b></div>
    </div>

    <section class="ft-grid-2">
        <article v-for="pack in taskPacks" :key="pack.id" class="ft-card ft-section-card">
            <div class="ft-section-head">
                <div>
                    <h3>{{ pack.name }}</h3>
                    <div class="ft-muted ft-small">{{ pack.code }} · {{ pack.description || 'No description' }}</div>
                </div>
                <div class="ft-actions">
                    <FlowBadge :value="pack.is_active ? 'Active' : 'Inactive'" />
                    <button class="ft-mini-btn" @click="openPack(pack)">Edit Pack</button>
                    <button class="ft-mini-btn" @click="openItem(pack)">＋ Add Task</button>
                </div>
            </div>

            <div v-for="(item, index) in pack.items" :key="item.id" class="ft-check-row">
                <span class="ft-badge gray">{{ index + 1 }}</span>
                <div style="flex: 1">
                    <b>{{ item.title }}</b>
                    <div class="ft-muted ft-small">
                        {{ item.default_assignee?.name || item.default_department?.name || 'Job coordinator' }}
                        · Due +{{ item.due_offset_days }} day(s)
                        <span v-if="item.priority"> · {{ item.priority.name }}</span>
                        <span v-if="item.document_category"> · Required file: {{ item.document_category.name }}</span>
                    </div>
                </div>
                <div class="ft-actions">
                    <FlowBadge :value="item.is_required ? 'Required' : 'Optional'" />
                    <button class="ft-mini-btn" @click="openItem(pack, item)">Edit</button>
                    <button class="ft-mini-btn" @click="deleteItem(item)">×</button>
                </div>
            </div>

            <div v-if="!pack.items?.length" class="ft-empty">No tasks configured in this Task Pack.</div>
        </article>

        <div v-if="!taskPacks.length" class="ft-card ft-empty">No Task Packs have been created.</div>
    </section>

    <FlowModal
        :open="packOpen"
        :title="editingPack ? 'Edit Task Pack' : 'Create Task Pack'"
        subtitle="The generated code cannot be changed by users."
        @close="packOpen = false"
    >
        <div class="ft-form-grid">
            <div class="ft-field">
                <label>Code</label>
                <input :value="editingPack?.code || nextTaskPackCode" readonly aria-readonly="true" />
                <span class="ft-muted ft-small">Automatically generated and permanently locked.</span>
            </div>
            <div class="ft-field">
                <label>Status</label>
                <select v-model="packForm.is_active">
                    <option :value="true">Active</option>
                    <option :value="false">Inactive</option>
                </select>
            </div>
            <div class="ft-field full">
                <label>Name</label>
                <input v-model="packForm.name" required />
                <span v-if="packForm.errors.name" class="ft-error">{{ packForm.errors.name }}</span>
            </div>
            <div class="ft-field full">
                <label>Description</label>
                <textarea v-model="packForm.description" rows="3"></textarea>
            </div>
        </div>
        <template #footer>
            <button class="ft-ghost" @click="packOpen = false">Cancel</button>
            <button class="ft-primary" :disabled="packForm.processing" @click="savePack">
                {{ packForm.processing ? 'Saving…' : editingPack ? 'Save Task Pack' : 'Create Task Pack' }}
            </button>
        </template>
    </FlowModal>

    <FlowModal
        :open="itemOpen"
        :title="editingItem ? 'Edit Task' : `Add Task to ${selectedPack?.name || 'Task Pack'}`"
        subtitle="These defaults are applied when the Task Pack activates for a Job."
        @close="itemOpen = false"
    >
        <div class="ft-form-grid">
            <div class="ft-field full">
                <label>Task title</label>
                <input v-model="itemForm.title" required />
                <span v-if="itemForm.errors.title" class="ft-error">{{ itemForm.errors.title }}</span>
            </div>
            <div class="ft-field full"><label>Description</label><textarea v-model="itemForm.description" rows="3"></textarea></div>
            <div class="ft-field">
                <label>Default assignee</label>
                <select v-model="itemForm.default_assignee_id">
                    <option :value="null">Job coordinator</option>
                    <option v-for="member in memberships" :key="member.user_id" :value="member.user_id">{{ member.user?.name }}</option>
                </select>
            </div>
            <div class="ft-field">
                <label>Default department</label>
                <select v-model="itemForm.default_department_id">
                    <option :value="null">No department default</option>
                    <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                </select>
            </div>
            <div class="ft-field">
                <label>Priority</label>
                <select v-model="itemForm.priority_id">
                    <option :value="null">Use Job priority</option>
                    <option v-for="priority in priorities" :key="priority.id" :value="priority.id">{{ priority.name }}</option>
                </select>
            </div>
            <div class="ft-field">
                <label>Required document</label>
                <select v-model="itemForm.document_category_id">
                    <option :value="null">No task-specific file</option>
                    <option v-for="category in documentCategories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <span class="ft-muted ft-small">When selected, this document must be uploaded for the Job before this task can be completed.</span>
            </div>
            <div class="ft-field">
                <label>Due after phase starts</label>
                <input v-model.number="itemForm.due_offset_days" type="number" min="0" max="365" />
            </div>
            <label class="ft-check-row"><input v-model="itemForm.is_required" type="checkbox" /> Required task</label>
        </div>
        <template #footer>
            <button class="ft-ghost" @click="itemOpen = false">Cancel</button>
            <button class="ft-primary" :disabled="itemForm.processing" @click="saveItem">
                {{ itemForm.processing ? 'Saving…' : editingItem ? 'Save Task' : 'Add Task' }}
            </button>
        </template>
    </FlowModal>
</template>
