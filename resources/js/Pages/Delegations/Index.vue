<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { date } from '@/Workflow/format';
import { router, useForm } from '@inertiajs/vue3';

interface D { id: number; reason: string; status: string; workflow_categories: string[] | null; allowed_actions: string[] | null; delegator: { name: string }; delegate: { name: string }; starts_at: string; ends_at: string; in_force: boolean }
defineProps<{ given: D[]; received: D[]; categories: string[]; canManage: boolean }>();

const local = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const form = useForm({ delegate_id: null as number | null, starts_at: local(new Date()), ends_at: local(new Date(Date.now() + 7 * 864e5)), reason: '', workflow_categories: [] as string[], allowed_actions: [] as string[] });
const save = () => form.post(route('delegations.store'), { preserveScroll: true, onSuccess: () => form.reset() });
async function revoke(d: D) {
    if (await ask(`Revoke the delegation to ${d.delegate.name}? Pending work returns to you immediately.`)) router.delete(route('delegations.destroy', d.id), { preserveScroll: true });
}
const status = (d: D) => (d.status === 'revoked' ? 'revoked' : d.in_force ? 'active' : new Date(d.ends_at) < new Date() ? 'expired' : d.status === 'active' ? 'scheduled' : d.status);
const scope = (d: D) => [(d.workflow_categories ?? ['all workflows']).join(', ').replace(/_/g, ' '), d.allowed_actions ? `only: ${d.allowed_actions.join(', ')}` : 'all actions'].join(' · ');
</script>

<template>
    <AppLayout title="Delegations">
        <PageHeader title="Delegations" description="While you are away, someone else can act on your approvals. The history always shows that they acted for you. They cannot approve their own requests." />

        <form class="card mb-8 space-y-4 p-6" @submit.prevent="save">
            <h2 class="font-semibold text-ink">Delegate my approvals</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField label="Delegate" :error="form.errors.delegate_id" required><UserPicker v-model="form.delegate_id" category="faculty,staff" /></FormField>
                <FormField label="From" :error="form.errors.starts_at" required><TextInput v-model="form.starts_at" type="datetime-local" /></FormField>
                <FormField label="Until" :error="form.errors.ends_at" required><TextInput v-model="form.ends_at" type="datetime-local" /></FormField>
            </div>
            <FormField label="Reason" :error="form.errors.reason" required><TextArea v-model="form.reason" rows="2" /></FormField>
            <details class="text-sm">
                <summary class="cursor-pointer font-medium text-brand-700">Limit what is delegated (optional)</summary>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <fieldset><legend class="mb-1.5 text-xs text-muted">Only these kinds of workflow</legend>
                        <div class="grid grid-cols-2 gap-1.5"><CheckboxInput v-for="c in categories" :key="c" v-model="form.workflow_categories" :value="c" :label="c.replace(/_/g, ' ')" /></div>
                    </fieldset>
                    <fieldset><legend class="mb-1.5 text-xs text-muted">Only these actions</legend>
                        <div class="grid grid-cols-2 gap-1.5"><CheckboxInput v-for="a in ['approve', 'reject', 'return', 'forward', 'hold']" :key="a" v-model="form.allowed_actions" :value="a" :label="a" /></div>
                    </fieldset>
                </div>
            </details>
            <div class="flex justify-end"><AppButton type="submit" :loading="form.processing">Save delegation</AppButton></div>
        </form>

        <h2 class="mb-3 font-semibold text-ink">Given by me</h2>
        <DataTable :empty="!given.length" empty-title="You have not delegated anything" class="mb-8">
            <table class="data-table">
                <thead><tr><th>To</th><th>Period</th><th>Scope</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="d in given" :key="d.id">
                        <td class="font-medium text-ink">{{ d.delegate.name }}<p class="text-xs font-normal text-muted">{{ d.reason }}</p></td>
                        <td class="text-xs">{{ date(d.starts_at) }} → {{ date(d.ends_at) }}</td>
                        <td class="text-xs">{{ scope(d) }}</td>
                        <td><StatusBadge :status="status(d)" /></td>
                        <td class="text-right"><AppButton v-if="d.status !== 'revoked' && new Date(d.ends_at) > new Date()" size="sm" variant="danger-ghost" @click="revoke(d)">Revoke</AppButton></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <h2 class="mb-3 font-semibold text-ink">Given to me</h2>
        <DataTable :empty="!received.length" empty-title="Nobody has delegated work to you">
            <table class="data-table">
                <thead><tr><th>From</th><th>Period</th><th>Scope</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="d in received" :key="d.id">
                        <td class="font-medium text-ink">{{ d.delegator.name }}</td>
                        <td class="text-xs">{{ date(d.starts_at) }} → {{ date(d.ends_at) }}</td>
                        <td class="text-xs">{{ scope(d) }}</td>
                        <td><StatusBadge :status="status(d)" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
