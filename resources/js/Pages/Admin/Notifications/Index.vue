<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, RotateCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Template { id: number; key: string; channel: string; subject: string | null; body: string; provider_template_id: string | null; is_active: boolean }
interface Message { id: number; template_key: string; channel: string; status: string; attempts: number; last_error: string | null; user: { name: string; username: string } | null; created_at: string }

const props = defineProps<{ templates: Template[]; channels: string[]; status: string; counts: Record<string, number>; messages: Paginated<Message> }>();

const grouped = computed(() => {
    const g: Record<string, Template[]> = {};
    props.templates.forEach((t) => (g[t.key] ??= []).push(t));
    return g;
});

const editing = ref<Template | null>(null);
const form = useForm({ subject: '', body: '', provider_template_id: '', is_active: true });
function edit(t: Template) {
    editing.value = t;
    Object.assign(form, { subject: t.subject ?? '', body: t.body, provider_template_id: t.provider_template_id ?? '', is_active: t.is_active });
    form.clearErrors();
}
const save = () => form.put(route('admin.notifications.templates.update', editing.value!.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const retry = (m: Message) => router.post(route('admin.notifications.retry', m.id), {}, { preserveScroll: true });
const setStatus = (s?: string) => router.get(route('admin.notifications.index'), { status: s }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader title="Notifications" description="Templates per event and channel, and delivery monitoring. Placeholders like {{ instance.reference }} are filled when a message is sent." />

        <h2 class="mb-3 font-semibold text-ink">Delivery</h2>
        <DataTable :empty="!messages.data.length" :empty-title="`No ${status} messages`" class="mb-8">
            <template #toolbar>
                <TabsNav
                    :model-value="status"
                    :items="['failed', 'queued', 'skipped', 'sent'].map((s) => ({ key: s, label: s, count: counts[s] ?? 0 }))"
                    @update:model-value="setStatus"
                />
            </template>
            <table class="data-table">
                <thead><tr><th>When</th><th>Recipient</th><th>Template</th><th>Channel</th><th>Status</th><th>Error</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="m in messages.data" :key="m.id">
                        <td class="text-xs whitespace-nowrap">{{ new Date(m.created_at).toLocaleString() }}</td>
                        <td>{{ m.user?.name ?? '—' }}</td>
                        <td class="font-mono text-xs">{{ m.template_key }}</td>
                        <td>{{ m.channel }}</td>
                        <td><StatusBadge :status="m.status" /> <span class="text-xs text-muted">×{{ m.attempts }}</span></td>
                        <td class="max-w-xs truncate text-xs text-red-700" :title="m.last_error ?? ''">{{ m.last_error ?? '' }}</td>
                        <td class="text-right"><AppButton v-if="m.status === 'failed'" size="sm" variant="ghost" :icon="RotateCw" @click="retry(m)">Retry</AppButton></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="messages.links" :from="messages.from" :to="messages.to" :total="messages.total" /></template>
        </DataTable>

        <h2 class="mb-3 font-semibold text-ink">Templates</h2>
        <div class="grid gap-4 lg:grid-cols-2">
            <section v-for="(list, key) in grouped" :key="key" class="card p-5">
                <h3 class="font-mono text-sm font-semibold text-ink">{{ key }}</h3>
                <ul class="mt-3 divide-y divide-line-soft">
                    <li v-for="t in list" :key="t.id" class="flex items-start justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">{{ t.channel }} <StatusBadge :status="t.is_active ? 'active' : 'inactive'" class="ml-1" /></p>
                            <p class="truncate text-xs text-muted">{{ t.subject ?? t.body }}</p>
                        </div>
                        <AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${t.key} ${t.channel}`" @click="edit(t)" />
                    </li>
                </ul>
            </section>
        </div>

        <ModalDialog :show="!!editing" :title="`${editing?.key} · ${editing?.channel}`" size="lg" @close="editing = null">
            <form class="space-y-4" @submit.prevent="save">
                <FormField v-if="editing?.channel === 'mail' || editing?.channel === 'in_app'" label="Subject / title" :error="form.errors.subject"><TextInput v-model="form.subject" /></FormField>
                <FormField label="Body" :error="form.errors.body"><TextArea v-model="form.body" rows="7" class="font-mono text-xs" /></FormField>
                <FormField v-if="editing?.channel === 'sms' || editing?.channel === 'whatsapp'" label="Provider template ID" hint="DLT / WhatsApp Business template registration; required by the provider." :error="form.errors.provider_template_id">
                    <TextInput v-model="form.provider_template_id" />
                </FormField>
                <CheckboxInput v-model="form.is_active" label="Send on this channel" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="editing = null">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
