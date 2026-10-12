<script setup lang="ts">
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { currentStep, statusLabel, when, type InstanceSummary } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { AlarmClock, CircleCheck, CircleX, FolderOpen, Search, Siren, Timer, Undo2 } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

const props = defineProps<{
    instances: Paginated<InstanceSummary>;
    filters: Record<string, string | number | undefined>;
    stats: Record<string, number>;
    workload: { name: string | null; open: number; overdue: number }[];
    workflows: { id: number; name: string }[];
    departments: { id: number; code: string; name: string }[];
}>();

const f = reactive({ status: props.filters.status ?? '', workflow: props.filters.workflow ?? '', department: props.filters.department ?? '', q: props.filters.q ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('monitor.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), { preserveState: true, replace: true }), 300);
});
const statuses = ['in_progress', 'overdue', 'returned', 'on_hold', 'completed', 'rejected', 'cancelled'].map((s) => ({ value: s, label: s === 'overdue' ? 'Has overdue step' : statusLabel(s) }));
</script>

<template>
    <AppLayout title="Monitor">
        <PageHeader title="Workflow monitor" description="All requests in your scope. Figures are live counts." />

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Open requests" :value="stats.open ?? 0" :icon="FolderOpen" tone="brand" />
            <StatCard label="Overdue steps" :value="stats.overdue_tasks ?? 0" :icon="AlarmClock" :tone="stats.overdue_tasks ? 'danger' : 'default'" :alert="!!stats.overdue_tasks" />
            <StatCard label="Escalated steps" :value="stats.escalated_tasks ?? 0" :icon="Siren" tone="warning" />
            <StatCard label="Returned for clarification" :value="stats.returned ?? 0" :icon="Undo2" />
            <StatCard label="Approved (90 days)" :value="stats.completed_90d ?? 0" :icon="CircleCheck" tone="success" />
            <StatCard label="Not approved (90 days)" :value="stats.rejected_90d ?? 0" :icon="CircleX" />
            <StatCard label="Average days to decide (90 days)" :value="stats.avg_days_90d ?? 0" :icon="Timer" />
        </div>

        <div class="grid gap-6 lg:grid-cols-4">
            <DataTable :empty="!instances.data.length" empty-title="No requests match" class="lg:col-span-3">
                <template #toolbar>
                    <div class="grid w-full gap-2 sm:grid-cols-4">
                        <TextInput v-model="f.q" :icon="Search" placeholder="Reference or title" aria-label="Search" />
                        <SelectInput v-model="f.status" :options="statuses" placeholder="Any status" aria-label="Status" />
                        <SelectInput v-model="f.workflow" :options="workflows.map((w) => ({ value: w.id, label: w.name }))" placeholder="Any workflow" aria-label="Workflow" />
                        <SelectInput v-model="f.department" :options="departments.map((d) => ({ value: d.id, label: d.code }))" placeholder="Any department" aria-label="Department" />
                    </div>
                </template>
                <table class="data-table">
                    <thead><tr><th>Reference</th><th>Request</th><th>Initiator</th><th>Status</th><th>With</th><th>Updated</th></tr></thead>
                    <tbody>
                        <tr v-for="i in instances.data" :key="i.id">
                            <td class="font-mono text-xs whitespace-nowrap"><Link :href="route('requests.show', i.id)" class="text-brand-700 hover:underline">{{ i.reference }}</Link></td>
                            <td><span class="font-medium text-ink">{{ i.title }}</span><p class="text-xs text-muted">{{ i.workflow }}</p></td>
                            <td class="text-sm">{{ i.initiator }}<p class="text-xs text-muted">{{ i.department }}</p></td>
                            <td><StatusBadge :status="i.status" :label="statusLabel(i.status)" /></td>
                            <td class="text-sm">
                                {{ currentStep(i) }}
                                <span v-if="i.current.some((c) => c.overdue)" class="block text-xs font-semibold text-red-700">overdue</span>
                            </td>
                            <td class="text-xs whitespace-nowrap text-muted">{{ when(i.updated_at) }}</td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><PaginationNav :links="instances.links" :from="instances.from" :to="instances.to" :total="instances.total" /></template>
            </DataTable>

            <section class="card h-fit p-5">
                <h2 class="mb-3 font-semibold text-ink">Workload</h2>
                <ul v-if="workload.length" class="space-y-2 text-sm">
                    <li v-for="w in workload" :key="w.name ?? ''" class="flex justify-between gap-2">
                        <span class="truncate text-ink">{{ w.name }}</span>
                        <span class="whitespace-nowrap text-muted">{{ w.open }}<span v-if="w.overdue" class="text-red-700"> · {{ w.overdue }} late</span></span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted">No open steps.</p>
            </section>
        </div>
    </AppLayout>
</template>
