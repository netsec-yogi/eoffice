<script setup lang="ts">
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { date, when } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { Siren } from 'lucide-vue-next';

interface Row {
    id: number;
    name: string;
    kind: string;
    status: string;
    outcome: string | null;
    due_at: string | null;
    overdue: boolean;
    escalation_level: number;
    on_behalf_of: string | null;
    assignee: string | null;
    created_at: string;
    completed_at: string | null;
    instance: { id: number; reference: string; title: string; status: string; workflow: string; initiator: string };
}
const props = defineProps<{ tab: string; tasks: Paginated<Row>; counts: Record<string, number> }>();

const tabs = [
    { key: 'pending', label: 'Pending', count: props.counts.pending },
    { key: 'overdue', label: 'Overdue', count: props.counts.overdue },
    { key: 'delegated', label: 'Delegated to me', count: props.counts.delegated },
    { key: 'done', label: 'Done by me' },
];
const go = (tab?: string) => router.get(route('tasks.index'), { tab }, { preserveState: true });
const empty: Record<string, string> = { pending: 'Nothing waiting for you', overdue: 'Nothing overdue', delegated: 'No work delegated to you right now', done: 'You have not decided anything yet' };
</script>

<template>
    <AppLayout title="Inbox">
        <PageHeader title="Inbox" description="Requests waiting for your decision, oldest deadline first." />
        <TabsNav :model-value="tab" :items="tabs" class="mb-4" @update:model-value="go" />

        <DataTable :empty="!tasks.data.length" :empty-title="empty[tab]">
            <table class="data-table">
                <thead>
                    <tr><th>Request</th><th>Step</th><th>From</th><th>{{ tab === 'done' ? 'Decision' : 'Due' }}</th><th>Received</th></tr>
                </thead>
                <tbody>
                    <tr v-for="t in tasks.data" :key="t.id" :class="t.overdue && 'bg-red-50/40 dark:bg-red-500/5'">
                        <td>
                            <Link :href="route('requests.show', { instance: t.instance.id, task: t.id })" class="font-medium text-ink hover:text-brand-700">{{ t.instance.title }}</Link>
                            <p class="font-mono text-xs text-muted">{{ t.instance.reference }} · {{ t.instance.workflow }}</p>
                        </td>
                        <td>
                            {{ t.name }}
                            <p v-if="t.on_behalf_of" class="text-xs text-amber-700">for {{ t.on_behalf_of }}</p>
                            <p v-if="tab === 'delegated'" class="text-xs text-amber-700">assigned to {{ t.assignee }}</p>
                        </td>
                        <td>{{ t.instance.initiator }}</td>
                        <td class="whitespace-nowrap">
                            <StatusBadge v-if="tab === 'done'" :status="t.outcome === 'approve' ? 'approved' : t.outcome === 'reject' ? 'rejected' : (t.outcome ?? t.status)" :label="t.outcome ?? t.status" />
                            <template v-else>
                                <span :class="t.overdue ? 'font-semibold text-red-700' : 'text-ink-soft'">{{ t.due_at ? date(t.due_at) : '—' }}</span>
                                <span v-if="t.escalation_level" class="ml-1 inline-flex items-center gap-0.5 text-xs text-red-700"><Siren :size="12" aria-hidden="true" />L{{ t.escalation_level }}</span>
                                <StatusBadge v-if="t.instance.status === 'on_hold'" status="on_hold" label="on hold" class="ml-1" />
                            </template>
                        </td>
                        <td class="text-xs whitespace-nowrap text-muted">{{ when(tab === 'done' ? t.completed_at : t.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="tasks.links" :from="tasks.from" :to="tasks.to" :total="tasks.total" /></template>
        </DataTable>
    </AppLayout>
</template>
