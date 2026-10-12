<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { Link, router } from '@inertiajs/vue3';
import { PackageCheck, Scale, Search, ShoppingCart } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Row { id: number; number: string; title: string; estimated_value: number; committed_amount: number; method: string; method_label: string; status: string; workflow_instance_id: number; department: string; requester: string; budget_head: string; created_at: string }
const props = defineProps<{ filters: Record<string, string>; register: boolean; cases: Row[] }>();

const f = reactive({ status: '', q: '', ...props.filters });
let timer: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('procurement.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const statuses = ['indent', 'budgeted', 'sourcing', 'evaluated', 'ordered', 'received', 'closed', 'rejected', 'cancelled'].map((s) => ({ value: s, label: s }));
const tone = (s: string) => ({ indent: 'submitted', budgeted: 'pending', sourcing: 'in_review', evaluated: 'in_review', ordered: 'approved', received: 'accepted' })[s] ?? s;
</script>

<template>
    <AppLayout title="Purchases">
        <PageHeader :title="register ? 'Purchase register' : 'My purchases'" description="Indents, the procurement method the published rules prescribe, orders, receipts and bills.">
            <AppButton variant="outline" :icon="Scale" :href="route('procurement.rules')" v-if="register">Procurement rules</AppButton>
            <AppButton variant="outline" :icon="PackageCheck" :href="route('requests.create', 'goods-receipt')">Record a delivery</AppButton>
            <AppButton :icon="ShoppingCart" :href="route('requests.create', 'purchase-indent')">New indent</AppButton>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <TextInput v-model="f.q" :icon="Search" placeholder="Number or title" class="!w-64" aria-label="Search" />
            <SelectInput v-model="f.status" :options="statuses" placeholder="All statuses" class="!w-48" aria-label="Status" />
        </div>

        <DataTable :empty="!cases.length" empty-title="No purchase cases" empty-description="An indent opens a case once it is submitted.">
            <table class="data-table">
                <thead><tr><th>Case</th><th v-if="register">Department</th><th>Method</th><th>Head</th><th class="text-right">Estimate</th><th class="text-right">Committed</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="c in cases" :key="c.id">
                        <td>
                            <Link :href="route('procurement.show', c.id)" class="font-medium text-brand-700 hover:underline">{{ c.title }}</Link>
                            <p class="font-mono text-xs text-muted">{{ c.number }}<span v-if="register"> · {{ c.requester }}</span></p>
                        </td>
                        <td v-if="register">{{ c.department }}</td>
                        <td class="text-sm">{{ c.method_label }}</td>
                        <td class="font-mono text-xs">{{ c.budget_head }}</td>
                        <td class="text-right tabular-nums">{{ inr(c.estimated_value) }}</td>
                        <td class="text-right tabular-nums">{{ inr(c.committed_amount) }}</td>
                        <td><StatusBadge :status="tone(c.status)" :label="c.status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
