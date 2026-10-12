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
import { date } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { CheckCheck, Circle, FilePlus, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Row { id: number; number: string | null; kind: string; title: string; status: string; version: number; requires_ack: boolean; published_at: string | null; author: string; read: boolean; acknowledged: boolean }
const props = defineProps<{ tab: string; circulars: Paginated<Row>; counts: Record<string, number>; can: { draft: boolean; manage: boolean } }>();

const tabs = [
    { key: 'inbox', label: 'Addressed to me', count: props.counts.unread || undefined },
    { key: 'pending_ack', label: 'To acknowledge', count: props.counts.pending_ack || undefined },
    ...(props.can.draft ? [{ key: 'drafts', label: 'Drafts' }] : []),
    ...(props.can.manage ? [{ key: 'all', label: 'All' }] : []),
];
const go = (tab?: string) => router.get(route('circulars.index'), { tab });
const q = ref('');
let t: ReturnType<typeof setTimeout>;
watch(q, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('circulars.index'), { tab: props.tab, q: q.value || undefined }, { preserveState: true, replace: true }), 300);
});
const kinds: Record<string, string> = { circular: 'Circular', office_order: 'Office order', notice: 'Notice' };
</script>

<template>
    <AppLayout title="Circulars">
        <PageHeader title="Circulars and office orders" description="Issued after approval, delivered to the people they are addressed to, with read receipts and acknowledgments.">
            <AppButton v-if="can.draft" :icon="FilePlus" :href="route('circulars.create')">Draft</AppButton>
        </PageHeader>
        <TabsNav :model-value="tab" :items="tabs" class="mb-4" @update:model-value="go" />
        <DataTable :empty="!circulars.data.length" empty-title="Nothing here">
            <template #toolbar><div class="w-full max-w-sm"><TextInput v-model="q" :icon="Search" placeholder="Number or title" aria-label="Search" /></div></template>
            <table class="data-table">
                <thead><tr><th><span class="sr-only">Read</span></th><th>Number</th><th>Title</th><th>Issued</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="c in circulars.data" :key="c.id">
                        <td class="w-6"><Circle v-if="!c.read && c.published_at" :size="9" class="fill-brand-600 text-brand-600" aria-label="Unread" /></td>
                        <td class="font-mono text-xs whitespace-nowrap">{{ c.number ?? 'draft' }}<p class="font-sans text-muted">{{ kinds[c.kind] }}<template v-if="c.version > 1"> · v{{ c.version }}</template></p></td>
                        <td><Link :href="route('circulars.show', c.id)" :class="['hover:text-brand-700', c.read || !c.published_at ? 'text-ink' : 'font-semibold text-ink']">{{ c.title }}</Link><p class="text-xs text-muted">{{ c.author }}</p></td>
                        <td class="text-xs whitespace-nowrap">{{ date(c.published_at) }}</td>
                        <td>
                            <StatusBadge :status="c.status === 'published' ? 'published' : c.status === 'in_review' ? 'in_review' : c.status === 'draft' ? 'draft' : 'archived'" :label="c.status.replace('_', ' ')" />
                            <span v-if="c.requires_ack && c.status === 'published'" :class="['ml-1 inline-flex items-center gap-0.5 text-xs', c.acknowledged ? 'text-emerald-700' : 'text-amber-700']"><CheckCheck :size="12" aria-hidden="true" />{{ c.acknowledged ? 'acknowledged' : 'ack needed' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="circulars.links" :from="circulars.from" :to="circulars.to" :total="circulars.total" /></template>
        </DataTable>
    </AppLayout>
</template>
