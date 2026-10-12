<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
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
import { ask } from '@/lib/confirm';
import { date } from '@/Workflow/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Eye, FileText, Pencil, Plus, Send, Trash2, Upload, Workflow } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Version { id: number; version: number; status: string; original_filename: string; original_sha256: string; page_count: number; change_note: string | null; fields: number; form_fields: number; original: string | null; published_at: string | null; published_by: string | null; created_at: string; documents: number }
interface Output { id: number; workflow_definition_id: number; workflow: string; is_enabled: boolean; required_steps: string[]; condition: { field: string; op: string; value: unknown } | null; download_groups: string[]; confidential: boolean; requires_signature: boolean; filename_pattern: string; regeneration: string }
const props = defineProps<{
    template: { id: number; key: string; name: string; description: string | null; department_id: number | null; is_active: boolean };
    versions: Version[]; outputs: Output[];
    workflows: { id: number; name: string; key: string; steps: { value: string; label: string }[] }[];
    groups: { value: string; label: string }[];
    departments: { value: number; label: string }[];
    signatureProvider: boolean;
}>();

const hasDraft = computed(() => props.versions.some((v) => v.status === 'draft'));
const details = useForm({ name: props.template.name, description: props.template.description ?? '', department_id: props.template.department_id, is_active: props.template.is_active });
const editing = ref(false);
const replace = useForm({ file: null as File | null, change_note: '' });

async function publish(v: Version) {
    if (await ask(`Publish version ${v.version}? Requests submitted from now on will use it. Requests already submitted keep the version they started with, and documents already issued are not changed.`, { confirmLabel: 'Publish' })) {
        router.post(route('admin.pdf-templates.publish', v.id));
    }
}
async function discard(v: Version) {
    if (await ask(`Discard draft version ${v.version}? The uploaded file stays on record.`, { confirmLabel: 'Discard', tone: 'danger' })) router.post(route('admin.pdf-templates.discard', v.id));
}
async function toggle() {
    const off = props.template.is_active;
    if (!off || (await ask('Deactivate this template? Requests submitted from now on will not produce it; documents already issued remain available.', { confirmLabel: 'Deactivate', tone: 'danger' }))) {
        router.put(route('admin.pdf-templates.update', props.template.id), { ...details.data(), is_active: !off }, { preserveScroll: true });
    }
}

// Workflow outputs
const ops = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'empty', 'not_empty'].map((o) => ({ value: o, label: o }));
const blank = { workflow_definition_id: null as number | null, is_enabled: true, required_steps: [] as string[], use_condition: false, cond_field: '', cond_op: 'eq', cond_value: '', download_groups: ['applicant'] as string[], confidential: false, requires_signature: false, filename_pattern: '{template}_{reference}_Approved', regeneration: 'authorised' };
const out = useForm({ ...blank });
const outOpen = ref(false);
const outErrors = computed(() => out.errors as Record<string, string | undefined>);
function editOutput(o: Output | null) {
    out.clearErrors();
    Object.assign(out, o ? { ...blank, ...o, use_condition: !!o.condition, cond_field: o.condition?.field ?? '', cond_op: o.condition?.op ?? 'eq', cond_value: o.condition ? String(o.condition.value ?? '') : '' } : blank);
    outOpen.value = true;
}
const steps = computed(() => props.workflows.find((w) => w.id === out.workflow_definition_id)?.steps ?? []);
const saveOutput = () =>
    out.transform((d) => ({ ...d, condition: d.use_condition && d.cond_field ? { field: d.cond_field, op: d.cond_op, value: d.cond_value } : null }))
        .post(route('admin.pdf-templates.outputs.save', props.template.id), { preserveScroll: true, onSuccess: () => (outOpen.value = false) });
async function disable(o: Output) {
    if (await ask(`Stop producing this form for "${o.workflow}"? Documents already issued are kept.`, { confirmLabel: 'Stop', tone: 'danger' })) router.post(route('admin.pdf-templates.outputs.disable', o.id), {}, { preserveScroll: true });
}
const groupLabel = (g: string) => props.groups.find((x) => x.value === g)?.label ?? g;
</script>

<template>
    <AppLayout :title="template.name">
        <PageHeader :title="template.name" :description="template.description ?? template.key" :breadcrumbs="[{ label: 'Official PDF templates', href: route('admin.pdf-templates.index') }, { label: template.name }]">
            <AppButton variant="outline" :icon="Pencil" @click="editing = true">Details</AppButton>
            <AppButton :variant="template.is_active ? 'outline' : 'primary'" @click="toggle">{{ template.is_active ? 'Deactivate' : 'Activate' }}</AppButton>
        </PageHeader>
        <AlertBox v-if="!template.is_active" tone="warning" class="mb-6">Inactive: no new documents are produced from this form.</AlertBox>

        <DataTable title="Versions" description="A published version, its file and its mapping never change. A revised form is uploaded as a new version." class="mb-6">
            <table class="data-table">
                <thead><tr><th>Version</th><th>File</th><th>Fields</th><th>Issued</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="v in versions" :key="v.id">
                        <td class="font-semibold">{{ v.version }}<p v-if="v.change_note" class="text-xs font-normal text-muted">{{ v.change_note }}</p></td>
                        <td class="text-sm">
                            <a v-if="v.original" :href="route('documents.show', v.original) + '?inline=1'" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-brand-700 hover:underline"><FileText :size="14" aria-hidden="true" />{{ v.original_filename }}</a>
                            <p class="font-mono text-[11px] text-muted" :title="v.original_sha256">sha256 {{ v.original_sha256.slice(0, 16) }}… · {{ v.page_count }} page(s)</p>
                        </td>
                        <td class="text-sm">{{ v.fields }} mapped<p v-if="v.form_fields" class="text-xs text-muted">{{ v.form_fields }} interactive field(s) found</p></td>
                        <td class="text-sm">{{ v.documents }}</td>
                        <td><StatusBadge :status="v.status === 'superseded' ? 'archived' : v.status" :label="v.status" /><p v-if="v.published_at" class="mt-1 text-xs text-muted">{{ date(v.published_at) }} · {{ v.published_by }}</p></td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton size="sm" variant="ghost" :icon="v.status === 'draft' ? Pencil : Eye" :href="route('admin.pdf-templates.mapper', v.id)">{{ v.status === 'draft' ? 'Map fields' : 'Mapping' }}</AppButton>
                            <a v-if="v.fields" :href="route('admin.pdf-templates.preview', v.id)" target="_blank" rel="noopener" class="ml-1 text-sm text-brand-700 hover:underline">Sample PDF</a>
                            <AppButton v-if="v.status === 'draft'" size="sm" :icon="Send" class="ml-1" @click="publish(v)">Publish</AppButton>
                            <AppButton v-if="v.status === 'draft' && v.version > 1" size="sm" variant="danger-ghost" :icon="Trash2" aria-label="Discard draft" @click="discard(v)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <form v-if="!hasDraft" class="card mb-6 grid gap-3 p-5 sm:grid-cols-6 sm:items-end" @submit.prevent="replace.post(route('admin.pdf-templates.versions.store', template.id))">
            <h2 class="font-semibold text-ink sm:col-span-6">Upload a revised form</h2>
            <FormField label="Revised PDF" :error="replace.errors.file" required class="sm:col-span-3"><FileUpload v-model="replace.file" accept=".pdf,application/pdf" /></FormField>
            <FormField label="What changed, and on whose authority" :error="replace.errors.change_note" required class="sm:col-span-2"><TextInput v-model="replace.change_note" /></FormField>
            <AppButton type="submit" variant="outline" :icon="Upload" :loading="replace.processing" :disabled="!replace.file">Upload</AppButton>
        </form>

        <DataTable title="Workflows that issue this form" description="When the request reaches final approval, the form is filled and offered to the people allowed below." :empty="!outputs.length" empty-title="Not attached to any workflow">
            <template #toolbar><AppButton size="sm" :icon="Plus" @click="editOutput(null)">Attach to a workflow</AppButton></template>
            <table class="data-table">
                <thead><tr><th>Workflow</th><th>Required approvals</th><th>Who may download</th><th>Release</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="o in outputs" :key="o.id" :class="!o.is_enabled && 'opacity-60'">
                        <td><span class="inline-flex items-center gap-1 font-medium"><Workflow :size="14" aria-hidden="true" />{{ o.workflow }}</span><p v-if="o.condition" class="font-mono text-xs text-muted">only if {{ o.condition.field }} {{ o.condition.op }} {{ o.condition.value }}</p></td>
                        <td class="text-sm">{{ o.required_steps.join(', ') || 'final approval' }}</td>
                        <td class="text-sm">{{ o.confidential ? 'Confidential: applicant, deciding officers, named officers' : o.download_groups.map(groupLabel).join('; ') }}</td>
                        <td class="text-sm">{{ o.requires_signature ? 'After authorised signature' : 'On generation' }}<p class="font-mono text-xs text-muted">{{ o.filename_pattern }}.pdf</p></td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${o.workflow}`" @click="editOutput(o)" />
                            <AppButton v-if="o.is_enabled" size="sm" variant="danger-ghost" :icon="Trash2" :aria-label="`Stop for ${o.workflow}`" @click="disable(o)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="editing" title="Template details" @close="editing = false">
            <form class="space-y-4" @submit.prevent="details.put(route('admin.pdf-templates.update', template.id), { onSuccess: () => (editing = false) })">
                <FormField label="Name" :error="details.errors.name" required><TextInput v-model="details.name" /></FormField>
                <FormField label="Department" :error="details.errors.department_id"><SelectInput v-model="details.department_id" :options="departments" placeholder="Institute-wide" /></FormField>
                <FormField label="Purpose / notes" :error="details.errors.description"><TextArea v-model="details.description" rows="3" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="editing = false">Cancel</AppButton><AppButton type="submit" :loading="details.processing">Save</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="outOpen" title="Issue this form from a workflow" size="lg" @close="outOpen = false">
            <form class="space-y-4" @submit.prevent="saveOutput">
                <FormField label="Workflow" :error="out.errors.workflow_definition_id" required><SelectInput v-model="out.workflow_definition_id" :options="workflows.map((w) => ({ value: w.id, label: w.name }))" placeholder="Choose" /></FormField>
                <fieldset v-if="steps.length">
                    <legend class="mb-1 text-sm font-medium text-ink">Approvals that must be on record (checked again at generation)</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1"><CheckboxInput v-for="s in steps" :key="s.value" v-model="out.required_steps" :value="s.value" :label="s.label" /></div>
                </fieldset>
                <div>
                    <CheckboxInput v-model="out.use_condition" label="Only for some requests (condition on the form)" />
                    <div v-if="out.use_condition" class="mt-2 grid gap-2 sm:grid-cols-3">
                        <TextInput v-model="out.cond_field" placeholder="field key" aria-label="Condition field" class="font-mono" />
                        <SelectInput v-model="out.cond_op" :options="ops" aria-label="Condition operator" />
                        <TextInput v-model="out.cond_value" placeholder="value" aria-label="Condition value" />
                    </div>
                    <p v-if="outErrors.condition" class="mt-1 text-sm text-red-700">{{ outErrors.condition }}</p>
                </div>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium text-ink">Who may download</legend>
                    <div class="space-y-1"><CheckboxInput v-for="g in groups" :key="g.value" v-model="out.download_groups" :value="g.value" :label="g.label" /></div>
                    <p v-if="out.errors.download_groups" class="text-sm text-red-700">{{ out.errors.download_groups }}</p>
                </fieldset>
                <CheckboxInput v-model="out.confidential" label="Confidential (e.g. medical): only the applicant, the officers who decided, and officers given the confidential-documents permission" />
                <CheckboxInput v-model="out.requires_signature" label="Release only after an authorised electronic signature" />
                <AlertBox v-if="out.requires_signature && !signatureProvider" tone="warning">No signature provider is configured yet. Documents will be generated and held as "awaiting signature" — never released unsigned and never shown as signed.</AlertBox>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="File name" :error="out.errors.filename_pattern" hint="{template} {reference} {version}"><TextInput v-model="out.filename_pattern" class="font-mono" /></FormField>
                    <FormField label="Reissue" :error="out.errors.regeneration"><SelectInput v-model="out.regeneration" :options="[{ value: 'authorised', label: 'By authorised officers, with a reason' }, { value: 'never', label: 'Never' }]" /></FormField>
                </div>
                <CheckboxInput v-model="out.is_enabled" label="Enabled" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="outOpen = false">Cancel</AppButton><AppButton type="submit" :loading="out.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
