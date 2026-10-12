<script setup lang="ts">
import FormField from '@/Components/FormField.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import type { InertiaForm } from '@inertiajs/vue3';

export interface AssetFields {
    asset_number: string;
    category: string;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    purchase_date: string | null;
    purchase_reference: string | null;
    cost: string | number | null;
    warranty_until: string | null;
    location: string | null;
    notes: string | null;
}
defineProps<{ form: InertiaForm<AssetFields>; categories: string[] }>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Asset number" :error="form.errors.asset_number" required><TextInput v-model="form.asset_number" /></FormField>
        <FormField label="Category" :error="form.errors.category" required><SelectInput v-model="form.category" :options="categories.map((c) => ({ value: c, label: c }))" /></FormField>
        <FormField label="Manufacturer" :error="form.errors.manufacturer"><TextInput v-model="form.manufacturer" /></FormField>
        <FormField label="Model" :error="form.errors.model"><TextInput v-model="form.model" /></FormField>
        <FormField label="Serial number" :error="form.errors.serial_number"><TextInput v-model="form.serial_number" /></FormField>
        <FormField label="Location" :error="form.errors.location"><TextInput v-model="form.location" /></FormField>
        <FormField label="Purchase date" :error="form.errors.purchase_date"><TextInput v-model="form.purchase_date" type="date" /></FormField>
        <FormField label="PO / invoice reference" :error="form.errors.purchase_reference"><TextInput v-model="form.purchase_reference" /></FormField>
        <FormField label="Cost (₹)" :error="form.errors.cost"><TextInput v-model="form.cost" type="number" step="0.01" min="0" /></FormField>
        <FormField label="Warranty until" :error="form.errors.warranty_until"><TextInput v-model="form.warranty_until" type="date" /></FormField>
        <FormField label="Notes" :error="form.errors.notes" class="sm:col-span-2"><TextArea v-model="form.notes" rows="2" /></FormField>
    </div>
</template>
