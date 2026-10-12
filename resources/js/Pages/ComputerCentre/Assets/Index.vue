<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Search } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
import AssetForm, { type AssetFields } from './AssetForm.vue';

interface Row { id: number; asset_number: string; category: string; manufacturer: string | null; model: string | null; serial_number: string | null; location: string | null; status: string; warranty_until: string | null; custodian: { name: string } | null }
const props = defineProps<{ assets: Paginated<Row>; filters: Record<string, string>; categories: string[]; counts: Record<string, number> }>();

const f = reactive({ q: '', status: '', category: '', warranty: '', ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('cc.assets.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const open = ref(false);
const form = useForm<AssetFields>({ asset_number: '', category: 'laptop', manufacturer: null, model: null, serial_number: null, purchase_date: null, purchase_reference: null, cost: null, warranty_until: null, location: null, notes: null });
const label = (s: string) => s.replace('_', ' ');
const warrantyGone = (d: string | null) => !!d && new Date(d) < new Date();
</script>

<template>
    <AppLayout title="IT assets">
        <PageHeader title="IT asset register" :description="`${counts.issued ?? 0} issued · ${counts.in_stock ?? 0} in stock · ${counts.repair ?? 0} in repair · ${counts.disposed ?? 0} disposed`">
            <AppButton :icon="Plus" @click="open = true">Register asset</AppButton>
        </PageHeader>
        <DataTable :empty="!assets.data.length" empty-title="No assets match">
            <template #toolbar>
                <div class="grid w-full gap-2 sm:grid-cols-4">
                    <TextInput v-model="f.q" :icon="Search" placeholder="Number, serial, model, custodian" aria-label="Search" />
                    <SelectInput v-model="f.status" :options="['in_stock', 'issued', 'repair', 'disposed'].map((s) => ({ value: s, label: label(s) }))" placeholder="Any status" aria-label="Status" />
                    <SelectInput v-model="f.category" :options="categories.map((c) => ({ value: c, label: c }))" placeholder="Any category" aria-label="Category" />
                    <SelectInput v-model="f.warranty" :options="[{ value: 'expiring', label: 'Warranty ends ≤ 60 days' }, { value: 'expired', label: 'Warranty expired' }]" placeholder="Any warranty" aria-label="Warranty" />
                </div>
            </template>
            <table class="data-table">
                <thead><tr><th>Asset</th><th>Category</th><th>Make / model</th><th>Serial</th><th>Custodian</th><th>Warranty</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="a in assets.data" :key="a.id">
                        <td class="font-mono text-xs"><Link :href="route('cc.assets.show', a.id)" class="text-brand-700 hover:underline">{{ a.asset_number }}</Link></td>
                        <td class="capitalize">{{ a.category }}</td>
                        <td>{{ [a.manufacturer, a.model].filter(Boolean).join(' ') || '—' }}</td>
                        <td class="font-mono text-xs">{{ a.serial_number ?? '—' }}</td>
                        <td>{{ a.custodian?.name ?? (a.location ? `Store: ${a.location}` : '—') }}</td>
                        <td :class="['text-xs', warrantyGone(a.warranty_until) && 'text-red-700']">{{ a.warranty_until ?? '—' }}</td>
                        <td><StatusBadge :status="a.status === 'in_stock' ? 'active' : a.status === 'issued' ? 'in_review' : a.status === 'repair' ? 'pending' : 'archived'" :label="label(a.status)" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="assets.links" :from="assets.from" :to="assets.to" :total="assets.total" /></template>
        </DataTable>
        <ModalDialog :show="open" title="Register asset" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="form.post(route('cc.assets.store'))">
                <AssetForm :form="form" :categories="categories" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Register</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
