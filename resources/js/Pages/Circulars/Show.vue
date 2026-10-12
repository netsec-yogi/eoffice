<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { date } from '@/Workflow/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Archive, CheckCheck, Download, FilePen, Pencil, Send, Undo2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    circular: { id: number; number: string | null; kind: string; kind_label: string; title: string; body: string; status: string; version: number; requires_ack: boolean; ack_by: string | null; published_at: string | null; author: string; author_designation: string | null; department: string | null; pdf: string | null; superseded_by: number | null; workflow_instance_id: number | null; previous_version_id: number | null };
    receipt: { acknowledged_at: string | null } | null;
    report: { department: string; total: number; read: number; acknowledged: number }[] | null;
    can: { edit: boolean; manage: boolean; revise: boolean };
    workflows: { key: string; name: string }[];
}>();

const submit = useForm({ workflow: props.workflows[0]?.key ?? '' });
const ack = () => router.post(route('circulars.acknowledge', props.circular.id), {}, { preserveScroll: true });
const revise = async () => (await ask('Draft a new version? The current one stays in force until the new version is approved and published.')) && router.post(route('circulars.revise', props.circular.id));
const withdrawOpen = ref(false);
const withdraw = useForm({ reason: '' });
const archive = async () => (await ask('Archive this circular? It is kept but no longer listed as current.')) && router.post(route('circulars.archive', props.circular.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :title="circular.number ?? circular.title">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="font-mono text-sm text-muted">{{ circular.number ?? 'Draft' }} · {{ circular.kind_label }}<template v-if="circular.version > 1"> · version {{ circular.version }}</template></p>
            <div class="flex flex-wrap gap-2">
                <StatusBadge :status="circular.status === 'published' ? 'published' : circular.status" :label="circular.status.replace('_', ' ')" />
                <AppButton v-if="circular.pdf" size="sm" variant="outline" :icon="Download" :href="circular.pdf + '?inline=1'" external>PDF</AppButton>
                <AppButton v-if="can.edit" size="sm" variant="outline" :icon="Pencil" :href="route('circulars.edit', circular.id)">Edit</AppButton>
                <AppButton v-if="can.revise" size="sm" variant="outline" :icon="FilePen" @click="revise">Revise</AppButton>
                <AppButton v-if="can.manage && circular.status === 'published'" size="sm" variant="danger-ghost" :icon="Undo2" @click="withdrawOpen = true">Withdraw</AppButton>
                <AppButton v-if="can.manage && ['published', 'superseded', 'withdrawn'].includes(circular.status)" size="sm" variant="ghost" :icon="Archive" @click="archive">Archive</AppButton>
            </div>
        </div>

        <AlertBox v-if="circular.superseded_by" tone="warning" class="mb-4">Superseded by a newer version. <Link :href="route('circulars.show', circular.superseded_by)" class="font-semibold underline">Open the current version</Link>.</AlertBox>
        <AlertBox v-if="circular.status === 'withdrawn'" tone="danger" class="mb-4">This circular has been withdrawn.</AlertBox>
        <AlertBox v-if="circular.status === 'in_review'" tone="info" class="mb-4">Awaiting approval. <Link v-if="circular.workflow_instance_id" :href="route('requests.show', circular.workflow_instance_id)" class="font-semibold underline">Follow the approval</Link>.</AlertBox>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <article class="card p-8">
                <h1 class="text-center text-xl font-bold text-ink">{{ circular.title }}</h1>
                <p class="mt-1 text-center text-sm text-muted">{{ circular.department ?? '' }}<template v-if="circular.published_at"> · {{ date(circular.published_at) }}</template></p>
                <div class="mt-6 font-serif text-[15px] leading-relaxed whitespace-pre-line text-ink">{{ circular.body }}</div>
                <p class="mt-8 text-right text-sm"><strong class="text-ink">{{ circular.author }}</strong><br><span class="text-muted">{{ circular.author_designation }}</span></p>
            </article>
            <aside class="space-y-4">
                <section v-if="receipt && circular.requires_ack && circular.status === 'published'" class="card p-4">
                    <p v-if="receipt.acknowledged_at" class="flex items-center gap-2 text-sm text-emerald-700"><CheckCheck :size="16" aria-hidden="true" />You acknowledged this on {{ date(receipt.acknowledged_at) }}.</p>
                    <template v-else>
                        <p class="mb-3 text-sm text-ink">Please confirm that you have read and noted this<template v-if="circular.ack_by"> by {{ circular.ack_by }}</template>.</p>
                        <AppButton class="w-full" :icon="CheckCheck" @click="ack">Acknowledge</AppButton>
                    </template>
                </section>
                <section v-if="can.edit && workflows.length" class="card space-y-3 p-4">
                    <FormField label="Send for approval through"><SelectInput v-model="submit.workflow" :options="workflows.map((w) => ({ value: w.key, label: w.name }))" /></FormField>
                    <p v-if="(submit.errors as Record<string, string>).workflow" class="text-sm text-red-600">{{ (submit.errors as Record<string, string>).workflow }}</p>
                    <AppButton class="w-full" :icon="Send" :loading="submit.processing" @click="submit.post(route('circulars.submit', circular.id), { preserveScroll: true })">Send for approval</AppButton>
                </section>
                <DataTable v-if="report" :empty="!report.length" empty-title="No recipients" title="Receipts">
                    <table class="data-table">
                        <thead><tr><th>Dept</th><th class="text-right">Sent</th><th class="text-right">Read</th><th v-if="circular.requires_ack" class="text-right">Ack</th></tr></thead>
                        <tbody>
                            <tr v-for="r in report" :key="r.department">
                                <td>{{ r.department }}</td><td class="text-right tabular-nums">{{ r.total }}</td><td class="text-right tabular-nums">{{ r.read }}</td>
                                <td v-if="circular.requires_ack" :class="['text-right tabular-nums', r.acknowledged < r.total && 'text-amber-700']">{{ r.acknowledged }}</td>
                            </tr>
                        </tbody>
                    </table>
                </DataTable>
                <p v-if="circular.previous_version_id" class="text-sm"><Link :href="route('circulars.show', circular.previous_version_id)" class="text-brand-700 hover:underline">Previous version</Link></p>
            </aside>
        </div>

        <ModalDialog :show="withdrawOpen" title="Withdraw circular" description="Recipients will see it marked as withdrawn." @close="withdrawOpen = false">
            <form class="space-y-4" @submit.prevent="withdraw.post(route('circulars.withdraw', circular.id), { onSuccess: () => (withdrawOpen = false) })">
                <FormField label="Reason" :error="withdraw.errors.reason" required><TextArea v-model="withdraw.reason" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="withdrawOpen = false">Cancel</AppButton><AppButton type="submit" variant="danger" :loading="withdraw.processing">Withdraw</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
