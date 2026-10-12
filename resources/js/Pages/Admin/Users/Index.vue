<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Search, UserPlus } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Row {
    id: number;
    name: string;
    username: string;
    email: string | null;
    employee_id: string | null;
    category: string;
    auth_source: string;
    department: string | null;
    designation: string | null;
    roles: string[];
    status: string;
    last_login_at: string | null;
}

const props = defineProps<{
    users: Paginated<Row>;
    filters: Record<string, string | number | undefined>;
    departments: { id: number; code: string; name: string }[];
    categories: string[];
    canManage: boolean;
}>();

const filters = reactive({ q: props.filters.q ?? '', department: props.filters.department ?? '', category: props.filters.category ?? '', status: props.filters.status ?? '' });
let timer: ReturnType<typeof setTimeout>;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('admin.users.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), { preserveState: true, replace: true }), 300);
});

const showCreate = reactive({ open: false });
const form = useForm({ username: '', name: '', email: '', phone: '', category: 'vendor', password: '' });
const create = () => form.post(route('admin.users.local'), { onSuccess: () => (showCreate.open = false) });
</script>

<template>
    <AppLayout title="Users">
        <PageHeader title="Users" description="Directory users are created on first sign-in or by directory sync. Local accounts are only for vendors and external members.">
            <AppButton v-if="canManage" :icon="UserPlus" variant="outline" @click="showCreate.open = true">Local account</AppButton>
        </PageHeader>

        <DataTable :empty="users.data.length === 0" empty-title="No users match these filters">
            <template #toolbar>
                <div class="grid w-full gap-2 sm:grid-cols-4">
                    <TextInput v-model="filters.q" :icon="Search" placeholder="Name, username, e-mail, ID" aria-label="Search users" />
                    <SelectInput v-model="filters.department" :options="departments.map((d) => ({ value: d.id, label: `${d.code} – ${d.name}` }))" placeholder="All departments" aria-label="Department" />
                    <SelectInput v-model="filters.category" :options="categories.map((c) => ({ value: c, label: c }))" placeholder="All categories" aria-label="Category" />
                    <SelectInput v-model="filters.status" :options="[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]" placeholder="Any status" aria-label="Status" />
                </div>
            </template>
            <table class="data-table">
                <thead>
                    <tr><th>Name</th><th>Username</th><th>Department</th><th>Category</th><th>Roles</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id">
                        <td>
                            <Link :href="route('admin.users.edit', u.id)" class="font-medium text-ink hover:text-brand-600">{{ u.name }}</Link>
                            <p class="text-xs text-muted">{{ u.designation ?? '—' }}</p>
                        </td>
                        <td>{{ u.username }}<span v-if="u.auth_source === 'local'" class="ml-1 text-xs text-amber-700">(local)</span></td>
                        <td>{{ u.department ?? '—' }}</td>
                        <td class="capitalize">{{ u.category }}</td>
                        <td class="text-xs">{{ u.roles.join(', ').replace(/_/g, ' ') || '—' }}</td>
                        <td><StatusBadge :status="u.status" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="users.links" :from="users.from" :to="users.to" :total="users.total" /></template>
        </DataTable>

        <ModalDialog :show="showCreate.open" title="Create local account" description="For people outside the institute directory. Directory users never need one." @close="showCreate.open = false">
            <form class="space-y-4" @submit.prevent="create">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Username" :error="form.errors.username" required><TextInput v-model="form.username" autocomplete="off" /></FormField>
                    <FormField label="Category" :error="form.errors.category" required>
                        <SelectInput v-model="form.category" :options="[{ value: 'vendor', label: 'Vendor' }, { value: 'external', label: 'External member' }]" />
                    </FormField>
                </div>
                <FormField label="Full name / organisation" :error="form.errors.name" required><TextInput v-model="form.name" /></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="E-mail" :error="form.errors.email" required><TextInput v-model="form.email" type="email" /></FormField>
                    <FormField label="Phone" :error="form.errors.phone"><TextInput v-model="form.phone" type="tel" /></FormField>
                </div>
                <FormField label="Initial password" hint="At least 12 characters with upper and lower case and a number. Share it with the person through a separate channel." :error="form.errors.password" required>
                    <TextInput v-model="form.password" type="password" autocomplete="new-password" />
                </FormField>
                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" @click="showCreate.open = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="form.processing">Create account</AppButton>
                </div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
