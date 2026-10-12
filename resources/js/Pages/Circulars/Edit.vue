<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    circular: { id: number; kind: string; title: string; body: string; requires_ack: boolean; ack_by: string | null; departments: number[]; categories: string[]; version: number } | null;
    kinds: Record<string, string>;
    departments: { id: number; code: string; name: string }[];
}>();
const form = useForm({
    kind: props.circular?.kind ?? 'circular', title: props.circular?.title ?? '', body: props.circular?.body ?? '',
    departments: props.circular?.departments ?? ([] as number[]), categories: props.circular?.categories ?? ['faculty', 'staff'],
    requires_ack: props.circular?.requires_ack ?? false, ack_by: props.circular?.ack_by ?? '',
});
const save = () => (props.circular ? form.put(route('circulars.update', props.circular.id)) : form.post(route('circulars.store')));
</script>

<template>
    <AppLayout :title="circular ? 'Edit draft' : 'New draft'">
        <PageHeader :title="circular ? `Edit draft${circular.version > 1 ? ` (version ${circular.version})` : ''}` : 'New circular / order'" description="A draft is visible only to you and the circulars office until it is approved and published." />
        <form class="card max-w-4xl space-y-4 p-6" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-4">
                <FormField label="Kind" :error="form.errors.kind" required><SelectInput v-model="form.kind" :options="Object.entries(kinds).map(([value, label]) => ({ value, label }))" /></FormField>
                <FormField label="Title" :error="form.errors.title" required class="sm:col-span-3"><TextInput v-model="form.title" /></FormField>
            </div>
            <FormField label="Text" :error="form.errors.body" required><TextArea v-model="form.body" rows="14" class="font-serif" /></FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Departments" hint="None selected = the whole institute (including sub-units of those selected)." :error="form.errors.departments"><MultiSelect v-model="form.departments" :options="departments.map((d) => ({ value: d.id, label: d.name }))" placeholder="Whole institute" /></FormField>
                <fieldset><legend class="mb-1.5 text-sm font-medium text-ink">Addressed to</legend><div class="flex gap-4"><CheckboxInput v-for="c in ['faculty', 'staff', 'student']" :key="c" v-model="form.categories" :value="c" :label="c" /></div>
                    <p v-if="form.errors.categories" class="mt-1 text-sm text-red-600">{{ form.errors.categories }}</p></fieldset>
            </div>
            <div class="flex flex-wrap items-end gap-4">
                <CheckboxInput v-model="form.requires_ack" label="Recipients must acknowledge" />
                <FormField v-if="form.requires_ack" label="Acknowledge by" :error="form.errors.ack_by"><TextInput v-model="form.ack_by" type="date" /></FormField>
            </div>
            <p v-if="(form.errors as Record<string, string>).workflow" class="text-sm text-red-600">{{ (form.errors as Record<string, string>).workflow }}</p>
            <div class="flex justify-end"><AppButton type="submit" :loading="form.processing">Save draft</AppButton></div>
        </form>
    </AppLayout>
</template>
