<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { Link } from '@inertiajs/vue3';
import { FileText, MessageSquareWarning, ReceiptIndianRupee } from 'lucide-vue-next';
import { computed } from 'vue';

interface Order { id: number; number: string; items: { description: string; specification?: string; qty: number; unit: string; unit_price: number }[]; total: number; status: string; document: string | null; deliver_by: string | null; issued_at: string }
interface Invoice { id: number; number: string; invoice_number: string; amount: number; order: string; invoice_date: string; status: string; clarification: string | null; edit_instance_id: number | null; deductions: number | null; net_payable: number | null; payment_reference: string | null; paid_on: string | null; payment_advice: string | null }
const props = defineProps<{ vendor: { code: string; name: string; gstin: string | null; email: string | null; account: string | null }; orders: Order[]; invoices: Invoice[]; canBill: boolean }>();

const asked = computed(() => props.invoices.filter((i) => i.status === 'clarification'));
const label: Record<string, string> = { submitted: 'Received', verified: 'Verified', sanctioned: 'Sanctioned', paid: 'Paid', rejected: 'Not accepted', clarification: 'Clarification needed' };
const tone: Record<string, string> = { submitted: 'submitted', verified: 'in_review', sanctioned: 'approved', paid: 'paid', rejected: 'rejected', clarification: 'pending' };
const orderLabel: Record<string, string> = { issued: 'Awaiting delivery', partly_received: 'Partly received', received: 'Received', invoiced: 'Billed', closed: 'Closed' };
</script>

<template>
    <AppLayout title="Vendor portal">
        <PageHeader :title="vendor.name" :description="`Vendor ${vendor.code}${vendor.gstin ? ` · GSTIN ${vendor.gstin}` : ''} · payments to account ${vendor.account ?? 'not recorded'}`" no-breadcrumbs>
            <AppButton :icon="ReceiptIndianRupee" :href="canBill ? route('requests.create', 'vendor-invoice') : undefined" :disabled="!canBill">Submit an invoice</AppButton>
        </PageHeader>
        <p v-if="!canBill" class="-mt-4 mb-6 text-sm text-muted">You can bill an order once the goods have been received and accepted.</p>

        <AlertBox v-for="i in asked" :key="i.id" tone="warning" :title="`Invoice ${i.invoice_number}: clarification requested`" class="mb-4">
            <p class="whitespace-pre-line">{{ i.clarification }}</p>
            <AppButton v-if="i.edit_instance_id" class="mt-3" size="sm" :icon="MessageSquareWarning" :href="route('requests.edit', i.edit_instance_id)">Respond</AppButton>
        </AlertBox>

        <DataTable title="Purchase orders" :empty="!orders.length" empty-title="No purchase orders yet" class="mb-6">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Items</th><th class="text-right">Total</th><th>Deliver by</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders" :key="o.id">
                        <td>
                            <a v-if="o.document" :href="route('documents.show', o.document)" class="inline-flex items-center gap-1 font-mono text-sm text-brand-700 hover:underline"><FileText :size="14" aria-hidden="true" />{{ o.number }}</a>
                            <span v-else class="font-mono text-sm">{{ o.number }}</span>
                            <p class="text-xs text-muted">issued {{ o.issued_at }}</p>
                        </td>
                        <td class="text-sm"><span v-for="(i, n) in o.items" :key="n" class="block">{{ i.qty }} {{ i.unit }} × {{ i.description }} @ {{ inr(i.unit_price) }}</span></td>
                        <td class="text-right tabular-nums">{{ inr(o.total) }}</td>
                        <td class="text-sm">{{ o.deliver_by ?? '—' }}</td>
                        <td><StatusBadge :status="o.status === 'issued' ? 'pending' : o.status" :label="orderLabel[o.status] ?? o.status" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <DataTable title="Invoices and payments" :empty="!invoices.length" empty-title="No invoices submitted">
            <table class="data-table">
                <thead><tr><th>Invoice</th><th>Order</th><th class="text-right">Amount</th><th class="text-right">Deductions</th><th class="text-right">Paid</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="i in invoices" :key="i.id">
                        <td><span class="font-mono text-sm">{{ i.invoice_number }}</span><p class="text-xs text-muted">{{ i.invoice_date }} · our ref. {{ i.number }}</p></td>
                        <td class="font-mono text-xs">{{ i.order }}</td>
                        <td class="text-right tabular-nums">{{ inr(i.amount) }}</td>
                        <td class="text-right tabular-nums">{{ inr(i.deductions) }}</td>
                        <td class="text-right tabular-nums">
                            {{ inr(i.net_payable) }}
                            <p v-if="i.payment_reference" class="text-xs text-muted">{{ i.paid_on }} · {{ i.payment_reference }}</p>
                            <a v-if="i.payment_advice" :href="route('documents.show', i.payment_advice)" class="text-xs text-brand-700 hover:underline">Payment advice</a>
                        </td>
                        <td>
                            <StatusBadge :status="tone[i.status] ?? i.status" :label="label[i.status] ?? i.status" />
                            <Link v-if="i.edit_instance_id" :href="route('requests.edit', i.edit_instance_id)" class="ml-2 text-xs text-brand-700 hover:underline">Respond</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
