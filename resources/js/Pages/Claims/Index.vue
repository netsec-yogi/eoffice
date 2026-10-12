<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { HandCoins, Plane, ReceiptIndianRupee, Stethoscope, Wallet } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Journey { id: number; kind: string; destination: string; status: string; claim_status: string; advance_requested: number; advance_sanctioned: number | null; workflow_instance_id: number; from: string; to: string; user: string }
interface Claim { id: number; kind: string; status: string; claimed_amount: number; admissible_amount: number | null; advance_adjusted: number; net_payable: number | null; payment_reference: string | null; workflow_instance_id: number; paid_on: string | null; user: string; created_at: string }
const props = defineProps<{ register: boolean; filters: Record<string, string | boolean>; journeys: Journey[]; claims: Claim[]; totals: Record<string, number> | null }>();

const f = reactive({ kind: '', status: '', all: false, ...props.filters });
watch(f, () => router.get(route('claims.index'), Object.fromEntries(Object.entries({ ...f, all: f.all ? 1 : '' }).filter(([, v]) => v)), { preserveState: true, replace: true }));
const inr = (n: number | null) => (n === null ? '—' : `₹ ${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`);
const tone = (s: string) => ({ paid: 'paid', sanctioned: 'approved', pending: 'pending', rejected: 'rejected', cancelled: 'cancelled', approved: 'approved' })[s] ?? s;
</script>

<template>
    <AppLayout title="Travel & claims">
        <PageHeader title="Travel and claims" description="Tours, LTC and reimbursement claims. Advances are adjusted against claims automatically.">
            <AppButton variant="outline" :icon="Plane" :href="route('requests.create', 'tour')">Tour</AppButton>
            <AppButton variant="outline" :icon="Wallet" :href="route('requests.create', 'ltc')">LTC</AppButton>
            <AppButton variant="outline" :icon="Stethoscope" :href="route('requests.create', 'medical-claim')">Medical claim</AppButton>
            <AppButton :icon="ReceiptIndianRupee" :href="route('requests.create', 'tada-claim')">TA/DA claim</AppButton>
        </PageHeader>

        <div v-if="totals" class="mb-6 grid gap-3 sm:grid-cols-3">
            <StatCard label="Claims in process" :value="inr(totals.pending)" :icon="ReceiptIndianRupee" />
            <StatCard label="Sanctioned, awaiting payment" :value="inr(totals.payable)" :icon="HandCoins" tone="warning" />
            <StatCard label="Advances not yet settled" :value="inr(totals.advances_outstanding)" :icon="Wallet" />
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <SelectInput v-model="f.kind" :options="[{ value: 'tour', label: 'Tour / TA-DA' }, { value: 'ltc', label: 'LTC' }, { value: 'medical', label: 'Medical' }]" placeholder="All kinds" class="!w-48" aria-label="Kind" />
            <CheckboxInput v-if="register" v-model="f.all" label="Everyone's (register)" />
        </div>

        <DataTable :empty="!journeys.length" empty-title="No journeys" title="Journeys" class="mb-6">
            <table class="data-table">
                <thead><tr><th>Journey</th><th v-if="f.all">Person</th><th>Dates</th><th>Advance</th><th>Status</th><th>Claim</th></tr></thead>
                <tbody>
                    <tr v-for="j in journeys" :key="j.id">
                        <td><Link :href="route('requests.show', j.workflow_instance_id)" class="font-medium text-brand-700 hover:underline">{{ j.destination }}</Link><p class="text-xs text-muted uppercase">{{ j.kind }}</p></td>
                        <td v-if="f.all">{{ j.user }}</td>
                        <td class="text-xs">{{ j.from }} → {{ j.to }}</td>
                        <td class="text-sm">{{ inr(j.advance_sanctioned ?? j.advance_requested) }}<p v-if="j.advance_sanctioned === null && j.advance_requested" class="text-xs text-muted">requested</p></td>
                        <td><StatusBadge :status="tone(j.status)" :label="j.status" /></td>
                        <td><StatusBadge :status="j.claim_status === 'settled' ? 'paid' : j.claim_status === 'claimed' ? 'pending' : 'draft'" :label="j.claim_status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
        <DataTable :empty="!claims.length" empty-title="No claims" title="Claims">
            <table class="data-table">
                <thead><tr><th>Claim</th><th v-if="f.all">Person</th><th class="text-right">Claimed</th><th class="text-right">Admissible</th><th class="text-right">Advance</th><th class="text-right">Net payable</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="c in claims" :key="c.id">
                        <td><Link :href="route('requests.show', c.workflow_instance_id)" class="font-medium text-brand-700 uppercase hover:underline">{{ c.kind }}</Link><p v-if="c.payment_reference" class="text-xs text-muted">{{ c.payment_reference }} · {{ c.paid_on }}</p></td>
                        <td v-if="f.all">{{ c.user }}</td>
                        <td class="text-right tabular-nums">{{ inr(c.claimed_amount) }}</td>
                        <td class="text-right tabular-nums">{{ inr(c.admissible_amount) }}</td>
                        <td class="text-right tabular-nums">{{ inr(c.advance_adjusted) }}</td>
                        <td :class="['text-right font-semibold tabular-nums', (c.net_payable ?? 0) < 0 ? 'text-red-700' : 'text-ink']">{{ inr(c.net_payable) }}<p v-if="(c.net_payable ?? 0) < 0" class="text-xs font-normal">to be recovered</p></td>
                        <td><StatusBadge :status="tone(c.status)" :label="c.status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
