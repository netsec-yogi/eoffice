<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ forms: { id: number; key: string; name: string; description: string | null; department: string | null; published: number | null; draft: number | null }[]; departments: { id: number; code: string; name: string }[] }>();
const open = ref(false);
const form = useForm({ key: '', name: '', description: '', department_id: null as number | null });
</script>

<template>
    <AppLayout title="Forms">
        <PageHeader title="Forms" description="Application forms built without programming. Published versions never change; each request keeps the version it was submitted on.">
            <AppButton :icon="Plus" @click="open = true">Form</AppButton>
        </PageHeader>
        <DataTable :empty="!forms.length" empty-title="No forms yet">
            <table class="data-table">
                <thead><tr><th>Form</th><th>Department</th><th>Published</th><th>Draft</th></tr></thead>
                <tbody>
                    <tr v-for="f in forms" :key="f.id">
                        <td><Link :href="route('admin.forms.edit', f.id)" class="font-medium text-ink hover:text-brand-700">{{ f.name }}</Link><p class="font-mono text-xs text-muted">{{ f.key }}</p></td>
                        <td>{{ f.department ?? '—' }}</td>
                        <td><template v-if="f.published">v{{ f.published }}</template><StatusBadge v-else status="draft" label="never published" /></td>
                        <td>{{ f.draft ? `v${f.draft}` : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
        <ModalDialog :show="open" title="New form" @close="open = false">
            <form class="space-y-4" @submit.prevent="form.post(route('admin.forms.store'))">
                <FormField label="Key" hint="e.g. it_email_request" :error="form.errors.key" required><TextInput v-model="form.key" /></FormField>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                <FormField label="Description" :error="form.errors.description"><TextArea v-model="form.description" rows="2" /></FormField>
                <FormField label="Department" :error="form.errors.department_id"><SelectInput v-model="form.department_id" :options="departments.map((d) => ({ value: d.id, label: d.name }))" placeholder="Institute" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Create</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
