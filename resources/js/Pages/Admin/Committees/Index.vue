<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { CalendarX, Pencil, Plus, UserPlus, UsersRound } from 'lucide-vue-next';
import { ref } from 'vue';

interface Member { id: number; role: string; is_voting: boolean; voting_weight: number; user: { id: number; name: string; username: string }; starts_on: string; ends_on: string | null; current: boolean }
interface Committee { id: number; code: string; name: string; department_id: number | null; department: string | null; purpose: string | null; is_active: boolean; members: Member[] }

defineProps<{ committees: Committee[]; departments: { id: number; code: string; name: string }[]; roles: string[] }>();

const editing = ref<Committee | null>(null);
const open = ref(false);
const form = useForm({ code: '', name: '', department_id: null as number | null, purpose: '', is_active: true });
function edit(c: Committee | null) {
    editing.value = c;
    form.clearErrors();
    Object.assign(form, c ? { code: c.code, name: c.name, department_id: c.department_id, purpose: c.purpose ?? '', is_active: c.is_active } : { code: '', name: '', department_id: null, purpose: '', is_active: true });
    open.value = true;
}
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(route('admin.committees.update', editing.value.id), opts) : form.post(route('admin.committees.store'), opts);
};

const addTo = ref<Committee | null>(null);
const member = useForm({ user_id: null as number | null, role: 'member', voting_weight: 1, is_voting: true, starts_on: new Date().toISOString().slice(0, 10), ends_on: '' });
const saveMember = () => member.post(route('admin.committees.members.store', addTo.value!.id), { preserveScroll: true, onSuccess: () => (addTo.value = null) });

const ending = ref<Member | null>(null);
const end = useForm({ ends_on: new Date().toISOString().slice(0, 10) });
const saveEnd = () => end.put(route('admin.committees.members.end', ending.value!.id), { preserveScroll: true, onSuccess: () => (ending.value = null) });
</script>

<template>
    <AppLayout title="Committees">
        <PageHeader title="Committees" description="Committees decide workflow steps together. How a decision is reached (any, all, majority, quorum, weighted) is set on each workflow step.">
            <AppButton :icon="Plus" @click="edit(null)">Committee</AppButton>
        </PageHeader>

        <EmptyState v-if="!committees.length" :icon="UsersRound" title="No committees yet" />

        <div class="space-y-4">
            <section v-for="c in committees" :key="c.id" class="card p-5">
                <header class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-ink">{{ c.name }}</h2>
                        <p class="font-mono text-xs text-muted">{{ c.code }}<template v-if="c.department"> · {{ c.department }}</template><span v-if="!c.is_active"> · inactive</span></p>
                        <p v-if="c.purpose" class="mt-1 text-sm text-muted">{{ c.purpose }}</p>
                    </div>
                    <div class="flex gap-1">
                        <AppButton variant="ghost" size="sm" :icon="UserPlus" :aria-label="`Add member to ${c.name}`" @click="(addTo = c), member.reset()" />
                        <AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${c.name}`" @click="edit(c)" />
                    </div>
                </header>
                <table v-if="c.members.length" class="data-table mt-3">
                    <thead><tr><th>Member</th><th>Role</th><th>Vote</th><th>Period</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="m in c.members" :key="m.id" :class="!m.current && 'opacity-60'">
                            <td class="font-medium text-ink">{{ m.user.name }}</td>
                            <td class="capitalize">{{ m.role.replace('_', ' ') }}</td>
                            <td>{{ m.is_voting ? `weight ${m.voting_weight}` : 'non-voting' }}</td>
                            <td class="text-xs">{{ m.starts_on }} → {{ m.ends_on ?? 'open' }}</td>
                            <td class="text-right"><AppButton v-if="!m.ends_on" variant="danger-ghost" size="sm" :icon="CalendarX" :aria-label="`End ${m.user.name}'s membership`" @click="ending = m" /></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 text-sm text-amber-700">No members.</p>
            </section>
        </div>

        <ModalDialog :show="open" :title="editing ? 'Edit committee' : 'New committee'" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Department" :error="form.errors.department_id">
                        <SelectInput v-model="form.department_id" :options="departments.map((d) => ({ value: d.id, label: `${d.code} – ${d.name}` }))" placeholder="Institute-level" />
                    </FormField>
                </div>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                <FormField label="Purpose / constituting order" :error="form.errors.purpose"><TextArea v-model="form.purpose" rows="2" /></FormField>
                <CheckboxInput v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="!!addTo" :title="`Add member: ${addTo?.name ?? ''}`" @close="addTo = null">
            <form class="space-y-4" @submit.prevent="saveMember">
                <FormField label="Person" :error="member.errors.user_id" required><UserPicker v-model="member.user_id" /></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Role" :error="member.errors.role" required><SelectInput v-model="member.role" :options="roles.map((r) => ({ value: r, label: r.replace('_', ' ') }))" /></FormField>
                    <FormField label="Voting weight" hint="Used only by weighted decisions." :error="member.errors.voting_weight"><TextInput v-model="member.voting_weight" type="number" step="0.5" min="0" /></FormField>
                    <FormField label="From" :error="member.errors.starts_on" required><TextInput v-model="member.starts_on" type="date" /></FormField>
                    <FormField label="Until" :error="member.errors.ends_on"><TextInput v-model="member.ends_on" type="date" /></FormField>
                </div>
                <CheckboxInput v-model="member.is_voting" label="Voting member" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="addTo = null">Cancel</AppButton><AppButton type="submit" :loading="member.processing">Add</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="!!ending" title="End membership" size="sm" @close="ending = null">
            <form class="space-y-4" @submit.prevent="saveEnd">
                <FormField label="Last day" :error="end.errors.ends_on" required><TextInput v-model="end.ends_on" type="date" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="ending = null">Cancel</AppButton><AppButton type="submit" variant="danger" :loading="end.processing">End membership</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
