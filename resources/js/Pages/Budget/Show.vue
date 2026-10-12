<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { useForm } from '@inertiajs/vue3';
import { Landmark, Lock, Receipt, Wallet } from 'lucide-vue-next';

interface Entry { id: number; kind: string; amount: number; description: string; reference_type: string | null; reference_id: number | null; by: string | null; at: string }
defineProps<{ head: { id: number; code: string; name: string; financial_year: string; department: string | null; allocated: number; committed: number; spent: number; available: number }; entries: Entry[]; canManage: boolean }>();

const form = useForm({ kind: 'allocation', amount: '', description: '' });
const kinds: Record<string, string> = { allocation: 'Allocation', reappropriation: 'Re-appropriation', commitment: 'Commitment', commitment_release: 'Commitment released', expenditure: 'Expenditure' };
</script>

<template>
    <AppLayout :title="head.code">
        <PageHeader :title="head.name" :description="`${head.code} · FY ${head.financial_year} · ${head.department ?? 'Institute-wide'}`" :breadcrumbs="[{ label: 'Budget', href: route('budget.index') }, { label: head.code }]" />

        <div class="mb-6 grid gap-3 sm:grid-cols-4">
            <StatCard label="Allocated" :value="inr(head.allocated)" :icon="Landmark" />
            <StatCard label="Committed" :value="inr(head.committed)" :icon="Lock" tone="warning" />
            <StatCard label="Spent" :value="inr(head.spent)" :icon="Receipt" />
            <StatCard label="Available" :value="inr(head.available)" :icon="Wallet" />
        </div>

        <form v-if="canManage" class="card mb-6 grid gap-3 p-5 sm:grid-cols-6 sm:items-end" @submit.prevent="form.post(route('budget.post', head.id), { preserveScroll: true, onSuccess: () => form.reset('amount', 'description') })">
            <FormField label="Entry" :error="form.errors.kind"><SelectInput v-model="form.kind" :options="[{ value: 'allocation', label: 'Allocation' }, { value: 'reappropriation', label: 'Re-appropriation (±)' }]" /></FormField>
            <FormField label="Amount (₹)" :error="form.errors.amount" required><TextInput v-model="form.amount" type="number" step="0.01" /></FormField>
            <FormField label="Order / description" :error="form.errors.description" required class="sm:col-span-3"><TextInput v-model="form.description" /></FormField>
            <AppButton type="submit" :loading="form.processing">Post</AppButton>
        </form>

        <DataTable title="Ledger" description="Append-only. Corrections are posted as new entries." :empty="!entries.length" empty-title="No entries">
            <table class="data-table">
                <thead><tr><th>When</th><th>Entry</th><th>Description</th><th class="text-right">Amount</th><th>By</th></tr></thead>
                <tbody>
                    <tr v-for="e in entries" :key="e.id">
                        <td class="text-xs whitespace-nowrap">{{ e.at.slice(0, 16).replace('T', ' ') }}</td>
                        <td>{{ kinds[e.kind] ?? e.kind }}</td>
                        <td class="text-sm">{{ e.description }}</td>
                        <td :class="['text-right tabular-nums', e.amount < 0 ? 'text-red-700' : 'text-emerald-700']">{{ inr(e.amount) }}</td>
                        <td class="text-sm">{{ e.by ?? 'Workflow' }}</td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
