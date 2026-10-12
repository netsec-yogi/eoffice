<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import type { UserRef } from '@/types';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{
    user: {
        id: number;
        name: string;
        username: string;
        email: string | null;
        phone: string | null;
        employee_id: string | null;
        category: string;
        auth_source: string;
        department_id: number | null;
        designation_id: number | null;
        reports_to_id: number | null;
        reports_to: UserRef | null;
        is_active: boolean;
        directory_disabled: boolean;
        roles: string[];
        last_synced_at: string | null;
        last_login_at: string | null;
        authorities: { authority: string; department: string | null; starts_on: string; ends_on: string | null; is_acting: boolean }[];
    };
    departments: { id: number; code: string; name: string }[];
    designations: { id: number; name: string }[];
    roles: string[];
    categories: string[];
    can: { manage: boolean; roles: boolean };
}>();

const form = useForm({
    department_id: props.user.department_id,
    designation_id: props.user.designation_id,
    reports_to_id: props.user.reports_to_id,
    category: props.user.category,
    phone: props.user.phone ?? '',
    is_active: props.user.is_active,
});
const roles = useForm({ roles: [...props.user.roles] });

async function save() {
    if (props.user.is_active && !form.is_active && !(await ask('Deactivate this account? The user will be unable to sign in until reactivated.'))) return;
    form.put(route('admin.users.update', props.user.id), { preserveScroll: true });
}
const saveRoles = () => roles.put(route('admin.users.roles', props.user.id), { preserveScroll: true });
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : 'never');
</script>

<template>
    <AppLayout :title="user.name">
        <PageHeader :title="user.name" :description="`${user.username} · ${user.email ?? 'no e-mail'} · ${user.employee_id ?? 'no ID'}`">
            <StatusBadge :status="user.is_active && !user.directory_disabled ? 'active' : 'inactive'" />
        </PageHeader>

        <AlertBox v-if="user.directory_disabled" tone="warning" class="mb-6">The directory marks this account as disabled. It cannot sign in regardless of the setting below.</AlertBox>

        <div class="grid gap-6 lg:grid-cols-3">
            <form class="card space-y-4 p-6 lg:col-span-2" @submit.prevent="save">
                <h2 class="font-semibold text-ink">Organisation</h2>
                <p class="text-sm text-muted">
                    Name, e-mail and ID come from the {{ user.auth_source === 'ldap' ? 'directory' : 'local account' }}. Last synchronised: {{ fmt(user.last_synced_at) }}. Last sign-in:
                    {{ fmt(user.last_login_at) }}.
                </p>
                <fieldset :disabled="!can.manage" class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Department" :error="form.errors.department_id">
                        <SelectInput v-model="form.department_id" :options="departments.map((d) => ({ value: d.id, label: `${d.code} – ${d.name}` }))" placeholder="None" />
                    </FormField>
                    <FormField label="Designation" :error="form.errors.designation_id">
                        <SelectInput v-model="form.designation_id" :options="designations.map((d) => ({ value: d.id, label: d.name }))" placeholder="None" />
                    </FormField>
                    <FormField label="Reports to" hint="Used by workflows that route to the reporting officer." :error="form.errors.reports_to_id">
                        <UserPicker v-model="form.reports_to_id" :selected="user.reports_to" />
                    </FormField>
                    <FormField label="Category" :error="form.errors.category">
                        <SelectInput v-model="form.category" :options="categories.map((c) => ({ value: c, label: c }))" />
                    </FormField>
                    <FormField label="Phone (for SMS/WhatsApp)" :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" /></FormField>
                    <div class="flex items-end pb-2"><CheckboxInput v-model="form.is_active" label="Account active" /></div>
                </fieldset>
                <div v-if="can.manage" class="flex justify-end"><AppButton type="submit" :loading="form.processing">Save</AppButton></div>
            </form>

            <div class="space-y-6">
                <form class="card space-y-3 p-6" @submit.prevent="saveRoles">
                    <h2 class="font-semibold text-ink">Roles</h2>
                    <p class="text-sm text-muted">Roles grant platform permissions. Approval authority comes from authorities and committees, not roles.</p>
                    <fieldset :disabled="!can.roles" class="space-y-2">
                        <CheckboxInput v-for="r in props.roles" :key="r" v-model="roles.roles" :value="r" :label="r.replace(/_/g, ' ')" />
                    </fieldset>
                    <p v-if="roles.errors.roles" class="text-sm text-red-600">{{ roles.errors.roles }}</p>
                    <div v-if="can.roles" class="flex justify-end"><AppButton type="submit" size="sm" :loading="roles.processing">Save roles</AppButton></div>
                </form>

                <div class="card p-6">
                    <h2 class="font-semibold text-ink">Authorities held</h2>
                    <ul v-if="user.authorities.length" class="mt-3 space-y-2 text-sm">
                        <li v-for="(a, i) in user.authorities" :key="i">
                            <span class="font-medium text-ink">{{ a.authority }}</span><span v-if="a.department"> ({{ a.department }})</span>
                            <span v-if="a.is_acting" class="text-amber-700"> · acting</span>
                            <p class="text-xs text-muted">{{ a.starts_on }} → {{ a.ends_on ?? 'open' }}</p>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-muted">None.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
