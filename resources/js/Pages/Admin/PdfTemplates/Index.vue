<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FileUpload from '@/Components/FileUpload.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Upload } from 'lucide-vue-next';
import { ref } from 'vue';

interface Row { id: number; key: string; name: string; description: string | null; is_active: boolean; department: string | null; published: { id: number; version: number; page_count: number; original_filename: string } | null; draft: { id: number; version: number } | null; workflows: string[] }
defineProps<{ templates: Row[]; departments: { value: number; label: string }[] }>();

const open = ref(false);
const form = useForm({ key: '', name: '', description: '', department_id: null as number | null, file: null as File | null });
const slug = () => !form.key && (form.key = form.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 64));
</script>

<template>
    <AppLayout title="Official PDF templates">
        <PageHeader title="Official PDF templates" description="The institute's prescribed forms. Each approved request is printed onto its form; the uploaded file itself is never changed.">
            <AppButton :icon="Upload" @click="open = true">Upload a form</AppButton>
        </PageHeader>

        <DataTable :empty="!templates.length" empty-title="No templates yet" empty-description="Upload each prescribed PDF form, map its fields and publish it.">
            <table class="data-table">
                <thead><tr><th>Form</th><th>In use</th><th>Used by</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" :class="!t.is_active && 'opacity-60'">
                        <td><Link :href="route('admin.pdf-templates.show', t.id)" class="font-medium text-brand-700 hover:underline">{{ t.name }}</Link><p class="font-mono text-xs text-muted">{{ t.key }}<span v-if="t.department"> · {{ t.department }}</span></p></td>
                        <td class="text-sm">
                            <template v-if="t.published">Version {{ t.published.version }} · {{ t.published.page_count }} page(s)<p class="truncate text-xs text-muted">{{ t.published.original_filename }}</p></template>
                            <span v-else class="text-muted">Not published</span>
                            <p v-if="t.draft" class="text-xs text-amber-700">Draft version {{ t.draft.version }} in progress</p>
                        </td>
                        <td class="text-sm">{{ t.workflows.join(', ') || '—' }}</td>
                        <td><StatusBadge :status="t.is_active ? 'active' : 'inactive'" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="open" title="Upload an official form" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="form.post(route('admin.pdf-templates.store'), { onSuccess: () => (open = false) })">
                <AlertBox tone="info">Upload the form exactly as the office issues it (PDF). Fillable and flat or scanned forms both work. A password-protected PDF must be unlocked first.</AlertBox>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" @blur="slug" /></FormField>
                    <FormField label="Key" :error="form.errors.key" required hint="lower-case, used in links"><TextInput v-model="form.key" class="font-mono" /></FormField>
                </div>
                <FormField label="Department (optional)" :error="form.errors.department_id"><SelectInput v-model="form.department_id" :options="departments" placeholder="Institute-wide" /></FormField>
                <FormField label="Purpose / notes" :error="form.errors.description"><TextArea v-model="form.description" rows="2" /></FormField>
                <FormField label="PDF form" :error="form.errors.file" required><FileUpload v-model="form.file" accept=".pdf,application/pdf" :progress="form.progress?.percentage ?? null" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing" :disabled="!form.file">Upload</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
