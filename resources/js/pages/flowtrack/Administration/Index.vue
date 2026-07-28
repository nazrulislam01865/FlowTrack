<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowModal from '@/components/flowtrack/FlowModal.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import { initials } from '@/lib/flowtrack';

const props = defineProps<{ memberships: any[]; roles: any[]; permissions: Record<string, any[]>; taskPacks: any[]; priorities: any[]; departments: any[]; nextTaskPackCode: string }>();
const tab = ref<'packs' | 'users' | 'roles'>('packs');
const modalOpen = ref(false);
const editing = ref<any>(null);
const packOpen = ref(false);
const itemOpen = ref(false);
const inviteOpen = ref(false);
const roleOpen = ref(false);
const roleEditing = ref<any>(null);
const selectedPack = ref<any>(null);
const form = useForm({ role_id: null as number | null, department_id: null as number | null, job_title: '', status: 'active' });
const packForm = useForm({ name: '', description: '', is_active: true });
const itemForm = useForm({ title: '', description: '', default_assignee_id: null as number | null, default_department_id: null as number | null, priority_id: null as number | null, due_offset_days: 1, is_required: true });
const inviteForm = useForm({ name: '', initials: '', email: '', password: '', role_id: props.roles[0]?.id || null as number | null, department_id: props.departments[0]?.id || null as number | null, job_title: '' });
const roleForm = useForm({ name: '', slug: '', permission_ids: [] as number[] });
const departments = computed(() => props.departments || []);

function editMember(member: any) {
    editing.value = member;
    form.defaults({ role_id: member.role_id, department_id: member.department_id, job_title: member.job_title || '', status: member.status });
    form.reset(); form.clearErrors(); modalOpen.value = true;
}
function save() { form.put(`/flowtrack/administration/members/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (modalOpen.value = false) }); }
function createPack() { packForm.post('/flowtrack/task-packs', { preserveScroll: true, onSuccess: () => { packOpen.value = false; packForm.reset(); } }); }
function openItem(pack: any) { selectedPack.value = pack; itemForm.reset(); itemForm.default_assignee_id = props.memberships[0]?.user_id || null; itemForm.priority_id = props.priorities[0]?.id || null; itemOpen.value = true; }
function createItem() { itemForm.post(`/flowtrack/task-packs/${selectedPack.value.id}/items`, { preserveScroll: true, onSuccess: () => { itemOpen.value = false; itemForm.reset(); } }); }
function inviteMember() { inviteForm.post('/flowtrack/administration/members', { preserveScroll: true, onSuccess: () => { inviteOpen.value = false; inviteForm.reset(); } }); }

function openRole(role: any | null = null) {
    roleEditing.value = role;
    roleForm.defaults({
        name: role?.name || '',
        slug: role?.slug || '',
        permission_ids: (role?.permissions || []).map((permission: any) => permission.id),
    });
    roleForm.reset();
    roleForm.clearErrors();
    roleOpen.value = true;
}
function saveRole() {
    const options = { preserveScroll: true, onSuccess: () => { roleOpen.value = false; roleEditing.value = null; roleForm.reset(); } };
    if (roleEditing.value) roleForm.put(`/flowtrack/administration/roles/${roleEditing.value.id}`, options);
    else roleForm.post('/flowtrack/administration/roles', options);
}
</script>

<template>
    <Head title="Administration" />
    <FlowPageHeader title="Administration" subtitle="Users, roles, permissions, departments and reusable task packs"><button class="ft-ghost" @click="packOpen = true">＋ New Task Pack</button><button class="ft-ghost" @click="openRole()">＋ New Role</button><button class="ft-secondary" @click="inviteOpen = true">＋ Add Member</button><a class="ft-primary" href="/flowtrack/workflow-setup">Open Workflow Setup</a></FlowPageHeader>
    <div class="ft-config-summary"><div class="ft-card ft-config-stat"><span>Active Users</span><b>{{ memberships.filter((item) => item.status === 'active').length }}</b></div><div class="ft-card ft-config-stat"><span>Roles</span><b>{{ roles.length }}</b></div><div class="ft-card ft-config-stat"><span>Task Packs</span><b>{{ taskPacks.length }}</b></div><div class="ft-card ft-config-stat"><span>Permissions</span><b>{{ Object.values(permissions).flat().length }}</b></div></div>
    <div class="ft-board-tabs"><button class="ft-tab-btn" :class="{ active: tab === 'packs' }" @click="tab = 'packs'">Task Packs</button><button class="ft-tab-btn" :class="{ active: tab === 'users' }" @click="tab = 'users'">Users</button><button class="ft-tab-btn" :class="{ active: tab === 'roles' }" @click="tab = 'roles'">Roles & Permissions</button></div>

    <section v-if="tab === 'packs'" class="ft-grid-2">
        <article v-for="pack in taskPacks" :key="pack.id" class="ft-card ft-section-card"><div class="ft-section-head"><div><h3>{{ pack.name }}</h3><div class="ft-muted ft-small">{{ pack.code }} · {{ pack.description }}</div></div><div class="ft-actions"><button class="ft-mini-btn" @click="openItem(pack)">＋ Add Task</button><FlowBadge :value="pack.is_active ? 'Active' : 'Inactive'" /></div></div><div v-for="(item, index) in pack.items" :key="item.id" class="ft-check-row"><span class="ft-badge gray">{{ index + 1 }}</span><div style="flex: 1"><b>{{ item.title }}</b><div class="ft-muted ft-small">{{ item.default_assignee?.name || 'Job coordinator' }} · Due +{{ item.due_offset_days }} day(s)</div></div><div class="ft-actions"><FlowBadge :value="item.is_required ? 'Required' : 'Optional'" /><button class="ft-mini-btn" @click="router.delete(`/flowtrack/task-pack-items/${item.id}`, { preserveScroll: true })">×</button></div></div></article>
    </section>

    <section v-else-if="tab === 'users'" class="ft-card ft-table-wrap"><table class="ft-table"><thead><tr><th>Member</th><th>Department</th><th>Job Title</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="member in memberships" :key="member.id"><td><div class="ft-person"><span class="ft-avatar">{{ member.user?.initials || initials(member.user?.name) }}</span><div><b>{{ member.user?.name }}</b><div class="ft-muted ft-small">{{ member.user?.email }}</div></div></div></td><td>{{ member.department?.name || '—' }}</td><td>{{ member.job_title || '—' }}</td><td><FlowBadge :value="member.role?.name" /></td><td><FlowBadge :value="member.status" /></td><td><button class="ft-mini-btn" @click="editMember(member)">Edit Access</button></td></tr></tbody></table></section>

    <section v-else class="ft-grid-2"><article v-for="role in roles" :key="role.id" class="ft-card ft-section-card"><div class="ft-section-head"><div><h3>{{ role.name }}</h3><div class="ft-muted ft-small">{{ role.slug }}</div></div><div class="ft-actions"><button class="ft-mini-btn" @click="openRole(role)">Edit Permissions</button><FlowBadge :value="role.is_system ? 'System Role' : 'Custom Role'" /></div></div><div v-for="permission in role.permissions" :key="permission.id" class="ft-check-row"><span class="ft-signal green"></span><div><b>{{ permission.name }}</b><div class="ft-muted ft-small">{{ permission.group }}</div></div></div></article></section>

    <FlowModal :open="roleOpen" :title="roleEditing ? 'Edit Role Permissions' : 'Create Role'" subtitle="Roles define workspace-wide capabilities. Project membership still limits assigned Job access." @close="roleOpen = false">
        <div class="ft-form-grid">
            <div class="ft-field"><label>Role name</label><input v-model="roleForm.name" required /><span v-if="roleForm.errors.name" class="ft-error">{{ roleForm.errors.name }}</span></div>
            <div class="ft-field"><label>Role slug</label><input v-model="roleForm.slug" required placeholder="e.g. quality-manager" /><span v-if="roleForm.errors.slug" class="ft-error">{{ roleForm.errors.slug }}</span></div>
            <div class="ft-field full"><label>Permissions</label><div v-for="(group, groupName) in permissions" :key="groupName" class="ft-card" style="padding: 12px; margin-bottom: 8px"><b>{{ groupName }}</b><label v-for="permission in group" :key="permission.id" class="ft-check-row"><input v-model="roleForm.permission_ids" type="checkbox" :value="permission.id" /><span><b>{{ permission.name }}</b><span class="ft-muted ft-small" style="display: block">{{ permission.slug }}</span></span></label></div><span v-if="roleForm.errors.permission_ids" class="ft-error">{{ roleForm.errors.permission_ids }}</span></div>
        </div>
        <template #footer><button class="ft-ghost" @click="roleOpen = false">Cancel</button><button class="ft-primary" :disabled="roleForm.processing" @click="saveRole">{{ roleForm.processing ? 'Saving…' : 'Save Role' }}</button></template>
    </FlowModal>

    <FlowModal :open="packOpen" title="Create Task Pack" subtitle="Reusable task packs are activated automatically by workflow phases." @close="packOpen = false">
        <div class="ft-form-grid"><div class="ft-field"><label>Code</label><input :value="nextTaskPackCode" readonly aria-readonly="true" /><span class="ft-muted ft-small">Automatically generated and permanently locked.</span></div><div class="ft-field"><label>Status</label><select v-model="packForm.is_active"><option :value="true">Active</option><option :value="false">Inactive</option></select></div><div class="ft-field full"><label>Name</label><input v-model="packForm.name" required /></div><div class="ft-field full"><label>Description</label><textarea v-model="packForm.description" rows="3"></textarea></div></div>
        <template #footer><button class="ft-ghost" @click="packOpen = false">Cancel</button><button class="ft-primary" :disabled="packForm.processing" @click="createPack">Create Pack</button></template>
    </FlowModal>

    <FlowModal :open="itemOpen" :title="`Add Task to ${selectedPack?.name || 'Pack'}`" subtitle="The assignee, priority and due date can be changed after the task is created on a job." @close="itemOpen = false">
        <div class="ft-form-grid"><div class="ft-field full"><label>Task title</label><input v-model="itemForm.title" required /></div><div class="ft-field full"><label>Description</label><textarea v-model="itemForm.description" rows="3"></textarea></div><div class="ft-field"><label>Default assignee</label><select v-model="itemForm.default_assignee_id"><option :value="null">Job coordinator</option><option v-for="member in memberships" :key="member.user_id" :value="member.user_id">{{ member.user?.name }}</option></select></div><div class="ft-field"><label>Default department</label><select v-model="itemForm.default_department_id"><option :value="null">No department default</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></div><div class="ft-field"><label>Priority</label><select v-model="itemForm.priority_id"><option :value="null">Job priority</option><option v-for="priority in priorities" :key="priority.id" :value="priority.id">{{ priority.name }}</option></select></div><div class="ft-field"><label>Due after phase starts</label><input v-model.number="itemForm.due_offset_days" type="number" min="0" /></div><label class="ft-check-row"><input v-model="itemForm.is_required" type="checkbox" /> Required task</label></div>
        <template #footer><button class="ft-ghost" @click="itemOpen = false">Cancel</button><button class="ft-primary" :disabled="itemForm.processing" @click="createItem">Add Task</button></template>
    </FlowModal>

    <FlowModal :open="inviteOpen" title="Add Workspace Member" subtitle="Create a secure account and assign its initial role and department." @close="inviteOpen = false">
        <div class="ft-form-grid">
            <div class="ft-field"><label>Name</label><input v-model="inviteForm.name" required /><span v-if="inviteForm.errors.name" class="ft-error">{{ inviteForm.errors.name }}</span></div>
            <div class="ft-field"><label>Initials</label><input v-model="inviteForm.initials" maxlength="8" placeholder="Auto-generated when blank" /></div>
            <div class="ft-field"><label>Email</label><input v-model="inviteForm.email" type="email" required /><span v-if="inviteForm.errors.email" class="ft-error">{{ inviteForm.errors.email }}</span></div>
            <div class="ft-field"><label>Temporary password</label><input v-model="inviteForm.password" type="password" minlength="8" required /><span v-if="inviteForm.errors.password" class="ft-error">{{ inviteForm.errors.password }}</span></div>
            <div class="ft-field"><label>Role</label><select v-model="inviteForm.role_id"><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></div>
            <div class="ft-field"><label>Department</label><select v-model="inviteForm.department_id"><option :value="null">No department</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></div>
            <div class="ft-field full"><label>Job title</label><input v-model="inviteForm.job_title" /></div>
        </div>
        <template #footer><button class="ft-ghost" @click="inviteOpen = false">Cancel</button><button class="ft-primary" :disabled="inviteForm.processing" @click="inviteMember">Create Member</button></template>
    </FlowModal>

    <FlowModal :open="modalOpen" title="Update Member Access" :subtitle="editing?.user?.name" @close="modalOpen = false">
        <div class="ft-form-grid"><div class="ft-field"><label>Role</label><select v-model="form.role_id"><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></div><div class="ft-field"><label>Department</label><select v-model="form.department_id"><option :value="null">No department</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></div><div class="ft-field"><label>Job title</label><input v-model="form.job_title" /></div><div class="ft-field"><label>Status</label><select v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="pending">Pending</option></select></div></div>
        <template #footer><button class="ft-ghost" @click="modalOpen = false">Cancel</button><button class="ft-primary" :disabled="form.processing" @click="save">Save Access</button></template>
    </FlowModal>
</template>
