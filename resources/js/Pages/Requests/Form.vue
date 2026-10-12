<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextArea from '@/Components/TextArea.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DynamicForm from '@/Workflow/DynamicForm.vue';
import type { FormSchema } from '@/Workflow/types';
import type { RequestPayload } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    workflow: { key: string; name: string; description: string | null };
    schema: FormSchema;
    data: Record<string, unknown>;
    instance: { id: number; reference: string | null; status: string; returned: { by: string | null; remarks: string | null; kind: string } | null } | null;
    mode: 'create' | 'draft' | 'resubmit';
    efile?: { id: number; file_number: string; subject: string } | null;
}>();

const data = ref<Record<string, unknown>>({ ...props.data });
const remarks = ref('');
const processing = ref(false);
const errors = computed(() => usePage().props.errors as Record<string, string>);

function send(submit: boolean) {
    const payload = { data: data.value, submit, remarks: remarks.value, efile: props.efile?.id } as unknown as RequestPayload;
    const opts = { preserveScroll: true, onStart: () => (processing.value = true), onFinish: () => (processing.value = false) };
    if (props.mode === 'create') router.post(route('requests.store', props.workflow.key), payload, opts);
    else router.put(route('requests.update', props.instance!.id), payload, opts);
}
const title = computed(() => (props.mode === 'resubmit' ? `Resubmit ${props.instance?.reference}` : props.mode === 'draft' ? `Draft: ${props.workflow.name}` : props.workflow.name));
const firstError = computed(() => Object.values(errors.value)[0]);
</script>

<template>
    <AppLayout :title="title">
        <PageHeader :title="title" :description="workflow.description ?? undefined" />

        <AlertBox v-if="efile" tone="info" class="mb-6">Routing file <strong>{{ efile.file_number }}</strong> ({{ efile.subject }}). Approvers will be able to read the file; the outcome is recorded in its history.</AlertBox>
        <AlertBox v-if="instance?.returned" tone="warning" :title="instance.returned.kind === 'documents' ? 'Additional documents requested' : 'Returned for clarification'" class="mb-6">
            <p><strong>{{ instance.returned.by }}</strong>: {{ instance.returned.remarks }}</p>
            <p class="mt-1 text-xs">Change what is needed and resubmit. The request continues from the step that returned it.</p>
        </AlertBox>
        <AlertBox v-if="firstError" tone="danger" class="mb-6">{{ errors.workflow ?? 'Please correct the highlighted fields.' }}</AlertBox>

        <form class="space-y-6" novalidate @submit.prevent="send(true)">
            <DynamicForm v-if="schema.sections.length" v-model="data" :schema="schema" :errors="errors" />
            <div v-else class="card p-6 text-sm text-muted">This service needs no form. Submit to start it.</div>

            <div v-if="mode === 'resubmit'" class="card p-6">
                <FormField label="Your reply" hint="Explain what you changed (optional)."><TextArea v-model="remarks" rows="3" /></FormField>
            </div>

            <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t border-line bg-canvas/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
                <AppButton v-if="mode !== 'resubmit'" variant="outline" :disabled="processing" @click="send(false)">Save draft</AppButton>
                <AppButton type="submit" :loading="processing">{{ mode === 'resubmit' ? 'Resubmit' : 'Submit' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
