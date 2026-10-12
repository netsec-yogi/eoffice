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
import { date } from '@/Workflow/format';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Row { id: number; key: string; name: string; category: string; is_active: boolean; department: string | null; published: number | null; published_at: string | null; draft: number | null; open: number; official_pdfs: { id: number; name: string }[] }
defineProps<{ definitions: Row[]; departments: { id: number; code: string; name: string }[]; categories: string[]; canManageTemplates: boolean }>();

const open = ref(false);
const form = useForm({ key: '', name: '', description: '', category: 'general', department_id: null as number | null });
const create = () => form.post(route('admin.workflows.store'));
</script>

<template>
    <AppLayout title="Workflows">
        <PageHeader title="Workflows" description="Every departmental process runs on the same engine. Changing a route, a step or a deadline is configuration: edit a draft and publish it.">
            <AppButton :icon="Plus" @click="open = true">Workflow</AppButton>
        </PageHeader>
        <DataTable :empty="!definitions.length" empty-title="No workflows yet" empty-description="Create a form, then a workflow that uses it.">
            <table class="data-table">
                <thead><tr><th>Workflow</th><th>Category</th><th>Owner</th><th>Published</th><th>Draft</th><th>Official PDFs</th><th>Open requests</th></tr></thead>
                <tbody>
                    <tr v-for="d in definitions" :key="d.id">
                        <td><Link :href="route('admin.workflows.edit', d.id)" class="font-medium text-ink hover:text-brand-700">{{ d.name }}</Link><p class="font-mono text-xs text-muted">{{ d.key }}</p></td>
                        <td class="capitalize">{{ d.category.replace(/_/g, ' ') }}</td>
                        <td>{{ d.department ?? 'Institute' }}</td>
                        <td><template v-if="d.published">v{{ d.published }} <span class="text-xs text-muted">{{ date(d.published_at) }}</span></template><StatusBadge v-else status="draft" label="never published" /></td>
                        <td>{{ d.draft ? `v${d.draft}` : '—' }}</td>
                        <td class="text-sm">
                            <template v-for="(t, i) in d.official_pdfs" :key="t.id"><span v-if="i">, </span><Link v-if="canManageTemplates" :href="route('admin.pdf-templates.show', t.id)" class="text-brand-700 hover:underline">{{ t.name }}</Link><span v-else>{{ t.name }}</span></template>
                            <span v-if="!d.official_pdfs.length" class="text-muted">—</span>
                        </td>
                        <td>{{ d.open }}<StatusBadge v-if="!d.is_active" status="inactive" class="ml-2" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="open" title="New workflow" @close="open = false">
            <form class="space-y-4" @submit.prevent="create">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Key" hint="Short, permanent, e.g. it-email. Used in reference numbers." :error="form.errors.key" required><TextInput v-model="form.key" /></FormField>
                    <FormField label="Category" :error="form.errors.category" required><SelectInput v-model="form.category" :options="categories.map((c) => ({ value: c, label: c.replace(/_/g, ' ') }))" /></FormField>
                </div>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                <FormField label="Description" :error="form.errors.description"><TextArea v-model="form.description" rows="2" /></FormField>
                <FormField label="Owning department" :error="form.errors.department_id"><SelectInput v-model="form.department_id" :options="departments.map((d) => ({ value: d.id, label: d.name }))" placeholder="Institute" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Create and design</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
