<script setup lang="ts">
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { date } from '@/Workflow/format';
import { Link } from '@inertiajs/vue3';

export interface Balance { code: string; name: string; has_balance: boolean; balance: number | null; pending: number; available: number | null }
export interface LeaveRow { id: number; type: string; from: string; to: string; days: number; status: string; instance: number }
export interface LedgerRow { id: number; type: string; kind: string; days: number; reason: string; by: string; at: string; instance: number | null }
defineProps<{ balances: Balance[]; records: LeaveRow[]; ledger: LedgerRow[] }>();
const tone = (s: string) => ({ approved: 'approved', pending: 'pending', rejected: 'rejected', cancelled: 'cancelled', curtailed: 'closed' })[s] ?? s;
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="b in balances" :key="b.code" class="card p-4">
                <p class="text-sm font-medium text-muted">{{ b.name }}</p>
                <template v-if="b.has_balance">
                    <p class="mt-1 text-3xl font-bold text-ink tabular-nums">{{ b.available }} <span class="text-sm font-normal text-muted">days available</span></p>
                    <p class="text-xs text-muted">Balance {{ b.balance }}<template v-if="b.pending"> · {{ b.pending }} pending</template></p>
                </template>
                <p v-else class="mt-1 text-sm text-muted">No balance kept<template v-if="b.pending"> · {{ b.pending }} pending</template></p>
            </div>
        </div>
        <DataTable :empty="!records.length" empty-title="No leave yet" title="Leave">
            <table class="data-table">
                <thead><tr><th>Kind</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="r in records" :key="r.id">
                        <td><Link :href="route('requests.show', r.instance)" class="font-medium text-brand-700 hover:underline">{{ r.type }}</Link></td>
                        <td>{{ r.from }}</td><td>{{ r.to }}</td><td class="tabular-nums">{{ r.days }}</td>
                        <td><StatusBadge :status="tone(r.status)" :label="r.status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
        <DataTable :empty="!ledger.length" empty-title="No ledger entries" title="Ledger" description="Every credit and debit. Balances are the sum of these entries.">
            <table class="data-table">
                <thead><tr><th>Date</th><th>Kind</th><th>Entry</th><th class="text-right">Days</th><th>By</th></tr></thead>
                <tbody>
                    <tr v-for="e in ledger" :key="e.id">
                        <td class="text-xs whitespace-nowrap">{{ date(e.at) }}</td>
                        <td class="font-mono text-xs">{{ e.type }}</td>
                        <td>{{ e.kind }} · {{ e.reason }}</td>
                        <td :class="['text-right tabular-nums', e.days < 0 ? 'text-red-700' : 'text-emerald-700']">{{ e.days > 0 ? '+' : '' }}{{ e.days }}</td>
                        <td class="text-xs">{{ e.by }}</td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </div>
</template>
