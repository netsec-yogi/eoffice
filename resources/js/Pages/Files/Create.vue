<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { classLabel } from './classification';

const props = defineProps<{
    categories: { id: number; code: string; name: string; description: string | null; numbering_pattern: string; default_classification: string; retention: string | null }[];
    departments: { id: number; code: string; name: string }[];
    defaultDepartment: number | null;
}>();
const form = useForm({ subject: '', description: '', file_category_id: null as number | null, department_id: props.defaultDepartment, classification: 'internal' });
const category = computed(() => props.categories.find((c) => c.id === form.file_category_id));
watch(category, (c) => c && (form.classification = c.default_classification));
const preview = computed(() => {
    const dept = props.departments.find((d) => d.id === form.department_id)?.code ?? 'DEPT';
    return category.value?.numbering_pattern.replace('{DEPT}', dept).replace('{CAT}', category.value.code).replace('{YYYY}', String(new Date().getFullYear())).replace('{YY}', String(new Date().getFullYear()).slice(-2)).replace(/\{SEQ(:\d+)?\}/, 'n');
});
const hints: Record<string, string> = {
    public: 'Any member of staff can read it.',
    internal: 'Members of the owning department can read it, and everyone it is sent to.',
    confidential: 'Only people it is sent to or given access can read it. Viewing is logged.',
    restricted: 'Only people it is sent to or given access can read it. Viewing is logged.',
};
</script>

<template>
    <AppLayout title="Open a file">
        <PageHeader title="Open a file" description="The file number is assigned from the category's numbering rule when you open it." />
        <form class="card max-w-3xl space-y-4 p-6" @submit.prevent="form.post(route('efiles.store'))">
            <FormField label="Subject" :error="form.errors.subject" required><TextInput v-model="form.subject" maxlength="500" /></FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Category" :hint="category ? `${category.description ?? ''} Retention: ${category.retention ?? 'not set'}` : undefined" :error="form.errors.file_category_id" required>
                    <SelectInput v-model="form.file_category_id" :options="categories.map((c) => ({ value: c.id, label: c.name }))" placeholder="Choose…" />
                </FormField>
                <FormField label="Department" :error="form.errors.department_id" required><SelectInput v-model="form.department_id" :options="departments.map((d) => ({ value: d.id, label: d.name }))" /></FormField>
                <FormField label="Classification" :hint="hints[form.classification]" :error="form.errors.classification" required>
                    <SelectInput v-model="form.classification" :options="Object.entries(classLabel).map(([value, label]) => ({ value, label }))" />
                </FormField>
                <FormField label="File number will look like"><p class="flex h-10 items-center font-mono text-sm text-muted">{{ preview ?? '—' }}</p></FormField>
            </div>
            <FormField label="Description" :error="form.errors.description"><TextArea v-model="form.description" rows="3" /></FormField>
            <div class="flex justify-end"><AppButton type="submit" :loading="form.processing">Open file</AppButton></div>
        </form>
    </AppLayout>
</template>
