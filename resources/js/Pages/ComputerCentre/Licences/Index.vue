<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/Workflow/format';
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, UserPlus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Title { id: number; code: string; name: string; publisher: string | null; licence_type: string; seats: number | null; agreement_reference: string | null; is_active: boolean; expires_on: string | null; used: number; expiring: boolean }
interface Alloc { id: number; title: string; code: string; user: { name: string; username: string }; allocated_at: string; workflow_instance_id: number | null }
defineProps<{ titles: Title[]; allocations: Alloc[] }>();

const editing = ref<Title | null>(null);
const open = ref(false);
const form = useForm({ code: '', name: '', publisher: '', licence_type: 'per_seat', seats: null as number | null, expires_on: '', agreement_reference: '', is_active: true });
function edit(t: Title | null) {
    editing.value = t;
    form.clearErrors();
    Object.assign(form, t ? { ...t, publisher: t.publisher ?? '', expires_on: t.expires_on ?? '', agreement_reference: t.agreement_reference ?? '' } : { code: '', name: '', publisher: '', licence_type: 'per_seat', seats: null, expires_on: '', agreement_reference: '', is_active: true });
    open.value = true;
}
const save = () => (editing.value ? form.put(route('cc.licences.update', editing.value.id), { onSuccess: () => (open.value = false) }) : form.post(route('cc.licences.store'), { onSuccess: () => (open.value = false) }));
const allocTo = ref<Title | null>(null);
const alloc = useForm({ user_id: null as number | null });
const doAlloc = () => alloc.post(route('cc.licences.allocate', allocTo.value!.id), { preserveScroll: true, onSuccess: () => ((allocTo.value = null), alloc.reset()) });
const revoking = ref<Alloc | null>(null);
const revoke = useForm({ reason: '' });
const doRevoke = () => revoke.post(route('cc.licences.revoke', revoking.value!.id), { preserveScroll: true, onSuccess: () => (revoking.value = null) });
</script>

<template>
    <AppLayout title="Software licences">
        <PageHeader title="Software licences" description="Seats, renewals and who holds them. Requests through the Software licence workflow are allocated automatically when seats are free.">
            <AppButton :icon="Plus" @click="edit(null)">Software</AppButton>
        </PageHeader>
        <DataTable :empty="!titles.length" empty-title="No software registered" class="mb-8">
            <table class="data-table">
                <thead><tr><th>Software</th><th>Type</th><th>Seats used</th><th>Expires</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="t in titles" :key="t.id">
                        <td><span class="font-medium text-ink">{{ t.name }}</span><p class="font-mono text-xs text-muted">{{ t.code }}<template v-if="t.publisher"> · {{ t.publisher }}</template></p></td>
                        <td class="capitalize">{{ t.licence_type.replace('_', ' ') }}</td>
                        <td :class="t.seats !== null && t.used >= t.seats ? 'font-semibold text-red-700' : ''">{{ t.used }} / {{ t.seats ?? '∞' }}</td>
                        <td :class="t.expiring && 'font-semibold text-amber-700'">{{ t.expires_on ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton variant="ghost" size="sm" :icon="UserPlus" :aria-label="`Allocate ${t.name}`" @click="allocTo = t" />
                            <AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${t.name}`" @click="edit(t)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
        <h2 class="mb-3 font-semibold text-ink">Current allocations</h2>
        <DataTable :empty="!allocations.length" empty-title="No seats allocated">
            <table class="data-table">
                <thead><tr><th>Software</th><th>Holder</th><th>Since</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="a in allocations" :key="a.id">
                        <td>{{ a.title }}</td><td>{{ a.user.name }} <span class="text-xs text-muted">{{ a.user.username }}</span></td><td class="text-xs">{{ date(a.allocated_at) }}</td>
                        <td class="text-right"><AppButton variant="danger-ghost" size="sm" @click="(revoking = a), revoke.reset()">Release</AppButton></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
        <ModalDialog :show="open" :title="editing ? 'Edit software' : 'Add software'" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" hint="Used in request forms, e.g. matlab" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                    <FormField label="Publisher" :error="form.errors.publisher"><TextInput v-model="form.publisher" /></FormField>
                    <FormField label="Licence type" :error="form.errors.licence_type"><SelectInput v-model="form.licence_type" :options="['per_seat', 'site', 'subscription', 'open_source'].map((v) => ({ value: v, label: v.replace('_', ' ') }))" /></FormField>
                    <FormField label="Seats" hint="Empty = unlimited (site licence)" :error="form.errors.seats"><TextInput v-model="form.seats" type="number" min="0" /></FormField>
                    <FormField label="Expires on" :error="form.errors.expires_on"><TextInput v-model="form.expires_on" type="date" /></FormField>
                    <FormField label="Agreement / PO reference" :error="form.errors.agreement_reference" class="sm:col-span-2"><TextInput v-model="form.agreement_reference" /></FormField>
                </div>
                <CheckboxInput v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!allocTo" :title="`Allocate: ${allocTo?.name ?? ''}`" @close="allocTo = null">
            <form class="space-y-4" @submit.prevent="doAlloc">
                <FormField label="Person" :error="alloc.errors.user_id ?? (alloc.errors as Record<string, string>).software" required><UserPicker v-model="alloc.user_id" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="allocTo = null">Cancel</AppButton><AppButton type="submit" :loading="alloc.processing">Allocate</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!revoking" title="Release seat" size="sm" @close="revoking = null">
            <form class="space-y-4" @submit.prevent="doRevoke">
                <FormField label="Reason" :error="revoke.errors.reason" required><TextInput v-model="revoke.reason" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="revoking = null">Cancel</AppButton><AppButton type="submit" variant="danger" :loading="revoke.processing">Release</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
