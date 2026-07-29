<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FlowBadge from '@/components/flowtrack/FlowBadge.vue';
import FlowEmpty from '@/components/flowtrack/FlowEmpty.vue';
import FlowPageHeader from '@/components/flowtrack/FlowPageHeader.vue';
import { formatDate, initials } from '@/lib/flowtrack';

const props = defineProps<{ query: string; results: { jobs: any[]; tasks: any[]; clients: any[]; documents: any[] } }>();
const search = ref(props.query);
const total = props.results.jobs.length + props.results.tasks.length + props.results.clients.length + props.results.documents.length;

function submit() {
    if (search.value.trim().length < 2) return;
    router.get('/flowtrack/search', { q: search.value.trim() });
}
</script>

<template>
    <Head :title="query ? `Search: ${query}` : 'Search'" />
    <FlowPageHeader title="Global Search" subtitle="Search visible Jobs, tasks, clients, and documents across your workspace" />
    <form class="ft-card ft-toolbar" @submit.prevent="submit"><input v-model="search" class="ft-search-small" placeholder="Enter at least two characters…" /><button class="ft-primary">Search</button></form>
    <div v-if="query && total" class="ft-source-note" style="margin-bottom: 14px">Found {{ total }} result(s) for <b>{{ query }}</b>. Results respect your workspace role and project assignments.</div>
    <FlowEmpty v-if="!query || !total" :title="query ? 'No matching records' : 'Start a workspace search'" :message="query ? 'Try a Job code, task title, client, contact, or document name.' : 'Enter at least two characters to search accessible records.'" />
    <template v-else>
        <section v-if="results.jobs.length" class="ft-card ft-section-card" style="margin-bottom: 14px"><div class="ft-section-head"><h3>Jobs</h3><FlowBadge :value="`${results.jobs.length} result(s)`" /></div><Link v-for="job in results.jobs" :key="job.id" :href="`/flowtrack/jobs/${job.id}`" class="ft-attention-item"><span class="ft-signal" :class="job.health === 'on_track' ? 'green' : ''"></span><div><div class="ft-item-title">{{ job.code }} · {{ job.title }}</div><div class="ft-item-meta">{{ job.client?.name }} · {{ job.current_phase?.name || 'No phase' }} · {{ job.progress }}%</div></div><FlowBadge :value="job.health" health :title="job.health_reason || 'Calculated from current-phase Tasks and delivery dates.'" /></Link></section>
        <section v-if="results.tasks.length" class="ft-card ft-section-card" style="margin-bottom: 14px"><div class="ft-section-head"><h3>Tasks</h3><FlowBadge :value="`${results.tasks.length} result(s)`" /></div><Link v-for="task in results.tasks" :key="task.id" :href="`/flowtrack/my-work?search=${encodeURIComponent(task.code)}`" class="ft-attention-item"><span class="ft-avatar">{{ task.assignee?.initials || initials(task.assignee?.name) }}</span><div><div class="ft-item-title">{{ task.code }} · {{ task.title }}</div><div class="ft-item-meta">{{ task.job?.code }} · {{ task.job?.client?.name }} · Due {{ formatDate(task.due_date) }}</div></div><FlowBadge :value="task.status?.name" /></Link></section>
        <section v-if="results.clients.length" class="ft-card ft-section-card" style="margin-bottom: 14px"><div class="ft-section-head"><h3>Clients</h3><FlowBadge :value="`${results.clients.length} result(s)`" /></div><Link v-for="client in results.clients" :key="client.id" :href="`/flowtrack/clients?search=${encodeURIComponent(client.name)}`" class="ft-attention-item"><span class="ft-avatar">{{ client.code.slice(0, 2) }}</span><div><div class="ft-item-title">{{ client.name }}</div><div class="ft-item-meta">{{ client.country || 'No country' }} · {{ client.contact_name || 'No contact' }}</div></div><span>›</span></Link></section>
        <section v-if="results.documents.length" class="ft-card ft-section-card"><div class="ft-section-head"><h3>Documents</h3><FlowBadge :value="`${results.documents.length} result(s)`" /></div><Link v-for="document in results.documents" :key="document.id" :href="`/flowtrack/documents?search=${encodeURIComponent(document.code)}`" class="ft-attention-item"><span class="ft-doc-icon" style="margin: 0">FILE</span><div><div class="ft-item-title">{{ document.code }} · {{ document.name }}</div><div class="ft-item-meta">{{ document.job?.code }} · {{ document.job?.title }}</div></div><FlowBadge :value="document.is_final ? 'Final' : 'Working'" /></Link></section>
    </template>
</template>
