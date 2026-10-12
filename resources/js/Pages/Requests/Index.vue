<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import type { InstanceSummary } from '@/Workflow/format';
import { currentStep, statusLabel, when } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { FilePlus2, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{ instances: Paginated<InstanceSummary>; filters: { status?: string; q?: string }; counts: Record<string, number> }>();

const status = ref(props.filters.status ?? '');
const q = ref(props.filters.q ?? '');
let t: ReturnType<typeof setTimeout>;
const go = () => router.get(route('requests.index'), Object.fromEntries(Object.entries({ status: status.value, q: q.value }).filter(([, v]) => v)), { preserveState: true, replace: true });
watch(status, go);
watch(q, () => {
    clearTimeout(t);
    t = setTimeout(go, 300);
});
const tabs = [
    { key: '', label: 'All' },
    { key: 'in_progress', label: 'In progress' },
    { key: 'returned', label: 'Returned to me' },
    { key: 'draft', label: 'Drafts' },
    { key: 'completed', label: 'Completed' },
    { key: 'rejected', label: 'Not approved' },
].map((x) => ({ ...x, count: x.key ? (props.counts[x.key] ?? 0) : undefined }));
</script>

<template>
    <AppLayout title="My requests">
        <PageHeader title="My requests" description="Everything you have raised, where it is, and who has it now.">
            <AppButton :icon="FilePlus2" :href="route('requests.catalogue')">New request</AppButton>
        </PageHeader>

        <TabsNav v-model="status" :items="tabs" class="mb-4" />
        <DataTable :empty="!instances.data.length" empty-title="No requests here">
            <template #toolbar><div class="w-full max-w-sm"><TextInput v-model="q" :icon="Search" placeholder="Reference or title" aria-label="Search" /></div></template>
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Request</th><th>Status</th><th>With</th><th>Updated</th></tr></thead>
                <tbody>
                    <tr v-for="i in instances.data" :key="i.id">
                        <td class="font-mono text-xs whitespace-nowrap"><Link :href="route(i.status === 'draft' ? 'requests.edit' : 'requests.show', i.id)" class="text-brand-700 hover:underline">{{ i.reference ?? 'Draft' }}</Link></td>
                        <td><span class="font-medium text-ink">{{ i.title }}</span><p class="text-xs text-muted">{{ i.workflow }}</p></td>
                        <td><StatusBadge :status="i.status" :label="statusLabel(i.status)" /></td>
                        <td class="text-sm">{{ currentStep(i) }}</td>
                        <td class="text-xs whitespace-nowrap text-muted">{{ when(i.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="instances.links" :from="instances.from" :to="instances.to" :total="instances.total" /></template>
        </DataTable>
    </AppLayout>
</template>
