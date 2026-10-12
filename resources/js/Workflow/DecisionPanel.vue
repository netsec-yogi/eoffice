<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import TextArea from '@/Components/TextArea.vue';
import UserPicker from '@/Components/UserPicker.vue';
import { ask } from '@/lib/confirm';
import type { RequestPayload } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRightLeft, Check, Pause, Undo2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DynamicForm from './DynamicForm.vue';
import { date } from './format';
import type { FormSchema } from './types';

export interface MyTask {
    id: number;
    node_key: string;
    name: string;
    kind: string;
    actions: string[];
    labels: Record<string, string>;
    instructions: string | null;
    require_remarks_on: string[];
    editable_fields: string[];
    on_behalf_of: string | null;
    due_at: string | null;
}
const props = defineProps<{ task: MyTask; instanceId: number; schema: FormSchema; data: Record<string, unknown> }>();

const remarks = ref('');
const returnKind = ref<'clarification' | 'documents'>('clarification');
const forwardTo = ref<number | null>(null);
const mode = ref<'decide' | 'forward'>('decide');
const busy = ref(false);
const edits = ref<Record<string, unknown>>({ ...props.data });
const errors = computed(() => usePage().props.errors as Record<string, string>);

const defaults: Record<string, string> = { approve: 'Approve', reject: 'Reject', return: 'Return to initiator', abstain: 'Abstain', complete: 'Mark as done', fail: 'Mark as failed' };
const label = (a: string) => props.task.labels?.[a] ?? defaults[a] ?? a;
const decisive = computed(() => props.task.actions.filter((a) => ['approve', 'reject', 'return', 'abstain', 'complete', 'fail'].includes(a)));
const icon = (a: string) => ({ approve: Check, complete: Check, reject: X, fail: X, return: Undo2 })[a];
const variant = (a: string) => (['approve', 'complete'].includes(a) ? 'success' : ['reject', 'fail'].includes(a) ? 'danger' : 'outline');
const needsRemarks = (a: string) => props.task.require_remarks_on.includes(a);

async function decide(action: string) {
    if (needsRemarks(action) && !remarks.value.trim()) {
        remarks.value = remarks.value.trim();
        document.getElementById(`remarks-${props.task.id}`)?.focus();
        return;
    }
    if (['reject', 'fail'].includes(action) && !(await ask(`${label(action)} this request? This ends it and cannot be undone.`))) return;
    router.post(
        route('tasks.decide', props.task.id),
        { action, remarks: remarks.value, return_kind: action === 'return' ? returnKind.value : null, data: props.task.editable_fields.length ? edits.value : null } as unknown as RequestPayload,
        { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) },
    );
}
const forward = () =>
    router.post(route('tasks.forward', props.task.id), { to: forwardTo.value, remarks: remarks.value }, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
async function hold() {
    if (!remarks.value.trim()) return document.getElementById(`remarks-${props.task.id}`)?.focus();
    router.post(route('requests.hold', props.instanceId), { reason: remarks.value }, { preserveScroll: true });
}
</script>

<template>
    <section class="card overflow-hidden ring-2 ring-brand-500/60" :aria-label="`Your action: ${task.name}`">
        <header class="bg-brand-50 px-5 py-3 dark:bg-brand-500/10">
            <p class="text-xs font-semibold tracking-wide text-brand-700 uppercase">Your action</p>
            <h2 class="font-semibold text-ink">{{ task.name }}</h2>
            <p class="text-xs text-muted">
                <template v-if="task.on_behalf_of">As delegate of {{ task.on_behalf_of }} · </template>
                <template v-if="task.due_at">Due {{ date(task.due_at) }}</template>
            </p>
        </header>
        <div class="space-y-4 p-5">
            <p v-if="task.instructions" class="rounded-xl bg-surface-muted px-3 py-2 text-sm whitespace-pre-line text-ink-soft">{{ task.instructions }}</p>
            <AlertBox v-if="errors.workflow" tone="danger">{{ errors.workflow }}</AlertBox>

            <div v-if="task.editable_fields.length" class="-mx-1">
                <p class="mb-2 text-sm font-medium text-ink">Fields to complete at this step</p>
                <DynamicForm v-model="edits" :schema="{ sections: schema.sections.map((s) => ({ ...s, fields: s.fields.filter((f) => task.editable_fields.includes(f.key)) })).filter((s) => s.fields.length) }" :editable-at="task.node_key" :errors="errors" />
            </div>

            <template v-if="mode === 'decide'">
                <FormField :label="'Remarks'" :hint="task.require_remarks_on.length ? `Required to ${task.require_remarks_on.map((a) => label(a).toLowerCase()).join(' or ')}.` : 'Visible to the initiator and later approvers.'" :error="errors.remarks">
                    <TextArea :id="`remarks-${task.id}`" v-model="remarks" rows="3" />
                </FormField>
                <fieldset v-if="task.actions.includes('return')" class="flex flex-wrap gap-4 text-sm">
                    <legend class="mb-1 text-xs text-muted">If returning, ask for</legend>
                    <label class="flex items-center gap-2"><input v-model="returnKind" type="radio" value="clarification" class="text-brand-600" /> Clarification</label>
                    <label class="flex items-center gap-2"><input v-model="returnKind" type="radio" value="documents" class="text-brand-600" /> Additional documents</label>
                </fieldset>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-for="a in decisive" :key="a" :variant="variant(a)" :icon="icon(a)" :loading="busy" @click="decide(a)">{{ label(a) }}</AppButton>
                </div>
                <div v-if="task.actions.includes('forward') || task.actions.includes('hold')" class="flex flex-wrap gap-2 border-t border-line-soft pt-3">
                    <AppButton v-if="task.actions.includes('forward')" size="sm" variant="ghost" :icon="ArrowRightLeft" @click="mode = 'forward'">Forward to someone else</AppButton>
                    <AppButton v-if="task.actions.includes('hold')" size="sm" variant="ghost" :icon="Pause" @click="hold">Put on hold (uses remarks as reason)</AppButton>
                </div>
            </template>

            <template v-else>
                <FormField label="Forward to" required :error="errors.to"><UserPicker v-model="forwardTo" /></FormField>
                <FormField label="Note" :error="errors.remarks"><TextArea v-model="remarks" rows="2" /></FormField>
                <div class="flex gap-2">
                    <AppButton :loading="busy" :disabled="!forwardTo" @click="forward">Forward</AppButton>
                    <AppButton variant="ghost" @click="mode = 'decide'">Back</AppButton>
                </div>
            </template>
        </div>
    </section>
</template>
