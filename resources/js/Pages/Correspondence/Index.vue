<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import type { DocumentSummary } from '@/Workflow/types';
import { Link, router, useForm } from '@inertiajs/vue3';
import { FolderInput, Plus, Search } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface Entry { id: number; register_number: string; direction: string; kind: string; subject: string; counterparty: string; their_reference: string | null; mode: string; classification: string; register_date: string; letter_date: string | null; file: { id: number; file_number: string } | null; marked_to: string | null; mine: boolean; documents: DocumentSummary[] }
const props = defineProps<{ entries: Paginated<Entry>; filters: Record<string, string | boolean>; registry: boolean; myFiles: { id: number; file_number: string; subject: string }[]; kinds: string[]; modes: string[] }>();

const f = reactive({ direction: '', q: '', mine: false, ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('correspondence.index'), Object.fromEntries(Object.entries({ ...f, mine: f.mine ? 1 : '' }).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});

const open = ref(false);
const form = useForm({ direction: props.registry ? 'incoming' : 'outgoing', kind: 'letter', subject: '', counterparty: '', their_reference: '', letter_date: '', register_date: new Date().toISOString().slice(0, 10), mode: 'post', classification: 'internal', efile_id: null as number | null, marked_to: null as number | null, remarks: '', documents: [] as File[] });
const save = () => form.post(route('correspondence.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => ((open.value = false), form.reset()) });
const filing = ref<Entry | null>(null);
const attach = useForm({ efile_id: null as number | null });
const doAttach = () => attach.post(route('correspondence.attach', filing.value!.id), { preserveScroll: true, onSuccess: () => (filing.value = null) });
</script>

<template>
    <AppLayout title="Correspondence">
        <PageHeader title="Correspondence" description="Diary register for incoming letters and dispatch register for outgoing ones. Letters marked to you can be placed on a file you hold.">
            <AppButton :icon="Plus" @click="open = true">Register</AppButton>
        </PageHeader>
        <TabsNav v-model="f.direction" :items="[{ key: '', label: 'All' }, { key: 'incoming', label: 'Incoming (diary)' }, { key: 'outgoing', label: 'Outgoing (dispatch)' }]" class="mb-4" />
        <DataTable :empty="!entries.data.length" empty-title="Nothing registered">
            <template #toolbar>
                <div class="flex w-full flex-wrap items-center gap-3">
                    <div class="w-full max-w-sm"><TextInput v-model="f.q" :icon="Search" placeholder="Number, subject, sender, their reference" aria-label="Search" /></div>
                    <CheckboxInput v-model="f.mine" label="Marked to me, not yet filed" />
                </div>
            </template>
            <table class="data-table">
                <thead><tr><th>Number</th><th>Subject</th><th>From / to</th><th>Date</th><th>File</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id">
                        <td class="font-mono text-xs whitespace-nowrap">{{ e.register_number }}<p class="font-sans"><StatusBadge :status="e.direction === 'incoming' ? 'info' : 'sent'" :label="`${e.direction} ${e.kind}`" /></p></td>
                        <td>
                            <span class="font-medium text-ink">{{ e.subject }}</span>
                            <p class="text-xs text-muted"><template v-if="e.marked_to">Marked to {{ e.marked_to }} · </template>{{ e.mode }}<template v-if="e.their_reference"> · ref. {{ e.their_reference }}</template></p>
                            <a v-for="d in e.documents" :key="d.id" :href="d.url + (d.preview ? '?inline=1' : '')" target="_blank" rel="noopener" class="mr-2 text-xs text-brand-700 hover:underline">{{ d.name }}</a>
                        </td>
                        <td>{{ e.counterparty }}</td>
                        <td class="text-xs whitespace-nowrap">{{ e.register_date }}</td>
                        <td><Link v-if="e.file" :href="route('efiles.show', e.file.id)" class="font-mono text-xs text-brand-700 hover:underline">{{ e.file.file_number }}</Link><span v-else class="text-xs text-muted">not filed</span></td>
                        <td class="text-right"><AppButton v-if="!e.file && myFiles.length" size="sm" variant="ghost" :icon="FolderInput" @click="(filing = e), attach.reset()">Put on file</AppButton></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="entries.links" :from="entries.from" :to="entries.to" :total="entries.total" /></template>
        </DataTable>

        <ModalDialog :show="open" title="Register correspondence" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="Direction" :error="form.errors.direction" required>
                        <SelectInput v-model="form.direction" :options="[...(registry ? [{ value: 'incoming', label: 'Incoming (diary)' }] : []), { value: 'outgoing', label: 'Outgoing (dispatch)' }]" />
                    </FormField>
                    <FormField label="Kind" :error="form.errors.kind"><SelectInput v-model="form.kind" :options="kinds.map((k) => ({ value: k, label: k }))" /></FormField>
                    <FormField label="Mode" :error="form.errors.mode"><SelectInput v-model="form.mode" :options="modes.map((k) => ({ value: k, label: k }))" /></FormField>
                </div>
                <FormField label="Subject" :error="form.errors.subject" required><TextInput v-model="form.subject" /></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField :label="form.direction === 'incoming' ? 'From' : 'To'" :error="form.errors.counterparty" required><TextInput v-model="form.counterparty" /></FormField>
                    <FormField label="Their reference" :error="form.errors.their_reference"><TextInput v-model="form.their_reference" /></FormField>
                    <FormField label="Letter date" :error="form.errors.letter_date"><TextInput v-model="form.letter_date" type="date" /></FormField>
                    <FormField :label="form.direction === 'incoming' ? 'Received on' : 'Dispatched on'" :error="form.errors.register_date"><TextInput v-model="form.register_date" type="date" /></FormField>
                    <FormField label="Classification" :error="form.errors.classification"><SelectInput v-model="form.classification" :options="['public', 'internal', 'confidential', 'restricted'].map((c) => ({ value: c, label: c }))" /></FormField>
                    <FormField v-if="form.direction === 'incoming'" label="Mark to" :error="form.errors.marked_to"><UserPicker v-model="form.marked_to" category="faculty,staff" /></FormField>
                    <FormField v-else label="From file" hint="Outgoing letters are dispatched from a file with you." :error="form.errors.efile_id ?? (form.errors as Record<string, string>).workflow" required>
                        <SelectInput v-model="form.efile_id" :options="myFiles.map((f) => ({ value: f.id, label: `${f.file_number} — ${f.subject}` }))" />
                    </FormField>
                </div>
                <FormField label="Scanned letter / enclosures" :error="form.errors.documents"><input type="file" multiple class="text-sm" @change="form.documents = [...(($event.target as HTMLInputElement).files ?? [])]" /></FormField>
                <FormField label="Remarks" :error="form.errors.remarks"><TextArea v-model="form.remarks" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Register</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!filing" :title="`Put ${filing?.register_number ?? ''} on a file`" @close="filing = null">
            <form class="space-y-4" @submit.prevent="doAttach">
                <FormField label="File (with you)" :error="attach.errors.efile_id ?? (attach.errors as Record<string, string>).workflow" required>
                    <SelectInput v-model="attach.efile_id" :options="myFiles.map((f) => ({ value: f.id, label: `${f.file_number} — ${f.subject}` }))" />
                </FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="filing = null">Cancel</AppButton><AppButton type="submit" :loading="attach.processing">Put on file</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
