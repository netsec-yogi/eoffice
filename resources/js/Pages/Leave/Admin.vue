<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import LeaveSummary, { type Balance, type LedgerRow, type LeaveRow } from './LeaveSummary.vue';

interface LType { id: number; code: string; name: string; description: string | null; has_balance: boolean; counts_holidays: boolean; allow_half_day: boolean; max_days_per_application: number | null; min_notice_days: number | null; certificate_after_days: number | null; eligible_categories: string[] | null; is_active: boolean }
const props = defineProps<{ types: LType[]; person: ({ id: number; name: string; username: string; balances: Balance[]; records: LeaveRow[]; ledger: LedgerRow[] }) | null }>();

const who = ref<number | null>(props.person?.id ?? null);
watch(who, (id) => router.get(route('leave.admin'), id ? { user: id } : {}, { preserveScroll: true }));

const editing = ref<LType | null>(null);
const open = ref(false);
const blank = { code: '', name: '', description: '', has_balance: true, counts_holidays: false, allow_half_day: false, max_days_per_application: null as number | null, min_notice_days: null as number | null, certificate_after_days: null as number | null, eligible_categories: [] as string[], is_active: true };
const form = useForm({ ...blank });
function edit(t: LType | null) {
    editing.value = t;
    form.clearErrors();
    Object.assign(form, t ? { ...t, description: t.description ?? '', eligible_categories: t.eligible_categories ?? [] } : blank);
    open.value = true;
}
const save = () => (editing.value ? form.put(route('leave.types.update', editing.value.id), { onSuccess: () => (open.value = false) }) : form.post(route('leave.types.store'), { onSuccess: () => (open.value = false) }));

const post = useForm({ leave_type_id: null as number | null, kind: 'credit', days: '', reason: '', user_id: null as number | null, category: 'faculty', scope: 'person' });
const doPost = () =>
    post.transform((d) => ({ ...d, user_id: d.scope === 'person' ? props.person?.id : null, category: d.scope === 'person' ? null : d.category })).post(route('leave.ledger.post'), { preserveScroll: true, onSuccess: () => post.reset('days', 'reason') });
</script>

<template>
    <AppLayout title="Leave administration">
        <PageHeader title="Leave administration" description="Leave types and their rules, and the leave ledger. Every change is audited.">
            <AppButton :icon="Plus" @click="edit(null)">Leave type</AppButton>
        </PageHeader>
        <AlertBox tone="warning" class="mb-6">Seeded leave types and limits are placeholders. Set them from the institute's leave rules before use.</AlertBox>

        <DataTable :empty="!types.length" empty-title="No leave types" class="mb-8">
            <table class="data-table">
                <thead><tr><th>Type</th><th>Balance</th><th>Counts</th><th>Max / notice</th><th>Eligible</th><th><span class="sr-only">Edit</span></th></tr></thead>
                <tbody>
                    <tr v-for="t in types" :key="t.id" :class="!t.is_active && 'opacity-60'">
                        <td><span class="font-medium text-ink">{{ t.name }}</span><p class="font-mono text-xs text-muted">{{ t.code }}</p></td>
                        <td>{{ t.has_balance ? 'Kept' : 'None' }}</td>
                        <td>{{ t.counts_holidays ? 'Calendar days' : 'Working days' }}{{ t.allow_half_day ? ', half days' : '' }}</td>
                        <td class="text-sm">{{ t.max_days_per_application ?? '—' }} / {{ t.min_notice_days ?? '—' }} d</td>
                        <td class="text-sm">{{ t.eligible_categories?.join(', ') || 'faculty, staff' }}</td>
                        <td class="text-right"><AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${t.name}`" @click="edit(t)" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <section class="card mb-6 space-y-4 p-6">
            <h2 class="font-semibold text-ink">Ledger</h2>
            <FormField label="Person"><UserPicker v-model="who" :selected="person ? { id: person.id, name: person.name, username: person.username } : null" category="faculty,staff" /></FormField>
            <form class="grid gap-3 sm:grid-cols-6 sm:items-end" @submit.prevent="doPost">
                <FormField label="Post to" class="sm:col-span-1"><SelectInput v-model="post.scope" :options="[{ value: 'person', label: 'This person' }, { value: 'category', label: 'Everyone of a category' }]" /></FormField>
                <FormField v-if="post.scope === 'category'" label="Category"><SelectInput v-model="post.category" :options="[{ value: 'faculty', label: 'Faculty' }, { value: 'staff', label: 'Staff' }]" /></FormField>
                <FormField label="Leave type" :error="post.errors.leave_type_id"><SelectInput v-model="post.leave_type_id" :options="types.filter((t) => t.has_balance).map((t) => ({ value: t.id, label: t.name }))" /></FormField>
                <FormField label="Entry" :error="post.errors.kind"><SelectInput v-model="post.kind" :options="[{ value: 'credit', label: 'Credit' }, { value: 'adjustment', label: 'Adjustment (±)' }, { value: 'lapse', label: 'Lapse' }]" /></FormField>
                <FormField label="Days" :error="post.errors.days"><TextInput v-model="post.days" type="number" step="0.5" /></FormField>
                <FormField label="Reason / order" :error="post.errors.reason" class="sm:col-span-2"><TextInput v-model="post.reason" /></FormField>
                <AppButton type="submit" :disabled="post.scope === 'person' && !person" :loading="post.processing">Post</AppButton>
            </form>
        </section>
        <LeaveSummary v-if="person" :balances="person.balances" :records="person.records" :ledger="person.ledger" />

        <ModalDialog :show="open" :title="editing ? 'Edit leave type' : 'New leave type'" size="lg" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField label="Code" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Name" :error="form.errors.name" required class="sm:col-span-2"><TextInput v-model="form.name" /></FormField>
                    <FormField label="Max days per application" :error="form.errors.max_days_per_application"><TextInput v-model="form.max_days_per_application" type="number" step="0.5" /></FormField>
                    <FormField label="Notice (days)" :error="form.errors.min_notice_days"><TextInput v-model="form.min_notice_days" type="number" /></FormField>
                    <FormField label="Certificate beyond (days)" :error="form.errors.certificate_after_days"><TextInput v-model="form.certificate_after_days" type="number" step="0.5" /></FormField>
                </div>
                <div class="flex flex-wrap gap-4">
                    <CheckboxInput v-model="form.has_balance" label="Keeps a balance" />
                    <CheckboxInput v-model="form.counts_holidays" label="Counts weekends and holidays" />
                    <CheckboxInput v-model="form.allow_half_day" label="Half days allowed" />
                    <CheckboxInput v-model="form.is_active" label="Active" />
                </div>
                <fieldset><legend class="mb-1 text-sm font-medium text-ink">Eligible (none ticked = faculty and staff)</legend>
                    <div class="flex gap-4"><CheckboxInput v-for="c in ['faculty', 'staff', 'student']" :key="c" v-model="form.eligible_categories" :value="c" :label="c" /></div>
                </fieldset>
                <FormField label="Rule reference / notes" :error="form.errors.description"><TextArea v-model="form.description" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
