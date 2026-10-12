<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { AlarmClock, CalendarClock, FilePlus, FolderOpen, Gavel, Search, Timer } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Row { id: number; registration_number: string; applicant_name: string; subject: string; status: string; outcome: string | null; life_liberty: boolean; appeal_status: string; workflow_instance_id: number; appeal_instance_id: number | null; transferred_to: string | null; pio: string | null; received_on: string; due_on: string; replied_on: string | null; days_left: number | null; late: boolean; appeal_due_on: string | null }
const props = defineProps<{ requests: Paginated<Row>; filters: Record<string, string>; stats: Record<string, number>; rules: Record<string, number> }>();

const f = reactive({ status: '', q: '', ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('rti.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const clock = (r: Row) => (r.days_left === null ? null : r.days_left < 0 ? `${-r.days_left} day(s) overdue` : r.days_left === 0 ? 'due today' : `${r.days_left} day(s) left`);
</script>

<template>
    <AppLayout title="RTI register">
        <PageHeader :title="'RTI register'" :description="`Reply within ${rules.reply_days} days (${rules.life_liberty_days} for life or liberty, +${rules.apio_extra_days} via APIO); first appeals within ${rules.appeal_days} days. Limits come from configuration and must be verified against the Act.`">
            <AppButton variant="outline" :icon="Gavel" :href="route('requests.create', 'rti-appeal')">First appeal</AppButton>
            <AppButton :icon="FilePlus" :href="route('requests.create', 'rti-request')">Register application</AppButton>
        </PageHeader>
        <div class="mb-6 grid gap-3 sm:grid-cols-5">
            <StatCard label="Open" :value="stats.open" :icon="FolderOpen" tone="brand" />
            <StatCard label="Overdue" :value="stats.overdue" :icon="AlarmClock" :tone="stats.overdue ? 'danger' : 'default'" :alert="!!stats.overdue" />
            <StatCard label="Due within 7 days" :value="stats.due_week" :icon="CalendarClock" tone="warning" />
            <StatCard label="Appeals pending" :value="stats.appeals_open" :icon="Gavel" />
            <StatCard label="Replied late" :value="stats.replied_late" :icon="Timer" />
        </div>
        <TabsNav v-model="f.status" :items="[{ key: '', label: 'All' }, { key: 'open', label: 'Open' }, { key: 'overdue', label: 'Overdue' }, { key: 'replied', label: 'Replied' }, { key: 'transferred', label: 'Transferred' }, { key: 'appeals', label: 'Appeals' }]" class="mb-4" />
        <DataTable :empty="!requests.data.length" empty-title="No RTI applications">
            <template #toolbar><div class="w-full max-w-sm"><TextInput v-model="f.q" :icon="Search" placeholder="Number, applicant or subject" aria-label="Search" /></div></template>
            <table class="data-table">
                <thead><tr><th>Number</th><th>Applicant / subject</th><th>PIO</th><th>Received</th><th>Reply due</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="r in requests.data" :key="r.id">
                        <td class="font-mono text-xs whitespace-nowrap"><Link :href="route('requests.show', r.workflow_instance_id)" class="text-brand-700 hover:underline">{{ r.registration_number }}</Link><p v-if="r.life_liberty" class="font-sans font-semibold text-red-700">life / liberty</p></td>
                        <td><span class="font-medium text-ink">{{ r.applicant_name }}</span><p class="text-xs text-muted">{{ r.subject }}</p></td>
                        <td class="text-sm">{{ r.pio ?? '—' }}</td>
                        <td class="text-xs">{{ r.received_on }}</td>
                        <td class="text-xs whitespace-nowrap">{{ r.due_on }}<p v-if="clock(r)" :class="['font-semibold', (r.days_left ?? 1) < 0 ? 'text-red-700' : (r.days_left ?? 99) <= 7 ? 'text-amber-700' : 'text-muted']">{{ clock(r) }}</p></td>
                        <td>
                            <StatusBadge :status="r.status === 'open' ? 'pending' : 'completed'" :label="r.status" />
                            <p v-if="r.replied_on" :class="['text-xs', r.late ? 'text-red-700' : 'text-muted']">{{ r.outcome }} · {{ r.replied_on }}{{ r.late ? ' (late)' : '' }}</p>
                            <p v-if="r.appeal_status !== 'none'" class="text-xs text-muted">appeal {{ r.appeal_status }}<template v-if="r.appeal_status === 'filed'"> · due {{ r.appeal_due_on }}</template></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" /></template>
        </DataTable>
    </AppLayout>
</template>
