<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/Workflow/format';
import { router, useForm } from '@inertiajs/vue3';
import { Download, Pencil, Plus, Search, ShieldCheck } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Cert { id: number; number: string; verification_code: string; signatory_name: string; revoke_reason: string | null; workflow_instance_id: number | null; template: string; holder: string; issued_at: string; revoked: boolean; pdf: string | null; verify_url: string }
interface Tpl { id: number; code: string; name: string; title: string; body: string; numbering_pattern: string; footer: string | null; is_active: boolean }
const props = defineProps<{ manage: boolean; q: string | null; certificates: Cert[]; templates: Tpl[] }>();

const tab = ref<'issued' | 'templates'>('issued');
const q = ref(props.q ?? '');
let t: ReturnType<typeof setTimeout>;
watch(q, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('certificates.index'), q.value ? { q: q.value } : {}, { preserveState: true, replace: true }), 300);
});
const editing = ref<Tpl | null>(null);
const open = ref(false);
const form = useForm({ code: '', name: '', title: '', body: '', numbering_pattern: '{YYYY}/{SEQ:4}', footer: '', is_active: true });
function edit(x: Tpl | null) {
    editing.value = x;
    form.clearErrors();
    Object.assign(form, x ? { ...x, footer: x.footer ?? '' } : { code: '', name: '', title: '', body: '', numbering_pattern: '{YYYY}/{SEQ:4}', footer: '', is_active: true });
    open.value = true;
}
const save = () => (editing.value ? form.put(route('certificates.templates.update', editing.value.id), { onSuccess: () => (open.value = false) }) : form.post(route('certificates.templates.store'), { onSuccess: () => (open.value = false) }));
const revoking = ref<Cert | null>(null);
const revoke = useForm({ reason: '' });
</script>

<template>
    <AppLayout title="Certificates">
        <PageHeader title="Certificates and NOCs" description="Request one through New request. Each certificate is numbered, carries the approving officer's name and can be checked by anyone with its verification code." />
        <TabsNav v-if="manage" v-model="tab" :items="[{ key: 'issued', label: 'Issued' }, { key: 'templates', label: 'Templates', count: templates.length }]" class="mb-4" />

        <DataTable v-if="tab === 'issued'" :empty="!certificates.length" empty-title="No certificates issued">
            <template v-if="manage" #toolbar><div class="w-full max-w-sm"><TextInput v-model="q" :icon="Search" placeholder="Number or holder" aria-label="Search" /></div></template>
            <table class="data-table">
                <thead><tr><th>Number</th><th>Certificate</th><th>Holder</th><th>Issued</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="c in certificates" :key="c.id">
                        <td class="font-mono text-xs">{{ c.number }}<p class="text-muted">code {{ c.verification_code }}</p></td>
                        <td>{{ c.template }}</td>
                        <td>{{ c.holder }}</td>
                        <td class="text-xs">{{ date(c.issued_at) }}<p class="text-muted">{{ c.signatory_name }}</p></td>
                        <td><StatusBadge :status="c.revoked ? 'revoked' : 'active'" :label="c.revoked ? 'revoked' : 'valid'" /><p v-if="c.revoke_reason" class="text-xs text-muted">{{ c.revoke_reason }}</p></td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton v-if="c.pdf" size="sm" variant="ghost" :icon="Download" :href="c.pdf + '?inline=1'" external>PDF</AppButton>
                            <AppButton size="sm" variant="ghost" :icon="ShieldCheck" :href="c.verify_url" external>Verify</AppButton>
                            <AppButton v-if="manage && !c.revoked" size="sm" variant="danger-ghost" @click="(revoking = c), revoke.reset()">Revoke</AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <template v-else>
            <div class="mb-3"><AppButton size="sm" :icon="Plus" @click="edit(null)">Template</AppButton></div>
            <DataTable :empty="!templates.length" empty-title="No templates">
                <table class="data-table">
                    <thead><tr><th>Template</th><th>Numbering</th><th>Status</th><th><span class="sr-only">Edit</span></th></tr></thead>
                    <tbody>
                        <tr v-for="x in templates" :key="x.id">
                            <td><span class="font-medium text-ink">{{ x.name }}</span><p class="font-mono text-xs text-muted">{{ x.code }}</p></td>
                            <td class="font-mono text-xs">{{ x.numbering_pattern }}</td>
                            <td><StatusBadge :status="x.is_active ? 'active' : 'inactive'" /></td>
                            <td class="text-right"><AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${x.name}`" @click="edit(x)" /></td>
                        </tr>
                    </tbody>
                </table>
            </DataTable>
        </template>

        <ModalDialog :show="open" :title="editing ? 'Edit template' : 'New template'" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="Code" hint="Used by workflow steps" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                    <FormField label="Numbering" hint="{YYYY} {YY} {SEQ:n}" :error="form.errors.numbering_pattern" required><TextInput v-model="form.numbering_pattern" class="font-mono" /></FormField>
                </div>
                <FormField label="Heading" :error="form.errors.title" required><TextInput v-model="form.title" /></FormField>
                <FormField label="Text" hint="Placeholders: {{ holder.name }}, {{ holder.designation }}, {{ holder.department }}, {{ holder.employee_id }}, {{ date }}, and any form field key." :error="form.errors.body" required><TextArea v-model="form.body" rows="8" class="font-serif" /></FormField>
                <FormField label="Footer" :error="form.errors.footer"><TextInput v-model="form.footer" /></FormField>
                <CheckboxInput v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!revoking" :title="`Revoke ${revoking?.number ?? ''}`" description="Verification will show the certificate as revoked." @close="revoking = null">
            <form class="space-y-4" @submit.prevent="revoke.post(route('certificates.revoke', revoking!.id), { onSuccess: () => (revoking = null) })">
                <FormField label="Reason" :error="revoke.errors.reason" required><TextInput v-model="revoke.reason" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="revoking = null">Cancel</AppButton><AppButton type="submit" variant="danger" :loading="revoke.processing">Revoke</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
