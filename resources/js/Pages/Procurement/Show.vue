<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { Link, useForm } from '@inertiajs/vue3';
import { ExternalLink, FileText, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

interface Item { description: string; specification?: string; qty: number; unit: string; est_unit_price: number; amount: number }
interface Quote { id: number; vendor: string; vendor_code: string; amount: number; qualified: boolean; remarks: string | null; rank: string }
interface Case {
    id: number; number: string; title: string; items: Item[]; estimated_value: number; committed_amount: number; method: string; method_label: string; quotations_required: number; committee_required: boolean;
    gem_status: string | null; gem_reference: string | null; tender_reference: string | null; selected_quotation_id: number | null; status: string; workflow_instance_id: number;
    decision: { rule_version: number; label: string; value: number; authority: string | null }; department: string; requester: string;
    budget_head: { id: number; code: string; name: string; financial_year: string; available: number | null };
}
const props = defineProps<{
    purchase: Case; comparative: Quote[]; gaps: string[];
    orders: { id: number; number: string; total: number; status: string; document: string | null; vendor: string; deliver_by: string | null }[];
    receipts: { id: number; number: string; accepted_value: number; status: string; workflow_instance_id: number; received_on: string }[];
    invoices: { id: number; number: string; invoice_number: string; amount: number; deductions: number; net_payable: number | null; status: string; payment_reference: string | null; workflow_instance_id: number; paid_on: string | null }[];
    canSource: boolean; vendors: { value: number; label: string }[];
}>();

const sourcing = useForm({ gem_status: props.purchase.gem_status ?? '', gem_reference: props.purchase.gem_reference ?? '', tender_reference: props.purchase.tender_reference ?? '' });
const quote = useForm({ vendor_id: null as number | null, amount: '', technically_qualified: true, remarks: '' });
const choose = useForm({ quotation_id: null as number | null, justification: '' });
const opts = { preserveScroll: true };
const notL1 = computed(() => choose.quotation_id !== null && props.comparative.find((q) => q.id === choose.quotation_id)?.rank !== 'L1');
const tender = computed(() => ['limited_tender', 'open_tender'].includes(props.purchase.method));
</script>

<template>
    <AppLayout :title="purchase.number">
        <PageHeader :title="purchase.title" :description="`${purchase.number} · ${purchase.department} · raised by ${purchase.requester}`" :breadcrumbs="[{ label: 'Purchases', href: route('procurement.index') }, { label: purchase.number }]">
            <AppButton variant="outline" :icon="ExternalLink" :href="route('requests.show', purchase.workflow_instance_id)">Indent and approvals</AppButton>
        </PageHeader>

        <div class="mb-6 grid gap-4 lg:grid-cols-3">
            <section class="card p-5 lg:col-span-2">
                <h2 class="font-semibold text-ink">Procurement method</h2>
                <p class="mt-2 text-lg font-semibold text-ink">{{ purchase.method_label }}</p>
                <p class="text-sm text-muted">
                    Rule set version {{ purchase.decision.rule_version }}, band “{{ purchase.decision.label }}”, applied to {{ inr(purchase.decision.value) }}.
                    {{ purchase.quotations_required ? `${purchase.quotations_required} qualified quotation(s) required.` : 'No quotations required.' }}
                    {{ purchase.committee_required ? 'Purchase committee required.' : '' }}
                </p>
                <p v-if="purchase.decision.authority" class="mt-2 text-xs text-muted">Authority: {{ purchase.decision.authority }}</p>
            </section>
            <section class="card p-5">
                <h2 class="font-semibold text-ink">Budget</h2>
                <p class="mt-2 font-mono text-sm">{{ purchase.budget_head.code }} ({{ purchase.budget_head.financial_year }})</p>
                <p class="text-sm text-muted">{{ purchase.budget_head.name }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-1 text-sm">
                    <dt class="text-muted">Committed</dt><dd class="text-right tabular-nums">{{ inr(purchase.committed_amount) }}</dd>
                    <template v-if="purchase.budget_head.available !== null"><dt class="text-muted">Head available</dt><dd class="text-right tabular-nums">{{ inr(purchase.budget_head.available) }}</dd></template>
                </dl>
                <StatusBadge class="mt-3" :status="purchase.status" />
            </section>
        </div>

        <DataTable title="Items" class="mb-6">
            <table class="data-table">
                <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Est. unit price</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                    <tr v-for="(i, n) in purchase.items" :key="n">
                        <td><span class="font-medium text-ink">{{ i.description }}</span><p v-if="i.specification" class="text-xs text-muted">{{ i.specification }}</p></td>
                        <td class="text-right tabular-nums">{{ i.qty }} {{ i.unit }}</td>
                        <td class="text-right tabular-nums">{{ inr(i.est_unit_price) }}</td>
                        <td class="text-right tabular-nums">{{ inr(i.amount) }}</td>
                    </tr>
                    <tr><td colspan="3" class="text-right font-medium">Estimate</td><td class="text-right font-semibold tabular-nums">{{ inr(purchase.estimated_value) }}</td></tr>
                </tbody>
            </table>
        </DataTable>

        <AlertBox v-if="gaps.length" tone="warning" title="Before the evaluation can be completed" class="mb-6">
            <ul class="list-disc pl-5"><li v-for="g in gaps" :key="g">{{ g }}</li></ul>
        </AlertBox>

        <section v-if="canSource" class="card mb-6 p-6">
            <h2 class="mb-4 font-semibold text-ink">Sourcing</h2>
            <form class="grid gap-3 sm:grid-cols-4 sm:items-end" @submit.prevent="sourcing.post(route('procurement.sourcing', purchase.id), opts)">
                <FormField label="Available on GeM?" :error="sourcing.errors.gem_status" required>
                    <SelectInput v-model="sourcing.gem_status" :options="[{ value: 'available', label: 'Available' }, { value: 'not_available', label: 'Not available' }, { value: 'not_applicable', label: 'Not applicable' }]" />
                </FormField>
                <FormField label="GeM reference" :error="sourcing.errors.gem_reference"><TextInput v-model="sourcing.gem_reference" /></FormField>
                <FormField v-if="tender" label="Tender reference" :error="sourcing.errors.tender_reference"><TextInput v-model="sourcing.tender_reference" /></FormField>
                <AppButton type="submit" variant="outline" :loading="sourcing.processing">Save</AppButton>
            </form>
        </section>

        <DataTable title="Comparative statement" description="Technically qualified quotations, lowest first." :empty="!comparative.length" empty-title="No quotations recorded" class="mb-4">
            <table class="data-table">
                <thead><tr><th>Rank</th><th>Vendor</th><th class="text-right">Quoted (incl. taxes)</th><th>Qualified</th><th>Remarks</th><th v-if="canSource"><span class="sr-only">Select</span></th></tr></thead>
                <tbody>
                    <tr v-for="q in comparative" :key="q.id" :class="q.id === purchase.selected_quotation_id && 'bg-brand-50/60 dark:bg-brand-500/10'">
                        <td class="font-semibold">{{ q.rank }}</td>
                        <td>{{ q.vendor }} <span class="font-mono text-xs text-muted">{{ q.vendor_code }}</span></td>
                        <td class="text-right tabular-nums">{{ inr(q.amount) }}</td>
                        <td><StatusBadge :status="q.qualified ? 'accepted' : 'rejected'" :label="q.qualified ? 'yes' : 'no'" /></td>
                        <td class="text-sm">{{ q.remarks }}</td>
                        <td v-if="canSource" class="text-right">
                            <span v-if="q.id === purchase.selected_quotation_id" class="text-sm font-medium text-brand-700">Selected</span>
                            <AppButton v-else-if="q.qualified" size="sm" variant="ghost" @click="choose.quotation_id = q.id">Select</AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <form v-if="canSource && choose.quotation_id" class="card mb-6 space-y-3 p-5" @submit.prevent="choose.post(route('procurement.select', purchase.id), { ...opts, onSuccess: () => choose.reset() })">
            <p class="text-sm">Select <strong>{{ comparative.find((q) => q.id === choose.quotation_id)?.vendor }}</strong>.</p>
            <FormField v-if="notL1" label="Justification (not the lowest qualified quotation)" :error="choose.errors.justification" required><TextArea v-model="choose.justification" rows="2" /></FormField>
            <div class="flex gap-2"><AppButton type="submit" :loading="choose.processing">Confirm selection</AppButton><AppButton variant="ghost" @click="choose.reset()">Cancel</AppButton></div>
        </form>

        <form v-if="canSource" class="card mb-6 grid gap-3 p-5 sm:grid-cols-6 sm:items-end" @submit.prevent="quote.post(route('procurement.quotations.store', purchase.id), { ...opts, onSuccess: () => quote.reset() })">
            <FormField label="Vendor" :error="quote.errors.vendor_id" required class="sm:col-span-2"><SelectInput v-model="quote.vendor_id" :options="vendors" placeholder="Choose" /></FormField>
            <FormField label="Amount incl. taxes (₹)" :error="quote.errors.amount" required><TextInput v-model="quote.amount" type="number" step="0.01" min="0" /></FormField>
            <FormField label="Remarks" :error="quote.errors.remarks" class="sm:col-span-2"><TextInput v-model="quote.remarks" /></FormField>
            <div class="flex flex-col gap-2">
                <CheckboxInput v-model="quote.technically_qualified" label="Meets specification" />
                <AppButton type="submit" :icon="Plus" :loading="quote.processing">Add quotation</AppButton>
            </div>
        </form>

        <div class="grid gap-6 lg:grid-cols-2">
            <DataTable title="Purchase orders" :empty="!orders.length" empty-title="No order yet">
                <table class="data-table">
                    <thead><tr><th>Order</th><th>Vendor</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id">
                            <td><a v-if="o.document" :href="route('documents.show', o.document)" class="inline-flex items-center gap-1 font-mono text-sm text-brand-700 hover:underline"><FileText :size="14" aria-hidden="true" />{{ o.number }}</a><span v-else class="font-mono text-sm">{{ o.number }}</span><p class="text-xs text-muted">deliver by {{ o.deliver_by ?? '—' }}</p></td>
                            <td>{{ o.vendor }}</td>
                            <td class="text-right tabular-nums">{{ inr(o.total) }}</td>
                            <td><StatusBadge :status="o.status" /></td>
                        </tr>
                    </tbody>
                </table>
            </DataTable>
            <DataTable title="Receipts and bills" :empty="!receipts.length && !invoices.length" empty-title="Nothing received or billed yet">
                <table class="data-table">
                    <thead><tr><th>Record</th><th class="text-right">Value</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="g in receipts" :key="`g${g.id}`"><td><Link :href="route('requests.show', g.workflow_instance_id)" class="font-mono text-sm text-brand-700 hover:underline">{{ g.number }}</Link><p class="text-xs text-muted">received {{ g.received_on }}</p></td><td class="text-right tabular-nums">{{ inr(g.accepted_value) }}</td><td><StatusBadge :status="g.status" /></td></tr>
                        <tr v-for="i in invoices" :key="`i${i.id}`"><td><Link :href="route('requests.show', i.workflow_instance_id)" class="font-mono text-sm text-brand-700 hover:underline">{{ i.number }}</Link><p class="text-xs text-muted">invoice {{ i.invoice_number }}<span v-if="i.payment_reference"> · paid {{ i.paid_on }} ({{ i.payment_reference }})</span></p></td><td class="text-right tabular-nums">{{ inr(i.amount) }}<p v-if="i.deductions" class="text-xs text-muted">net {{ inr(i.net_payable) }}</p></td><td><StatusBadge :status="i.status" /></td></tr>
                    </tbody>
                </table>
            </DataTable>
        </div>
    </AppLayout>
</template>
