<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { Link, router } from '@inertiajs/vue3';
import { AlarmClock, Banknote, HandCoins, ReceiptText } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Row { id: number; purpose: string; amount_requested: number; amount_sanctioned: number | null; status: string; disbursement_reference: string | null; spent: number | null; balance: number | null; workflow_instance_id: number; settlement_instance_id: number | null; settle_by: string | null; overdue: boolean; user: string }
const props = defineProps<{ register: boolean; filters: Record<string, string | boolean>; advances: Row[]; totals: { outstanding: number; overdue: number; overdue_count: number } | null }>();

const f = reactive({ status: '', all: false, ...props.filters });
watch(f, () => router.get(route('advances.index'), Object.fromEntries(Object.entries({ ...f, all: f.all ? 1 : '' }).filter(([, v]) => v)), { preserveState: true, replace: true }));
const statuses = ['requested', 'sanctioned', 'disbursed', 'overdue', 'settled', 'rejected', 'cancelled'].map((s) => ({ value: s, label: s }));
const tone = (r: Row) => (r.overdue ? 'failed' : ({ requested: 'pending', sanctioned: 'approved', disbursed: 'in_review', settled: 'paid' } as Record<string, string>)[r.status] ?? r.status);
</script>

<template>
    <AppLayout title="Advances">
        <PageHeader title="Advances" description="Advances for official expenses and their settlement. A positive balance is refundable by the holder; a negative one is payable to them.">
            <AppButton variant="outline" :icon="ReceiptText" :href="route('requests.create', 'advance-settlement')">Settle an advance</AppButton>
            <AppButton :icon="HandCoins" :href="route('requests.create', 'advance')">Request an advance</AppButton>
        </PageHeader>

        <div v-if="totals" class="mb-6 grid gap-3 sm:grid-cols-2">
            <StatCard label="Outstanding (disbursed, not settled)" :value="inr(totals.outstanding)" :icon="Banknote" />
            <StatCard :label="`Overdue (${totals.overdue_count})`" :value="inr(totals.overdue)" :icon="AlarmClock" :tone="totals.overdue_count ? 'danger' : undefined" />
        </div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <SelectInput v-model="f.status" :options="statuses" placeholder="All statuses" class="!w-48" aria-label="Status" />
            <CheckboxInput v-if="register" v-model="f.all" label="Everyone's (register)" />
        </div>

        <DataTable :empty="!advances.length" empty-title="No advances">
            <table class="data-table">
                <thead><tr><th>Purpose</th><th v-if="f.all">Holder</th><th class="text-right">Sanctioned</th><th>Settle by</th><th class="text-right">Spent</th><th class="text-right">Balance</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="a in advances" :key="a.id">
                        <td><Link :href="route('requests.show', a.workflow_instance_id)" class="font-medium text-brand-700 hover:underline">{{ a.purpose }}</Link><p v-if="a.disbursement_reference" class="text-xs text-muted">{{ a.disbursement_reference }}</p></td>
                        <td v-if="f.all">{{ a.user }}</td>
                        <td class="text-right tabular-nums">{{ inr(a.amount_sanctioned ?? a.amount_requested) }}<p v-if="a.amount_sanctioned === null" class="text-xs text-muted">requested</p></td>
                        <td :class="['text-sm', a.overdue && 'font-semibold text-red-700']">{{ a.settle_by ?? '—' }}</td>
                        <td class="text-right tabular-nums">{{ inr(a.spent) }}</td>
                        <td class="text-right tabular-nums">{{ inr(a.balance) }}<p v-if="a.balance" class="text-xs text-muted">{{ a.balance > 0 ? 'to refund' : 'payable to holder' }}</p></td>
                        <td><StatusBadge :status="tone(a)" :label="a.overdue ? 'overdue' : a.status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
