<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { when } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { FolderPlus, Lock, Scale, Search } from 'lucide-vue-next';
import { reactive, watch } from 'vue';
import { classLabel, classTone } from './classification';

interface Row { id: number; file_number: string; subject: string; classification: string; status: string; note_count: number; disposition: string | null; legal_hold: boolean; category: string; department: string; custodian: string; in_transit_to: string | null; updated_at: string }
const props = defineProps<{
    tab: string;
    files: Paginated<Row>;
    filters: Record<string, string | number | undefined>;
    counts: Record<string, number>;
    categories: { id: number; name: string }[];
    departments: { id: number; code: string; name: string }[];
    can: { open: boolean; register: boolean };
}>();

const f = reactive({ q: props.filters.q ?? '', status: props.filters.status ?? '', category: props.filters.category ?? '', department: props.filters.department ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('efiles.index'), { tab: props.tab, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')) }, { preserveState: true, replace: true }), 300);
});
const tabs = [
    { key: 'with_me', label: 'With me', count: props.counts.with_me },
    { key: 'incoming', label: 'Sent to me', count: props.counts.incoming },
    { key: 'sent', label: 'Awaiting receipt', count: props.counts.sent },
    { key: 'all', label: 'All I can see' },
    ...(props.can.register ? [{ key: 'register', label: 'Register' }] : []),
];
const go = (tab?: string) => router.get(route('efiles.index'), { tab });
const empty: Record<string, string> = { with_me: 'No files are with you', incoming: 'No files are waiting for you', sent: 'Nothing you sent is awaiting receipt', all: 'No files found', register: 'No files found' };
</script>

<template>
    <AppLayout title="Files">
        <PageHeader title="Files" description="Electronic files with their note sheets, enclosures, correspondence and movement.">
            <AppButton v-if="can.open" :icon="FolderPlus" :href="route('efiles.create')">Open a file</AppButton>
        </PageHeader>
        <TabsNav :model-value="tab" :items="tabs" class="mb-4" @update:model-value="go" />
        <DataTable :empty="!files.data.length" :empty-title="empty[tab]">
            <template v-if="tab === 'all' || tab === 'register'" #toolbar>
                <div class="grid w-full gap-2 sm:grid-cols-4">
                    <TextInput v-model="f.q" :icon="Search" placeholder="File number or subject" aria-label="Search files" />
                    <SelectInput v-model="f.status" :options="['open', 'closed', 'archived', 'destroyed'].map((s) => ({ value: s, label: s }))" placeholder="Any status" aria-label="Status" />
                    <SelectInput v-model="f.category" :options="categories.map((c) => ({ value: c.id, label: c.name }))" placeholder="Any category" aria-label="Category" />
                    <SelectInput v-model="f.department" :options="departments.map((d) => ({ value: d.id, label: d.code }))" placeholder="Any department" aria-label="Department" />
                </div>
            </template>
            <table class="data-table">
                <thead><tr><th>File</th><th>Subject</th><th>Class</th><th>With</th><th>Status</th><th>Updated</th></tr></thead>
                <tbody>
                    <tr v-for="r in files.data" :key="r.id">
                        <td class="font-mono text-xs whitespace-nowrap"><Link :href="route('efiles.show', r.id)" class="text-brand-700 hover:underline">{{ r.file_number }}</Link></td>
                        <td><span class="font-medium text-ink">{{ r.subject }}</span><p class="text-xs text-muted">{{ r.category }} · {{ r.department }} · {{ r.note_count }} notes</p></td>
                        <td><span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', classTone[r.classification]]"><Lock v-if="r.classification === 'confidential' || r.classification === 'restricted'" :size="11" aria-hidden="true" />{{ classLabel[r.classification] }}</span></td>
                        <td class="text-sm">{{ r.custodian }}<p v-if="r.in_transit_to" class="text-xs text-amber-700">in transit to {{ r.in_transit_to }}</p></td>
                        <td>
                            <StatusBadge :status="r.status === 'open' ? 'active' : r.status === 'destroyed' ? 'removed' : 'closed'" :label="r.status" />
                            <span v-if="r.legal_hold" class="ml-1 inline-flex items-center gap-0.5 text-xs text-red-700"><Scale :size="11" aria-hidden="true" />hold</span>
                            <span v-else-if="r.disposition === 'due'" class="ml-1 text-xs text-amber-700">disposition due</span>
                        </td>
                        <td class="text-xs whitespace-nowrap text-muted">{{ when(r.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="files.links" :from="files.from" :to="files.to" :total="files.total" /></template>
        </DataTable>
    </AppLayout>
</template>
