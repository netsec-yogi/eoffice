<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { when } from '@/Workflow/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Clock, PenLine, RefreshCw, RotateCcw, Search } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface Row { id: number; uuid: string; status: string; attempts: number; error_code: string | null; error_detail: string | null; reason: string | null; document_version: number; sha256: string | null; regeneration_reason: string | null; template: string; template_version: number | null; reference: string; instance_id: number; instance_status: string; workflow: string; regenerable: boolean; generated_at: string | null; updated_at: string }
const props = defineProps<{ filters: Record<string, string>; counts: Record<string, number>; documents: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; from: number | null; to: number | null; total: number } }>();

const f = reactive({ status: '', q: '', ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('admin.official-documents.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const tone: Record<string, string> = { generated: 'completed', failed: 'failed', queued: 'pending', generating: 'pending', waiting_approval: 'in_review', awaiting_signature: 'pending', not_generated: 'cancelled', superseded: 'archived' };
const retry = (r: Row) => router.post(route('admin.official-documents.retry', r.uuid), {}, { preserveScroll: true });
const reissue = ref<Row | null>(null);
const form = useForm({ reason: '', use_current_version: false });
const submitReissue = () => form.post(route('admin.official-documents.regenerate', reissue.value!.uuid), { preserveScroll: true, onSuccess: () => ((reissue.value = null), form.reset()) });
const n = (k: string) => props.counts[k] ?? 0;
</script>

<template>
    <AppLayout title="Document generation">
        <PageHeader title="Document generation" description="Official documents for approved requests: what is waiting, generated or failed. A failure never undoes an approval; fix the cause and retry. This page does not open the documents themselves." />

        <div class="mb-6 grid gap-3 sm:grid-cols-4">
            <StatCard label="Waiting for approval" :value="n('waiting_approval')" :icon="Clock" @click="f.status = 'waiting_approval'" />
            <StatCard label="Queued or generating" :value="n('queued') + n('generating')" :icon="RefreshCw" tone="warning" />
            <StatCard label="Generated" :value="n('generated')" :icon="CircleCheck" tone="success" />
            <StatCard label="Failed" :value="n('failed')" :icon="CircleX" :tone="n('failed') ? 'danger' : 'default'" :alert="!!n('failed')" />
        </div>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <TextInput v-model="f.q" :icon="Search" placeholder="Application number" class="!w-60" aria-label="Search" />
            <button v-for="s in ['', 'failed', 'awaiting_signature', 'queued', 'generated', 'waiting_approval', 'superseded']" :key="s" type="button"
                :class="['rounded-full px-3 py-1 text-sm', f.status === s ? 'bg-brand-600 text-white' : 'bg-surface-sunken text-ink-soft hover:bg-surface-muted']" @click="f.status = s">{{ s ? s.replace('_', ' ') : 'all' }}</button>
        </div>

        <DataTable :empty="!documents.data.length" empty-title="Nothing here">
            <table class="data-table">
                <thead><tr><th>Application</th><th>Form</th><th>Status</th><th>Attempts</th><th>Problem</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="r in documents.data" :key="r.id">
                        <td><Link :href="route('requests.show', r.instance_id)" class="font-mono text-sm text-brand-700 hover:underline">{{ r.reference }}</Link><p class="text-xs text-muted">{{ r.workflow }} · request {{ r.instance_status }}</p></td>
                        <td class="text-sm">{{ r.template }}<p class="text-xs text-muted">form v{{ r.template_version ?? '—' }}<span v-if="r.document_version > 1"> · reissue {{ r.document_version }}</span></p></td>
                        <td><StatusBadge :status="tone[r.status] ?? r.status" :label="r.status.replace('_', ' ')" /><p class="mt-1 text-xs text-muted">{{ when(r.updated_at) }}</p></td>
                        <td class="text-sm tabular-nums">{{ r.attempts }}</td>
                        <td class="max-w-md text-sm">
                            <template v-if="r.reason"><p class="font-medium text-ink">{{ r.reason }}</p><p v-if="r.error_detail" class="text-xs break-words text-muted">{{ r.error_detail }}</p></template>
                            <p v-if="r.regeneration_reason" class="text-xs text-muted">Reissued: {{ r.regeneration_reason }}</p>
                            <p v-if="r.sha256" class="font-mono text-[11px] text-muted" :title="r.sha256">sha256 {{ r.sha256.slice(0, 16) }}…</p>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton v-if="r.status === 'failed'" size="sm" :icon="RotateCcw" @click="retry(r)">Retry</AppButton>
                            <AppButton v-if="r.regenerable" size="sm" variant="ghost" :icon="PenLine" @click="reissue = r">Reissue</AppButton>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="documents.links" :from="documents.from" :to="documents.to" :total="documents.total" /></template>
        </DataTable>

        <ModalDialog :show="!!reissue" :title="`Reissue ${reissue?.template} for ${reissue?.reference}`" @close="reissue = null">
            <form class="space-y-4" @submit.prevent="submitReissue">
                <p class="text-sm text-muted">The current document is kept and marked as superseded; the applicant receives the new one. The reason is recorded in the audit log.</p>
                <FormField label="Reason" :error="form.errors.reason" required><TextArea v-model="form.reason" rows="3" /></FormField>
                <CheckboxInput v-model="form.use_current_version" label="Use the currently published form version (otherwise the version the request was submitted with)" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="reissue = null">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Reissue</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
