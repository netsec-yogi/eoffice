<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { date } from '@/Workflow/format';
import type { DocumentSummary } from '@/Workflow/types';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArchiveRestore, CheckCheck, FileText, FolderLock, GitBranch, Inbox, Link2, Lock, PenLine, Send, ShieldAlert, Undo2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { classLabel, classTone } from './classification';

interface Note { id: number; number: number | null; kind: string; body: string; status: string; author_name: string; author_designation: string | null; corrects: number | null; finalized_at: string | null; intact: boolean; mine: boolean }
interface Movement { id: number; from: string; to: string; from_department: string | null; to_department: string | null; action: string; reason: string | null; note: number | null; status: string; sent_at: string; acknowledged_at: string | null; remarks: string | null }
interface Corr { id: number; register_number: string; direction: string; kind: string; subject: string; counterparty: string; their_reference: string | null; register_date: string; documents: DocumentSummary[] }
const props = defineProps<{
    file: {
        id: number; file_number: string; subject: string; description: string | null; classification: string; status: string; note_count: number; legal_hold: boolean; disposition: string | null; closure_remarks: string | null;
        category: string; department: string; opened_by: string; opened_at: string; custodian: { id: number; name: string };
        retention: { code: string; name: string; retain_years: number | null; action: string } | null; retain_until: string | null; closed_at: string | null;
        in_transit: { id: number; to: string; from: string; to_me: boolean; from_me: boolean; action: string; reason: string | null; sent_at: string } | null;
    };
    contents: {
        notes: Note[]; movements: Movement[]; documents: (DocumentSummary & { replaces: number | null; db_id: number })[]; correspondence: Corr[];
        links: { id: number; relation: string; type: string; label: string; status: string | null; url: string | null }[];
        grants: { id: number; user: string; reason: string; expires_at: string | null }[];
        history: { id: number; event: string; summary: string; actor: string | null; at: string }[];
    } | null;
    can: { act: boolean; grant: boolean; reopen: boolean; records: boolean };
    actions: Record<string, string>;
    kinds: string[];
    approvalWorkflows: { key: string; name: string }[];
}>();

const tab = ref<'notes' | 'documents' | 'correspondence' | 'movement' | 'history' | 'access'>('notes');
const tabs = computed(() => [
    { key: 'notes' as const, label: 'Note sheet', count: props.file.note_count },
    { key: 'documents' as const, label: 'Documents', count: props.contents?.documents.length },
    { key: 'correspondence' as const, label: 'Correspondence', count: props.contents?.correspondence.length },
    { key: 'movement' as const, label: 'Movement', count: props.contents?.movements.length },
    { key: 'history' as const, label: 'History' },
    ...(props.can.grant && props.file.classification !== 'public' ? [{ key: 'access' as const, label: 'Access', count: props.contents?.grants.length }] : []),
]);
const finalNotes = computed(() => props.contents?.notes.filter((n) => n.status === 'final') ?? []);
const drafts = computed(() => props.contents?.notes.filter((n) => n.status === 'draft') ?? []);
const kindLabel = (k: string) => k.charAt(0).toUpperCase() + k.slice(1);

// ---- notes ----
const note = useForm({ kind: 'note', body: '', corrects_note_id: null as number | null, finalize: false });
async function saveNote(finalize: boolean) {
    if (finalize && !(await ask('Finalise this note? It is numbered and can no longer be changed; corrections are made by addendum.'))) return;
    note.finalize = finalize;
    note.post(route('efiles.notes.store', props.file.id), { preserveScroll: true, onSuccess: () => note.reset() });
}
const addendumTo = (n: Note) => {
    note.corrects_note_id = n.id;
    note.kind = 'addendum';
    document.getElementById('note-body')?.focus();
};
const editing = ref<Record<number, string>>({});
async function saveDraft(n: Note, finalize: boolean) {
    if (finalize && !(await ask('Finalise this note? It can no longer be changed afterwards.'))) return;
    router.put(route('efiles.notes.update', n.id), { body: editing.value[n.id] ?? n.body, kind: n.kind, finalize }, { preserveScroll: true });
}
async function discard(n: Note) {
    if (await ask('Discard this draft?')) router.delete(route('efiles.notes.destroy', n.id), { preserveScroll: true });
}

// ---- movement ----
const sendOpen = ref(false);
const send = useForm({ to: null as number | null, required_action: 'comments', reason: '', note_id: null as number | null });
const doSend = () => send.post(route('efiles.send', props.file.id));
const receive = useForm({ remarks: '' });
const doReceive = () => receive.post(route('efiles.receive', props.file.in_transit!.id));
async function recall() {
    if (await ask('Recall the file? It has not been received yet.')) router.post(route('efiles.recall', props.file.in_transit!.id), {}, { preserveScroll: true });
}

// ---- documents ----
const upload = ref<HTMLInputElement>();
const replacing = ref<number | null>(null);
function pick(replaces: number | null) {
    replacing.value = replaces;
    upload.value?.click();
}
function uploadFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    (e.target as HTMLInputElement).value = '';
    if (!file) return;
    router.post(route('efiles.attach', props.file.id), { file, replaces: replacing.value ?? undefined }, { forceFormData: true, preserveScroll: true });
}

// ---- close / reopen / grants / links ----
const closeOpen = ref(false);
const closing = useForm({ remarks: '' });
const reopenForm = useForm({ reason: '' });
const reopenOpen = ref(false);
const grant = useForm({ user_id: null as number | null, reason: '', expires_at: '' });
const revoke = async (id: number, user: string) => (await ask(`Revoke ${user}'s access?`)) && router.delete(route('efiles.grants.revoke', id), { preserveScroll: true });
const link = useForm({ file_number: '', relation: 'linked' });
const statusTone = (s: string) => (s === 'open' ? 'active' : s === 'destroyed' ? 'removed' : 'closed');
</script>

<template>
    <AppLayout :title="file.file_number">
        <PageHeader :title="file.subject" :description="`${file.file_number} · ${file.category} · ${file.department}`">
            <span :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold', classTone[file.classification]]"><Lock v-if="file.classification !== 'public' && file.classification !== 'internal'" :size="12" aria-hidden="true" />{{ classLabel[file.classification] }}</span>
            <StatusBadge :status="statusTone(file.status)" :label="file.status" />
        </PageHeader>

        <!-- In transit -->
        <AlertBox v-if="file.in_transit?.to_me" tone="info" class="mb-4" :title="`${file.in_transit.from} sent you this file — ${file.in_transit.action}`">
            <p v-if="file.in_transit.reason">{{ file.in_transit.reason }}</p>
            <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="doReceive">
                <TextInput v-model="receive.remarks" placeholder="Remarks on receipt (optional)" class="max-w-md" aria-label="Remarks on receipt" />
                <AppButton type="submit" :icon="Inbox" :loading="receive.processing">Acknowledge receipt</AppButton>
            </form>
        </AlertBox>
        <AlertBox v-else-if="file.in_transit" tone="warning" class="mb-4">
            In transit to <strong>{{ file.in_transit.to }}</strong> since {{ date(file.in_transit.sent_at) }} ({{ file.in_transit.action }}). Nothing can be added until it is received.
            <AppButton v-if="file.in_transit.from_me" size="sm" variant="outline" :icon="Undo2" class="ml-2" @click="recall">Recall</AppButton>
        </AlertBox>
        <AlertBox v-if="!contents" tone="warning" class="mb-4" title="Register entry only">You can see this file's register entry as a records officer. Its notes and documents are visible only to people it has been sent to or given access.</AlertBox>
        <AlertBox v-if="file.legal_hold" tone="danger" class="mb-4">Legal hold: this file cannot be disposed of.</AlertBox>

        <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
            <div class="min-w-0">
                <template v-if="contents">
                    <TabsNav v-model="tab" :items="tabs" class="mb-4" />

                    <!-- Note sheet -->
                    <section v-if="tab === 'notes'" class="space-y-4">
                        <div class="note-sheet rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 dark:border-emerald-500/20 dark:bg-emerald-500/5" aria-label="Note sheet">
                            <p v-if="!finalNotes.length" class="py-6 text-center text-sm text-muted">No notes yet.</p>
                            <article v-for="n in finalNotes" :key="n.id" class="border-b border-emerald-200/80 py-4 last:border-0 dark:border-emerald-500/15">
                                <header class="mb-1.5 flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-sm font-semibold text-ink">
                                        Note {{ n.number }} · {{ kindLabel(n.kind) }}<span v-if="n.corrects" class="font-normal text-muted"> to note {{ n.corrects }}</span>
                                    </p>
                                    <AppButton v-if="can.act" size="sm" variant="ghost" :icon="PenLine" @click="addendumTo(n)">Addendum</AppButton>
                                </header>
                                <p class="font-serif text-[15px] leading-relaxed whitespace-pre-line text-ink">{{ n.body }}</p>
                                <footer class="mt-2 text-right text-xs text-muted">
                                    <span class="font-medium text-ink-soft">{{ n.author_name }}</span><template v-if="n.author_designation">, {{ n.author_designation }}</template> · {{ date(n.finalized_at) }}
                                    <span v-if="!n.intact" class="ml-2 inline-flex items-center gap-0.5 font-semibold text-red-700"><ShieldAlert :size="12" aria-hidden="true" />integrity check failed</span>
                                </footer>
                            </article>
                        </div>

                        <!-- My drafts -->
                        <div v-for="n in drafts" :key="n.id" class="card space-y-2 border-2 border-dashed border-line-strong p-4">
                            <p class="text-xs font-semibold tracking-wide text-muted uppercase">Draft {{ kindLabel(n.kind) }}<span v-if="n.corrects"> to note {{ n.corrects }}</span> — visible only to you</p>
                            <TextArea :model-value="editing[n.id] ?? n.body" rows="4" @update:model-value="editing[n.id] = $event ?? ''" />
                            <div class="flex flex-wrap gap-2">
                                <AppButton size="sm" variant="outline" @click="saveDraft(n, false)">Save draft</AppButton>
                                <AppButton v-if="can.act" size="sm" @click="saveDraft(n, true)">Finalise</AppButton>
                                <AppButton size="sm" variant="danger-ghost" @click="discard(n)">Discard</AppButton>
                            </div>
                        </div>

                        <form v-if="can.act" class="card space-y-3 p-4" @submit.prevent="saveNote(true)">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ note.corrects_note_id ? 'Addendum' : 'New note' }}</p>
                                <SelectInput v-if="!note.corrects_note_id" v-model="note.kind" :options="kinds.filter((k) => k !== 'addendum').map((k) => ({ value: k, label: kindLabel(k) }))" class="!w-48" aria-label="Kind of note" />
                                <AppButton v-else size="sm" variant="ghost" @click="(note.corrects_note_id = null), (note.kind = 'note')">Cancel addendum</AppButton>
                            </div>
                            <FormField label="Note" hide-label :error="note.errors.body ?? (note.errors as Record<string, string>).workflow"><TextArea id="note-body" v-model="note.body" rows="5" placeholder="Write the note…" /></FormField>
                            <div class="flex flex-wrap justify-end gap-2">
                                <AppButton variant="outline" :loading="note.processing" @click="saveNote(false)">Save as draft</AppButton>
                                <AppButton type="submit" :loading="note.processing">Finalise note</AppButton>
                            </div>
                        </form>
                    </section>

                    <!-- Documents -->
                    <section v-else-if="tab === 'documents'" class="card p-5">
                        <input ref="upload" type="file" class="sr-only" @change="uploadFile" />
                        <div v-if="can.act" class="mb-4"><AppButton size="sm" :icon="Upload" @click="pick(null)">Add document</AppButton></div>
                        <ul class="divide-y divide-line-soft">
                            <li v-for="d in contents.documents" :key="d.id" class="flex items-center gap-3 py-2.5 text-sm">
                                <FileText :size="16" class="text-muted" aria-hidden="true" />
                                <a :href="d.url + (d.preview ? '?inline=1' : '')" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate font-medium text-brand-700 hover:underline">{{ d.name }}</a>
                                <span class="text-xs text-muted">v{{ d.version }} · {{ date(d.uploaded_at) }}</span>
                                <span v-if="d.scan_status === 'unscanned'" class="text-xs text-amber-700">not scanned</span>
                                <AppButton v-if="can.act" size="sm" variant="ghost" @click="pick(d.db_id)">New version</AppButton>
                            </li>
                            <li v-if="!contents.documents.length" class="py-3 text-sm text-muted">No documents.</li>
                        </ul>
                    </section>

                    <!-- Correspondence -->
                    <section v-else-if="tab === 'correspondence'" class="card p-5">
                        <p class="mb-3 text-sm text-muted">Letters are diarised in <Link :href="route('correspondence.index')" class="text-brand-700 hover:underline">Correspondence</Link> and placed on the file from there.</p>
                        <ul class="divide-y divide-line-soft">
                            <li v-for="c in contents.correspondence" :key="c.id" class="py-3 text-sm">
                                <p><span class="font-mono text-xs text-muted">{{ c.register_number }}</span> <StatusBadge :status="c.direction === 'incoming' ? 'info' : 'sent'" :label="c.direction" class="ml-1" /></p>
                                <p class="font-medium text-ink">{{ c.subject }}</p>
                                <p class="text-xs text-muted">{{ c.direction === 'incoming' ? 'From' : 'To' }} {{ c.counterparty }}<template v-if="c.their_reference"> · their ref. {{ c.their_reference }}</template> · {{ c.register_date }}</p>
                                <a v-for="d in c.documents" :key="d.id" :href="d.url + (d.preview ? '?inline=1' : '')" target="_blank" rel="noopener" class="mr-3 text-xs text-brand-700 hover:underline">{{ d.name }}</a>
                            </li>
                            <li v-if="!contents.correspondence.length" class="py-3 text-sm text-muted">No correspondence on this file.</li>
                        </ul>
                    </section>

                    <!-- Movement -->
                    <section v-else-if="tab === 'movement'" class="card overflow-x-auto p-0">
                        <table class="data-table">
                            <thead><tr><th>Sent</th><th>From</th><th>To</th><th>For</th><th>Received</th></tr></thead>
                            <tbody>
                                <tr v-for="m in contents.movements" :key="m.id">
                                    <td class="text-xs whitespace-nowrap">{{ date(m.sent_at) }}</td>
                                    <td>{{ m.from }}<p class="text-xs text-muted">{{ m.from_department }}</p></td>
                                    <td>{{ m.to }}<p class="text-xs text-muted">{{ m.to_department }}</p></td>
                                    <td class="text-sm">{{ m.action }}<p v-if="m.note" class="text-xs text-muted">see note {{ m.note }}</p><p v-if="m.reason" class="text-xs text-muted">{{ m.reason }}</p></td>
                                    <td class="text-xs">
                                        <template v-if="m.status === 'received'">{{ date(m.acknowledged_at) }}<p v-if="m.remarks" class="text-muted">{{ m.remarks }}</p></template>
                                        <StatusBadge v-else :status="m.status === 'recalled' ? 'cancelled' : 'pending'" :label="m.status.replace('_', ' ')" />
                                    </td>
                                </tr>
                                <tr v-if="!contents.movements.length"><td colspan="5" class="text-center text-muted">The file has not moved.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <!-- History -->
                    <section v-else-if="tab === 'history'" class="card p-5">
                        <ol class="relative space-y-3 border-l border-line pl-5">
                            <li v-for="h in contents.history" :key="h.id" class="text-sm">
                                <span class="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-line-strong" aria-hidden="true" />
                                <p class="text-ink">{{ h.summary }}</p>
                                <p class="text-xs text-muted">{{ h.actor ?? 'System' }} · {{ date(h.at) }}</p>
                            </li>
                        </ol>
                    </section>

                    <!-- Access -->
                    <section v-else-if="tab === 'access'" class="card space-y-4 p-5">
                        <p class="text-sm text-muted">Besides everyone the file has moved between, these people may read it:</p>
                        <ul class="divide-y divide-line-soft">
                            <li v-for="g in contents.grants" :key="g.id" class="flex items-center justify-between py-2 text-sm">
                                <span><span class="font-medium text-ink">{{ g.user }}</span> · {{ g.reason }}<span v-if="g.expires_at" class="text-xs text-muted"> · until {{ date(g.expires_at) }}</span></span>
                                <AppButton size="sm" variant="danger-ghost" @click="revoke(g.id, g.user)">Revoke</AppButton>
                            </li>
                            <li v-if="!contents.grants.length" class="py-2 text-sm text-muted">Nobody else.</li>
                        </ul>
                        <form class="grid gap-3 border-t border-line-soft pt-4 sm:grid-cols-3" @submit.prevent="grant.post(route('efiles.grant', file.id), { preserveScroll: true, onSuccess: () => grant.reset() })">
                            <FormField label="Person" :error="grant.errors.user_id"><UserPicker v-model="grant.user_id" category="faculty,staff" /></FormField>
                            <FormField label="Reason" :error="grant.errors.reason"><TextInput v-model="grant.reason" /></FormField>
                            <FormField label="Until (optional)" :error="grant.errors.expires_at"><TextInput v-model="grant.expires_at" type="date" /></FormField>
                            <div class="sm:col-span-3"><AppButton type="submit" size="sm" :loading="grant.processing">Grant read access</AppButton></div>
                        </form>
                    </section>
                </template>
            </div>

            <aside class="space-y-4">
                <section v-if="can.act || can.reopen" class="card space-y-2 p-4">
                    <AppButton v-if="can.act" class="w-full" :icon="Send" @click="sendOpen = true">Send file</AppButton>
                    <DropdownMenu v-if="can.act && approvalWorkflows.length" align="left" width="w-full">
                        <template #trigger="{ toggle, open }">
                            <AppButton class="w-full" variant="outline" :icon="GitBranch" :aria-expanded="open" @click="toggle">Route for approval</AppButton>
                        </template>
                        <Link v-for="w in approvalWorkflows" :key="w.key" :href="route('requests.create', { definition: w.key, efile: file.id })" class="block px-4 py-2 text-sm text-ink hover:bg-surface-muted">{{ w.name }}</Link>
                    </DropdownMenu>
                    <AppButton v-if="can.act" class="w-full" variant="ghost" :icon="FolderLock" @click="closeOpen = true">Close file</AppButton>
                    <AppButton v-if="can.reopen" class="w-full" variant="outline" :icon="ArchiveRestore" @click="reopenOpen = true">Reopen</AppButton>
                </section>

                <section class="card p-4 text-sm">
                    <dl class="space-y-2">
                        <div><dt class="text-xs text-muted">With</dt><dd class="font-medium text-ink">{{ file.custodian.name }}</dd></div>
                        <div><dt class="text-xs text-muted">Opened</dt><dd class="text-ink">{{ file.opened_by }} · {{ date(file.opened_at) }}</dd></div>
                        <div><dt class="text-xs text-muted">Retention</dt><dd class="text-ink">{{ file.retention ? `${file.retention.name} (${file.retention.retain_years ?? 'permanent'}${file.retention.retain_years ? ' years' : ''}, then ${file.retention.action})` : 'Not set' }}</dd></div>
                        <div v-if="file.closed_at"><dt class="text-xs text-muted">Closed</dt><dd class="text-ink">{{ date(file.closed_at) }}<template v-if="file.retain_until"> · keep until {{ file.retain_until }}</template></dd></div>
                        <div v-if="file.closure_remarks"><dt class="text-xs text-muted">Closure remarks</dt><dd class="text-ink">{{ file.closure_remarks }}</dd></div>
                        <div v-if="file.description"><dt class="text-xs text-muted">Description</dt><dd class="whitespace-pre-line text-ink">{{ file.description }}</dd></div>
                    </dl>
                </section>

                <section v-if="contents" class="card p-4">
                    <h2 class="mb-2 text-sm font-semibold text-ink">Linked</h2>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="l in contents.links" :key="l.id" class="flex items-start gap-1.5">
                            <Link2 :size="14" class="mt-0.5 shrink-0 text-muted" aria-hidden="true" />
                            <span><a v-if="l.url" :href="l.url" class="text-brand-700 hover:underline">{{ l.label }}</a><template v-else>{{ l.label }}</template>
                                <span class="text-xs text-muted"> · {{ l.relation }}<template v-if="l.status"> · {{ l.status.replace('_', ' ') }}</template></span></span>
                        </li>
                        <li v-if="!contents.links.length" class="text-muted">Nothing linked.</li>
                    </ul>
                    <form v-if="can.act" class="mt-3 flex gap-1.5" @submit.prevent="link.post(route('efiles.link', file.id), { preserveScroll: true, onSuccess: () => link.reset() })">
                        <TextInput v-model="link.file_number" placeholder="Link file number" aria-label="File number to link" />
                        <AppButton type="submit" size="sm" variant="outline" :loading="link.processing">Link</AppButton>
                    </form>
                    <p v-if="link.errors.file_number" class="mt-1 text-xs text-red-600">{{ link.errors.file_number }}</p>
                </section>
            </aside>
        </div>

        <ModalDialog :show="sendOpen" title="Send file" description="The file stays with you, in transit, until the receiver acknowledges it." @close="sendOpen = false">
            <form class="space-y-4" @submit.prevent="doSend">
                <FormField label="To" :error="send.errors.to" required><UserPicker v-model="send.to" category="faculty,staff" /></FormField>
                <FormField label="For" :error="send.errors.required_action" required><SelectInput v-model="send.required_action" :options="Object.entries(actions).map(([value, label]) => ({ value, label }))" /></FormField>
                <FormField label="With reference to" :error="send.errors.note_id"><SelectInput v-model="send.note_id" :options="finalNotes.map((n) => ({ value: n.id, label: `Note ${n.number} (${n.kind})` }))" placeholder="No particular note" /></FormField>
                <FormField label="Remarks" :error="send.errors.reason ?? (send.errors as Record<string, string>).workflow"><TextArea v-model="send.reason" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="sendOpen = false">Cancel</AppButton><AppButton type="submit" :icon="Send" :loading="send.processing">Send</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="closeOpen" title="Close file" :description="`Retention starts now${file.retention ? `: ${file.retention.name}` : ''}. Draft notes must be finalised or discarded first.`" @close="closeOpen = false">
            <form class="space-y-4" @submit.prevent="closing.post(route('efiles.close', file.id), { onSuccess: () => (closeOpen = false) })">
                <FormField label="Closure remarks" :error="closing.errors.remarks ?? (closing.errors as Record<string, string>).workflow" required><TextArea v-model="closing.remarks" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="closeOpen = false">Cancel</AppButton><AppButton type="submit" :icon="CheckCheck" :loading="closing.processing">Close file</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="reopenOpen" title="Reopen file" @close="reopenOpen = false">
            <form class="space-y-4" @submit.prevent="reopenForm.post(route('efiles.reopen', file.id), { onSuccess: () => (reopenOpen = false) })">
                <FormField label="Reason" :error="reopenForm.errors.reason" required><TextArea v-model="reopenForm.reason" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="reopenOpen = false">Cancel</AppButton><AppButton type="submit" :loading="reopenForm.processing">Reopen</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
