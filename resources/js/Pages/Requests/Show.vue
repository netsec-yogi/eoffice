<script setup lang="ts">
import OfficialDocumentList from '@/Pages/OfficialDocuments/OfficialDocumentList.vue';
import type { OfficialDocumentRow } from '@/Pages/OfficialDocuments/types';
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import UserPicker from '@/Components/UserPicker.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DecisionPanel, { type MyTask } from '@/Workflow/DecisionPanel.vue';
import DynamicForm from '@/Workflow/DynamicForm.vue';
import { date, statusLabel } from '@/Workflow/format';
import Timeline, { type TimelineEntry } from '@/Workflow/Timeline.vue';
import type { DocumentSummary, FormSchema } from '@/Workflow/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, CircleCheck, CircleDot, Circle, FileText, Pencil, Play, ShieldAlert } from 'lucide-vue-next';
import { ref } from 'vue';

interface Task { id: number; name: string; kind: string; status: string; outcome: string | null; assignee: string | null; principal: string | null; due_at: string | null; overdue: boolean; completed_at: string | null }
const props = defineProps<{
    instance: {
        id: number;
        reference: string | null;
        title: string;
        status: string;
        outcome: string | null;
        workflow: string;
        version: number;
        revision: number;
        initiator: { id: number; name: string; designation: string | null; department: string | null };
        submitted_at: string | null;
        completed_at: string | null;
        schema: FormSchema;
        data: Record<string, unknown>;
        display: Record<string, unknown>;
        timeline: TimelineEntry[];
        tasks: Task[];
        progress: { key: string; name: string; state: 'done' | 'current' | 'upcoming' }[];
        my_tasks: MyTask[];
        documents: DocumentSummary[];
        official_documents: OfficialDocumentRow[];
        can: { edit: boolean; cancel: boolean; intervene: boolean; resume: boolean };
    };
    focusTask: number | null;
}>();

const data = ref({ ...props.instance.data });
const cancelOpen = ref(false);
const cancel = useForm({ reason: '' });
const doCancel = () => cancel.post(route('requests.cancel', props.instance.id), { preserveScroll: true, onSuccess: () => (cancelOpen.value = false) });
const resume = () => router.post(route('requests.resume', props.instance.id), {}, { preserveScroll: true });

const reassigning = ref<Task | null>(null);
const reassign = useForm({ to: null as number | null, reason: '' });
const doReassign = () => reassign.post(route('tasks.reassign', reassigning.value!.id), { preserveScroll: true, onSuccess: () => (reassigning.value = null) });
const pending = () => props.instance.tasks.filter((t) => t.status === 'pending');
// Where the viewer came from: their own requests, or their inbox.
const mine = props.instance.initiator.id === usePage().props.auth.user!.id;
const crumbs = [mine ? { label: 'My requests', href: route('requests.index') } : { label: 'Inbox', href: route('tasks.index') }, { label: props.instance.reference ?? 'Draft' }];
</script>

<template>
    <AppLayout :title="instance.reference ?? instance.title">
        <Breadcrumbs :items="crumbs" />
        <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ instance.reference ?? 'Draft' }} · {{ instance.workflow }} (v{{ instance.version }})</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">{{ instance.title }}</h1>
                <p class="mt-1 text-sm text-muted">
                    {{ instance.initiator.name }}<template v-if="instance.initiator.designation">, {{ instance.initiator.designation }}</template
                    ><template v-if="instance.initiator.department"> · {{ instance.initiator.department }}</template> · submitted {{ date(instance.submitted_at) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :status="instance.status" :label="statusLabel(instance.status)" class="text-sm" />
                <AppButton v-if="instance.can.edit" size="sm" :icon="Pencil" :href="route('requests.edit', instance.id)">{{ instance.status === 'returned' ? 'Answer and resubmit' : 'Edit draft' }}</AppButton>
                <AppButton v-if="instance.can.resume" size="sm" variant="outline" :icon="Play" @click="resume">Resume</AppButton>
                <AppButton v-if="instance.can.cancel" size="sm" variant="danger-ghost" :icon="Ban" @click="cancelOpen = true">Cancel request</AppButton>
            </div>
        </header>

        <AlertBox v-if="instance.status === 'returned' && instance.can.edit" tone="warning" class="mb-6">This request was returned to you. Open it, make the changes asked for, and resubmit.</AlertBox>
        <AlertBox v-if="instance.status === 'on_hold'" tone="info" class="mb-6">On hold. Deadlines are paused until it is resumed.</AlertBox>

        <!-- Progress -->
        <nav v-if="instance.progress.length" aria-label="Progress" class="card mb-6 overflow-x-auto p-4">
            <ol class="flex min-w-max items-center gap-2 text-sm">
                <li v-for="(step, i) in instance.progress" :key="step.key" class="flex items-center gap-2">
                    <span :class="['flex items-center gap-1.5 rounded-full px-3 py-1', step.state === 'current' ? 'bg-brand-600 font-semibold text-white' : step.state === 'done' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-surface-sunken text-muted']" :aria-current="step.state === 'current' ? 'step' : undefined">
                        <CircleCheck v-if="step.state === 'done'" :size="14" aria-hidden="true" />
                        <CircleDot v-else-if="step.state === 'current'" :size="14" aria-hidden="true" />
                        <Circle v-else :size="14" aria-hidden="true" />
                        {{ step.name }}
                        <span class="sr-only">({{ step.state }})</span>
                    </span>
                    <span v-if="i < instance.progress.length - 1" class="h-px w-6 bg-line-strong" aria-hidden="true" />
                </li>
            </ol>
        </nav>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <DecisionPanel v-for="t in instance.my_tasks" :key="t.id" :task="t" :instance-id="instance.id" :schema="instance.schema" :data="instance.data" />

                <DynamicForm v-if="instance.schema.sections.length" v-model="data" :schema="instance.schema" :display="instance.display" readonly />

                <section v-if="instance.documents.length" class="card p-6">
                    <h2 class="mb-3 font-semibold text-ink">Documents</h2>
                    <ul class="divide-y divide-line-soft">
                        <li v-for="d in instance.documents" :key="d.id" class="flex items-center gap-3 py-2 text-sm">
                            <FileText :size="16" class="text-muted" aria-hidden="true" />
                            <a :href="d.url + (d.preview ? '?inline=1' : '')" target="_blank" rel="noopener" class="flex-1 truncate font-medium text-brand-700 hover:underline">{{ d.name }}</a>
                            <span v-if="d.scan_status === 'unscanned'" class="flex items-center gap-1 text-xs text-amber-700"><ShieldAlert :size="13" aria-hidden="true" /> not scanned</span>
                        </li>
                    </ul>
                </section>
            </div>

            <aside class="space-y-6">
                <section v-if="instance.official_documents.length" class="card p-5">
                    <h2 class="mb-1 font-semibold text-ink">Official documents</h2>
                    <p class="mb-2 text-xs text-muted">Issued on the institute's form once the request is finally approved.</p>
                    <OfficialDocumentList :documents="instance.official_documents" />
                </section>
                <section v-if="pending().length" class="card p-5">
                    <h2 class="mb-3 font-semibold text-ink">With</h2>
                    <ul class="space-y-3">
                        <li v-for="t in pending()" :key="t.id" class="text-sm">
                            <p class="font-medium text-ink">{{ t.assignee }}<span v-if="t.principal" class="font-normal text-muted"> for {{ t.principal }}</span></p>
                            <p class="text-xs text-muted">{{ t.name }} · <span :class="t.overdue && 'font-semibold text-red-600'">{{ t.due_at ? `due ${date(t.due_at)}` : 'no deadline' }}{{ t.overdue ? ' (overdue)' : '' }}</span></p>
                            <button v-if="instance.can.intervene" class="mt-1 text-xs font-medium text-brand-600 hover:underline" @click="(reassigning = t), reassign.reset()">Reassign</button>
                        </li>
                    </ul>
                </section>
                <section class="card p-5">
                    <h2 class="mb-4 font-semibold text-ink">History</h2>
                    <Timeline :entries="instance.timeline" />
                </section>
            </aside>
        </div>

        <ModalDialog :show="cancelOpen" title="Cancel this request?" description="Pending steps are withdrawn and everyone involved is told. This cannot be undone." @close="cancelOpen = false">
            <form class="space-y-4" @submit.prevent="doCancel">
                <FormField label="Reason" :error="cancel.errors.reason" required><TextArea v-model="cancel.reason" rows="3" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="cancelOpen = false">Keep it</AppButton><AppButton type="submit" variant="danger" :loading="cancel.processing">Cancel request</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="!!reassigning" :title="`Reassign: ${reassigning?.name ?? ''}`" :description="`Currently with ${reassigning?.assignee}. The change is recorded in the history and the audit log.`" @close="reassigning = null">
            <form class="space-y-4" @submit.prevent="doReassign">
                <FormField label="New assignee" :error="reassign.errors.to" required><UserPicker v-model="reassign.to" /></FormField>
                <FormField label="Reason" :error="reassign.errors.reason ?? (reassign.errors as Record<string, string>).workflow" required><TextArea v-model="reassign.reason" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="reassigning = null">Cancel</AppButton><AppButton type="submit" :loading="reassign.processing">Reassign</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
