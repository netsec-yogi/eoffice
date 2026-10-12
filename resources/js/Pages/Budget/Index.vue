<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { inr } from '@/lib/money';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Landmark, Lock, Plus, Receipt, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Head { id: number; code: string; name: string; financial_year: string; is_active: boolean; department: string | null; allocated: number; committed: number; spent: number; available: number }
const props = defineProps<{ year: string; years: string[]; heads: Head[]; departments: { value: number; label: string }[]; canManage: boolean }>();

const sum = (k: keyof Head) => props.heads.reduce((t, h) => t + Number(h[k]), 0);
const totals = computed(() => ({ allocated: sum('allocated'), committed: sum('committed'), spent: sum('spent'), available: sum('available') }));
const open = ref(false);
const form = useForm({ code: '', name: '', financial_year: props.year, department_id: null as number | null });
const pct = (h: Head) => (h.allocated > 0 ? Math.min(100, ((h.committed + h.spent) / h.allocated) * 100) : 0);
</script>

<template>
    <AppLayout title="Budget">
        <PageHeader title="Budget" description="Allocations, commitments from purchase orders and expenditure from paid bills. Every figure is a sum of the head's ledger.">
            <SelectInput :model-value="year" :options="years.map((y) => ({ value: y, label: `FY ${y}` }))" class="!w-36" aria-label="Financial year" @update:model-value="router.get(route('budget.index'), { year: $event })" />
            <AppButton v-if="canManage" :icon="Plus" @click="open = true">Budget head</AppButton>
        </PageHeader>

        <div class="mb-6 grid gap-3 sm:grid-cols-4">
            <StatCard label="Allocated" :value="inr(totals.allocated)" :icon="Landmark" />
            <StatCard label="Committed" :value="inr(totals.committed)" :icon="Lock" tone="warning" />
            <StatCard label="Spent" :value="inr(totals.spent)" :icon="Receipt" />
            <StatCard label="Available" :value="inr(totals.available)" :icon="Wallet" />
        </div>

        <DataTable :empty="!heads.length" empty-title="No budget heads for this year">
            <table class="data-table">
                <thead><tr><th>Head</th><th>Dept</th><th class="text-right">Allocated</th><th class="text-right">Committed</th><th class="text-right">Spent</th><th class="text-right">Available</th><th class="w-40">Used</th></tr></thead>
                <tbody>
                    <tr v-for="h in heads" :key="h.id" :class="!h.is_active && 'opacity-60'">
                        <td><Link :href="route('budget.show', h.id)" class="font-medium text-brand-700 hover:underline">{{ h.name }}</Link><p class="font-mono text-xs text-muted">{{ h.code }}</p></td>
                        <td>{{ h.department ?? 'Institute' }}</td>
                        <td class="text-right tabular-nums">{{ inr(h.allocated) }}</td>
                        <td class="text-right tabular-nums">{{ inr(h.committed) }}</td>
                        <td class="text-right tabular-nums">{{ inr(h.spent) }}</td>
                        <td :class="['text-right font-semibold tabular-nums', h.available < 0 ? 'text-red-700' : 'text-ink']">{{ inr(h.available) }}</td>
                        <td>
                            <div class="h-2 rounded-full bg-surface-sunken" role="meter" :aria-valuenow="Math.round(pct(h))" aria-valuemin="0" aria-valuemax="100" :aria-label="`${h.code} used`">
                                <div :class="['h-2 rounded-full', pct(h) > 90 ? 'bg-red-500' : 'bg-brand-500']" :style="{ width: `${pct(h)}%` }" />
                            </div>
                            <span class="text-xs text-muted">{{ Math.round(pct(h)) }}%</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="open" title="New budget head" @close="open = false">
            <form class="space-y-4" @submit.prevent="form.post(route('budget.store'), { onSuccess: () => ((open = false), form.reset()) })">
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="Code" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Name" :error="form.errors.name" required class="sm:col-span-2"><TextInput v-model="form.name" /></FormField>
                    <FormField label="Financial year" :error="form.errors.financial_year" required><TextInput v-model="form.financial_year" placeholder="2026-27" /></FormField>
                    <FormField label="Department (blank = institute-wide)" :error="form.errors.department_id" class="sm:col-span-2"><SelectInput v-model="form.department_id" :options="departments" placeholder="Institute-wide" /></FormField>
                </div>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Add</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
