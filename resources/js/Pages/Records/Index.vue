<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Cat { id: number; code: string; name: string; description: string | null; numbering_pattern: string; default_classification: string; retention_schedule_id: number | null; is_active: boolean; retention: string | null }
interface Sch { id: number; code: string; name: string; retain_years: number | null; action: string; description: string | null }
interface Due { id: number; file_number: string; subject: string; classification: string; disposition: string | null; legal_hold: boolean; department: string; retain_until: string | null; schedule: string | null; suggested: string | null }
const props = defineProps<{ categories: Cat[]; schedules: Sch[]; due: Due[]; classifications: string[] }>();

const tab = ref<'due' | 'categories' | 'schedules'>(new URLSearchParams(location.search).get('tab') === 'categories' ? 'categories' : 'due');

const catEditing = ref<Cat | null>(null);
const catOpen = ref(false);
const cat = useForm({ code: '', name: '', description: '', numbering_pattern: '{DEPT}/{CAT}/{YYYY}/{SEQ:4}', default_classification: 'internal', retention_schedule_id: null as number | null, is_active: true });
function editCat(c: Cat | null) {
    catEditing.value = c;
    cat.clearErrors();
    Object.assign(cat, c ? { ...c, description: c.description ?? '' } : { code: '', name: '', description: '', numbering_pattern: '{DEPT}/{CAT}/{YYYY}/{SEQ:4}', default_classification: 'internal', retention_schedule_id: null, is_active: true });
    catOpen.value = true;
}
const saveCat = () => (catEditing.value ? cat.put(route('records.categories.update', catEditing.value.id), { onSuccess: () => (catOpen.value = false) }) : cat.post(route('records.categories.store'), { onSuccess: () => (catOpen.value = false) }));

const schEditing = ref<Sch | null>(null);
const schOpen = ref(false);
const sch = useForm({ code: '', name: '', retain_years: null as number | null, action: 'review', description: '' });
function editSch(s: Sch | null) {
    schEditing.value = s;
    sch.clearErrors();
    Object.assign(sch, s ? { ...s, description: s.description ?? '' } : { code: '', name: '', retain_years: null, action: 'review', description: '' });
    schOpen.value = true;
}
const saveSch = () => (schEditing.value ? sch.put(route('records.schedules.update', schEditing.value.id), { onSuccess: () => (schOpen.value = false) }) : sch.post(route('records.schedules.store'), { onSuccess: () => (schOpen.value = false) }));

const deciding = ref<Due | null>(null);
const decision = useForm({ decision: 'transfer', reason: '', extend_years: 1 });
const decide = (d: Due) => ((deciding.value = d), decision.reset(), (decision.decision = d.suggested === 'destroy' ? 'destroy' : 'transfer'));
const doDecide = () => decision.post(route('records.dispose', deciding.value!.id), { preserveScroll: true, onSuccess: () => (deciding.value = null) });
const holding = ref<Due | null>(null);
const hold = useForm({ hold: true, reason: '' });
const doHold = () => hold.post(route('records.legal_hold', holding.value!.id), { preserveScroll: true, onSuccess: () => (holding.value = null) });
const years = (y: number | null) => (y === null ? 'Permanent' : `${y} years`);
</script>

<template>
    <AppLayout title="Records">
        <PageHeader title="Records management" description="File categories and numbering, retention schedules, and decisions on files whose retention period has ended. Nothing is destroyed without a recorded decision." />
        <TabsNav v-model="tab" :items="[{ key: 'due', label: 'Due for disposition', count: due.filter((d) => d.disposition === 'due').length }, { key: 'categories', label: 'File categories', count: categories.length }, { key: 'schedules', label: 'Retention schedules', count: schedules.length }]" class="mb-4" />

        <template v-if="tab === 'due'">
            <AlertBox tone="info" class="mb-4">Retention schedules are placeholders until the institute's records retention policy is confirmed. Destroying a file erases its notes and documents; the register entry and its history remain.</AlertBox>
            <DataTable :empty="!due.length" empty-title="No files are due">
                <table class="data-table">
                    <thead><tr><th>File</th><th>Department</th><th>Kept until</th><th>Schedule</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="d in due" :key="d.id">
                            <td><Link :href="route('efiles.show', d.id)" class="font-mono text-xs text-brand-700 hover:underline">{{ d.file_number }}</Link><p class="text-sm text-ink">{{ d.subject }}</p></td>
                            <td>{{ d.department }}</td>
                            <td>{{ d.retain_until }}</td>
                            <td>{{ d.schedule }} <span class="text-xs text-muted">({{ d.suggested }})</span><p v-if="d.legal_hold" class="text-xs font-semibold text-red-700">legal hold</p></td>
                            <td class="text-right whitespace-nowrap">
                                <AppButton v-if="!d.legal_hold" size="sm" variant="outline" @click="decide(d)">Decide</AppButton>
                                <AppButton size="sm" variant="ghost" @click="(holding = d), (hold.hold = !d.legal_hold), (hold.reason = '')">{{ d.legal_hold ? 'Lift hold' : 'Legal hold' }}</AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </DataTable>
        </template>

        <template v-else-if="tab === 'categories'">
            <div class="mb-3"><AppButton :icon="Plus" size="sm" @click="editCat(null)">Category</AppButton></div>
            <DataTable :empty="!categories.length" empty-title="No file categories">
                <table class="data-table">
                    <thead><tr><th>Category</th><th>Numbering</th><th>Default class</th><th>Retention</th><th><span class="sr-only">Edit</span></th></tr></thead>
                    <tbody>
                        <tr v-for="c in categories" :key="c.id" :class="!c.is_active && 'opacity-60'">
                            <td><span class="font-medium text-ink">{{ c.name }}</span><p class="font-mono text-xs text-muted">{{ c.code }}</p></td>
                            <td class="font-mono text-xs">{{ c.numbering_pattern }}</td>
                            <td class="capitalize">{{ c.default_classification }}</td>
                            <td>{{ c.retention ?? '—' }}</td>
                            <td class="text-right"><AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${c.name}`" @click="editCat(c)" /></td>
                        </tr>
                    </tbody>
                </table>
            </DataTable>
        </template>

        <template v-else>
            <div class="mb-3"><AppButton :icon="Plus" size="sm" @click="editSch(null)">Schedule</AppButton></div>
            <DataTable :empty="!schedules.length" empty-title="No retention schedules">
                <table class="data-table">
                    <thead><tr><th>Schedule</th><th>Keep</th><th>Then</th><th><span class="sr-only">Edit</span></th></tr></thead>
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id">
                            <td><span class="font-medium text-ink">{{ s.name }}</span><p class="font-mono text-xs text-muted">{{ s.code }}</p></td>
                            <td>{{ years(s.retain_years) }}</td>
                            <td class="capitalize">{{ s.action }}</td>
                            <td class="text-right"><AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="`Edit ${s.name}`" @click="editSch(s)" /></td>
                        </tr>
                    </tbody>
                </table>
            </DataTable>
        </template>

        <ModalDialog :show="catOpen" :title="catEditing ? 'Edit category' : 'New category'" @close="catOpen = false">
            <form class="space-y-4" @submit.prevent="saveCat">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" :error="cat.errors.code" required><TextInput v-model="cat.code" /></FormField>
                    <FormField label="Name" :error="cat.errors.name" required><TextInput v-model="cat.name" /></FormField>
                    <FormField label="Numbering pattern" hint="{DEPT} {CAT} {YYYY} {YY} {SEQ:n}" :error="cat.errors.numbering_pattern" required><TextInput v-model="cat.numbering_pattern" class="font-mono" /></FormField>
                    <FormField label="Default classification" :error="cat.errors.default_classification"><SelectInput v-model="cat.default_classification" :options="classifications.map((c) => ({ value: c, label: c }))" /></FormField>
                    <FormField label="Retention schedule" :error="cat.errors.retention_schedule_id" class="sm:col-span-2"><SelectInput v-model="cat.retention_schedule_id" :options="schedules.map((s) => ({ value: s.id, label: `${s.name} (${years(s.retain_years)}, then ${s.action})` }))" placeholder="None" /></FormField>
                </div>
                <FormField label="Description" :error="cat.errors.description"><TextArea v-model="cat.description" rows="2" /></FormField>
                <CheckboxInput v-model="cat.is_active" label="Available for new files" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="catOpen = false">Cancel</AppButton><AppButton type="submit" :loading="cat.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="schOpen" :title="schEditing ? 'Edit schedule' : 'New schedule'" @close="schOpen = false">
            <form class="space-y-4" @submit.prevent="saveSch">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" :error="sch.errors.code" required><TextInput v-model="sch.code" /></FormField>
                    <FormField label="Name" :error="sch.errors.name" required><TextInput v-model="sch.name" /></FormField>
                    <FormField label="Keep for (years)" hint="Empty = permanently" :error="sch.errors.retain_years"><TextInput v-model="sch.retain_years" type="number" min="0" /></FormField>
                    <FormField label="Then" :error="sch.errors.action"><SelectInput v-model="sch.action" :options="[{ value: 'review', label: 'Review' }, { value: 'destroy', label: 'Destroy' }, { value: 'transfer', label: 'Transfer to archives' }]" /></FormField>
                </div>
                <FormField label="Authority / notes" :error="sch.errors.description"><TextArea v-model="sch.description" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="schOpen = false">Cancel</AppButton><AppButton type="submit" :loading="sch.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!deciding" :title="`Disposition: ${deciding?.file_number ?? ''}`" @close="deciding = null">
            <form class="space-y-4" @submit.prevent="doDecide">
                <FormField label="Decision" :error="decision.errors.decision ?? (decision.errors as Record<string, string>).workflow" required>
                    <SelectInput v-model="decision.decision" :options="[{ value: 'transfer', label: 'Transfer to archives' }, { value: 'retain', label: 'Keep longer' }, { value: 'destroy', label: 'Destroy (erase notes and documents)' }]" />
                </FormField>
                <FormField v-if="decision.decision === 'retain'" label="Keep for further (years)" :error="decision.errors.extend_years"><TextInput v-model="decision.extend_years" type="number" min="1" /></FormField>
                <FormField label="Reason / authority" :error="decision.errors.reason" required><TextArea v-model="decision.reason" rows="2" /></FormField>
                <AlertBox v-if="decision.decision === 'destroy'" tone="danger">This cannot be undone. The register entry and history remain as the record of destruction.</AlertBox>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="deciding = null">Cancel</AppButton><AppButton type="submit" :variant="decision.decision === 'destroy' ? 'danger' : 'primary'" :loading="decision.processing">Record decision</AppButton></div>
            </form>
        </ModalDialog>
        <ModalDialog :show="!!holding" :title="hold.hold ? 'Place legal hold' : 'Lift legal hold'" @close="holding = null">
            <form class="space-y-4" @submit.prevent="doHold">
                <FormField label="Reason / order" :error="hold.errors.reason" required><TextArea v-model="hold.reason" rows="2" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="holding = null">Cancel</AppButton><AppButton type="submit" :loading="hold.processing">{{ hold.hold ? 'Place hold' : 'Lift hold' }}</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
