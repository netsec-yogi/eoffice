<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { router, useForm } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Vendor { id: number; code: string; name: string; gstin: string | null; pan: string | null; address: string | null; email: string | null; phone: string | null; ifsc: string | null; msme: boolean; is_active: boolean; account: string | null; portal: { username: string; active: boolean } | null }
const props = defineProps<{ q: string | null; vendors: Vendor[] }>();

const search = ref(props.q ?? '');
let timer: ReturnType<typeof setTimeout>;
watch(search, (q) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('vendors.index'), q ? { q } : {}, { preserveState: true, replace: true }), 300);
});

const blank = { code: '', name: '', gstin: '', pan: '', address: '', email: '', phone: '', bank_account: '', ifsc: '', msme: false, is_active: true };
const form = useForm({ ...blank });
const editing = ref<Vendor | null>(null);
const open = ref(false);
function edit(v: Vendor | null) {
    editing.value = v;
    form.clearErrors();
    Object.assign(form, v ? { ...blank, ...Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x ?? ''])), bank_account: '' } : blank);
    open.value = true;
}
const save = () => (editing.value ? form.put(route('vendors.update', editing.value.id), { onSuccess: () => (open.value = false) }) : form.post(route('vendors.store'), { onSuccess: () => (open.value = false) }));
async function account(v: Vendor) {
    const text = v.portal ? `Reset the portal password for ${v.name}? The old password stops working.` : `Create a portal sign-in for ${v.name} (${v.email})?`;
    if (await ask(text, { confirmLabel: v.portal ? 'Reset' : 'Create' })) router.post(route('vendors.account', v.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Vendors">
        <PageHeader title="Vendors" description="The vendor register. Bank details are encrypted and shown masked; vendors see only their own orders and bills in their portal.">
            <AppButton :icon="Plus" @click="edit(null)">Vendor</AppButton>
        </PageHeader>
        <TextInput v-model="search" :icon="Search" placeholder="Name, code or GSTIN" class="mb-4 !w-72" aria-label="Search vendors" />

        <DataTable :empty="!vendors.length" empty-title="No vendors">
            <table class="data-table">
                <thead><tr><th>Vendor</th><th>GSTIN / PAN</th><th>Bank</th><th>Contact</th><th>Portal</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="v in vendors" :key="v.id" :class="!v.is_active && 'opacity-60'">
                        <td><span class="font-medium text-ink">{{ v.name }}</span><p class="font-mono text-xs text-muted">{{ v.code }}<span v-if="v.msme"> · MSME</span></p></td>
                        <td class="font-mono text-xs">{{ v.gstin ?? '—' }}<br />{{ v.pan ?? '' }}</td>
                        <td class="font-mono text-xs">{{ v.account ?? '—' }}<br />{{ v.ifsc ?? '' }}</td>
                        <td class="text-sm">{{ v.email ?? '—' }}<p class="text-xs text-muted">{{ v.phone }}</p></td>
                        <td><StatusBadge v-if="v.portal" :status="v.portal.active ? 'active' : 'inactive'" :label="v.portal.username" /><span v-else class="text-xs text-muted">none</span></td>
                        <td class="text-right whitespace-nowrap">
                            <AppButton size="sm" variant="ghost" :icon="KeyRound" :disabled="!v.email || !v.is_active" @click="account(v)">{{ v.portal ? 'Reset' : 'Portal access' }}</AppButton>
                            <AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${v.name}`" @click="edit(v)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="open" :title="editing ? `Edit ${editing.name}` : 'New vendor'" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="Code" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Name" :error="form.errors.name" required class="sm:col-span-2"><TextInput v-model="form.name" /></FormField>
                    <FormField label="GSTIN" :error="form.errors.gstin"><TextInput v-model="form.gstin" class="font-mono uppercase" /></FormField>
                    <FormField label="PAN" :error="form.errors.pan"><TextInput v-model="form.pan" class="font-mono uppercase" /></FormField>
                    <FormField label="Phone" :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" /></FormField>
                    <FormField label="E-mail" :error="form.errors.email" class="sm:col-span-3"><TextInput v-model="form.email" type="email" /></FormField>
                    <FormField :label="editing ? 'Bank account (blank = unchanged)' : 'Bank account'" :error="form.errors.bank_account" class="sm:col-span-2"><TextInput v-model="form.bank_account" autocomplete="off" class="font-mono" /></FormField>
                    <FormField label="IFSC" :error="form.errors.ifsc"><TextInput v-model="form.ifsc" class="font-mono uppercase" /></FormField>
                </div>
                <FormField label="Address" :error="form.errors.address"><TextArea v-model="form.address" rows="2" /></FormField>
                <div class="flex gap-4"><CheckboxInput v-model="form.msme" label="MSME" /><CheckboxInput v-model="form.is_active" label="Active" /></div>
                <AlertBox v-if="editing && !form.is_active && editing.portal" tone="warning">Deactivating also blocks the vendor's portal sign-in.</AlertBox>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
