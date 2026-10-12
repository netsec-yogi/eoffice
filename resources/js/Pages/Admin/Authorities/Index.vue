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
import { CalendarX, KeyRound, Pencil, Plus, UserPlus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Holder { id: number; user: { id: number; name: string; username: string }; department: string | null; starts_on: string; ends_on: string | null; is_acting: boolean; order_reference: string | null; current: boolean }
interface Authority { id: number; code: string; name: string; scope: 'institute' | 'department'; description: string | null; is_active: boolean; holders: Holder[] }

defineProps<{ authorities: Authority[]; departments: { id: number; code: string; name: string }[] }>();

const editing = ref<Authority | null>(null);
const open = ref(false);
const form = useForm({ code: '', name: '', scope: 'institute' as 'institute' | 'department', description: '', is_active: true });
function edit(a: Authority | null) {
    editing.value = a;
    form.clearErrors();
    Object.assign(form, a ? { code: a.code, name: a.name, scope: a.scope, description: a.description ?? '', is_active: a.is_active } : { code: '', name: '', scope: 'institute', description: '', is_active: true });
    open.value = true;
}
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(route('admin.authorities.update', editing.value.id), opts) : form.post(route('admin.authorities.store'), opts);
};

const assignTo = ref<Authority | null>(null);
const assign = useForm({ user_id: null as number | null, department_id: null as number | null, starts_on: new Date().toISOString().slice(0, 10), ends_on: '', is_acting: false, order_reference: '' });
function openAssign(a: Authority) {
    assignTo.value = a;
    assign.reset();
    assign.clearErrors();
}
const saveAssign = () => assign.post(route('admin.authorities.assign', assignTo.value!.id), { preserveScroll: true, onSuccess: () => (assignTo.value = null) });

const ending = ref<Holder | null>(null);
const end = useForm({ ends_on: new Date().toISOString().slice(0, 10) });
const saveEnd = () => end.put(route('admin.authorities.end', ending.value!.id), { preserveScroll: true, onSuccess: () => (ending.value = null) });
</script>

<template>
    <AppLayout title="Authorities">
        <PageHeader title="Authorities" description="Named offices that workflows route to (Head of Department, Registrar …). Assign holders with dates; acting arrangements are recorded as such.">
            <AppButton :icon="Plus" @click="edit(null)">Authority</AppButton>
        </PageHeader>

        <EmptyState v-if="!authorities.length" :icon="KeyRound" title="No authorities yet" description="Create the offices your workflows approve through, then assign who holds them." />

        <div class="grid gap-4 md:grid-cols-2">
            <section v-for="a in authorities" :key="a.id" class="card p-5">
                <header class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-ink">{{ a.name }}</h2>
                        <p class="font-mono text-xs text-muted">{{ a.code }} · {{ a.scope === 'department' ? 'one per department' : 'institute-wide' }}<span v-if="!a.is_active"> · inactive</span></p>
                    </div>
                    <div class="flex gap-1">
                        <AppButton variant="ghost" size="sm" :icon="UserPlus" :aria-label="`Assign holder for ${a.name}`" @click="openAssign(a)" />
                        <AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${a.name}`" @click="edit(a)" />
                    </div>
                </header>
                <p v-if="a.description" class="mt-2 text-sm text-muted">{{ a.description }}</p>
                <ul class="mt-4 divide-y divide-line-soft">
                    <li v-for="h in a.holders" :key="h.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <div>
                            <span class="font-medium text-ink">{{ h.user.name }}</span>
                            <span v-if="h.department" class="text-muted"> · {{ h.department }}</span>
                            <span v-if="h.is_acting" class="ml-1 rounded-full bg-amber-50 px-1.5 text-xs text-amber-800">acting</span>
                            <span v-if="!h.current" class="ml-1 rounded-full bg-brand-50 px-1.5 text-xs text-brand-700">from {{ h.starts_on }}</span>
                            <p class="text-xs text-muted">{{ h.starts_on }} → {{ h.ends_on ?? 'open' }}<template v-if="h.order_reference"> · {{ h.order_reference }}</template></p>
                        </div>
                        <AppButton v-if="!h.ends_on" variant="danger-ghost" size="sm" :icon="CalendarX" :aria-label="`End ${h.user.name}'s term`" @click="ending = h" />
                    </li>
                    <li v-if="!a.holders.length" class="py-2 text-sm text-amber-700">No current holder — workflows routed here will fall back to the definition's fallback authority.</li>
                </ul>
            </section>
        </div>

        <ModalDialog :show="open" :title="editing ? 'Edit authority' : 'New authority'" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" hint="e.g. HOD, REGISTRAR. Used in workflow definitions." :error="form.errors.code" required><TextInput v-model="form.code" /></FormField>
                    <FormField label="Scope" :error="form.errors.scope" required>
                        <SelectInput v-model="form.scope" :options="[{ value: 'institute', label: 'Institute-wide' }, { value: 'department', label: 'One per department' }]" />
                    </FormField>
                </div>
                <FormField label="Name" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                <FormField label="Description" :error="form.errors.description"><TextArea v-model="form.description" rows="2" /></FormField>
                <CheckboxInput v-model="form.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="open = false">Cancel</AppButton><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="!!assignTo" :title="`Assign holder: ${assignTo?.name ?? ''}`" @close="assignTo = null">
            <form class="space-y-4" @submit.prevent="saveAssign">
                <FormField label="Person" :error="assign.errors.user_id" required><UserPicker v-model="assign.user_id" /></FormField>
                <FormField v-if="assignTo?.scope === 'department'" label="Department" :error="assign.errors.department_id" required>
                    <SelectInput v-model="assign.department_id" :options="departments.map((d) => ({ value: d.id, label: `${d.code} – ${d.name}` }))" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="From" :error="assign.errors.starts_on" required><TextInput v-model="assign.starts_on" type="date" /></FormField>
                    <FormField label="Until" hint="Leave empty if open-ended." :error="assign.errors.ends_on"><TextInput v-model="assign.ends_on" type="date" /></FormField>
                </div>
                <FormField label="Office order reference" :error="assign.errors.order_reference"><TextInput v-model="assign.order_reference" /></FormField>
                <CheckboxInput v-model="assign.is_acting" label="Acting arrangement" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="assignTo = null">Cancel</AppButton><AppButton type="submit" :loading="assign.processing">Assign</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="!!ending" title="End term" :description="ending ? `${ending.user.name} stops holding this authority after the date below.` : ''" size="sm" @close="ending = null">
            <form class="space-y-4" @submit.prevent="saveEnd">
                <FormField label="Last day" :error="end.errors.ends_on" required><TextInput v-model="end.ends_on" type="date" /></FormField>
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="ending = null">Cancel</AppButton><AppButton type="submit" variant="danger" :loading="end.processing">End term</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
