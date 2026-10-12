<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import FormField from '@/Components/FormField.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

interface Department { id: number; code: string; name: string; type: string; parent_id: number | null; parent: string | null; is_active: boolean; users_count: number }
interface Designation { id: number; code: string; name: string; level: number | null; is_active: boolean }

const props = defineProps<{ departments: Department[]; designations: Designation[]; types: string[] }>();

const tab = ref<'departments' | 'designations'>('departments');

const deptEditing = ref<Department | null>(null);
const deptOpen = ref(false);
const dept = useForm({ code: '', name: '', type: 'academic', parent_id: null as number | null, is_active: true });
function editDept(d: Department | null) {
    deptEditing.value = d;
    dept.clearErrors();
    Object.assign(dept, d ? { code: d.code, name: d.name, type: d.type, parent_id: d.parent_id, is_active: d.is_active } : { code: '', name: '', type: 'academic', parent_id: null, is_active: true });
    deptOpen.value = true;
}
function saveDept() {
    const opts = { preserveScroll: true, onSuccess: () => (deptOpen.value = false) };
    deptEditing.value ? dept.put(route('admin.departments.update', deptEditing.value.id), opts) : dept.post(route('admin.departments.store'), opts);
}

const desEditing = ref<Designation | null>(null);
const desOpen = ref(false);
const des = useForm({ code: '', name: '', level: null as number | null, is_active: true });
function editDes(d: Designation | null) {
    desEditing.value = d;
    des.clearErrors();
    Object.assign(des, d ? { code: d.code, name: d.name, level: d.level, is_active: d.is_active } : { code: '', name: '', level: null, is_active: true });
    desOpen.value = true;
}
function saveDes() {
    const opts = { preserveScroll: true, onSuccess: () => (desOpen.value = false) };
    desEditing.value ? des.put(route('admin.designations.update', desEditing.value.id), opts) : des.post(route('admin.designations.store'), opts);
}
const parentOptions = () => props.departments.filter((d) => d.id !== deptEditing.value?.id).map((d) => ({ value: d.id, label: `${d.code} – ${d.name}` }));
</script>

<template>
    <AppLayout title="Organisation">
        <PageHeader title="Organisation" description="Departments, offices and designations. Workflows route to authorities, which are held by people in these units.">
            <AppButton :icon="Plus" @click="tab === 'departments' ? editDept(null) : editDes(null)">{{ tab === 'departments' ? 'Department' : 'Designation' }}</AppButton>
        </PageHeader>

        <TabsNav v-model="tab" :items="[{ key: 'departments', label: 'Departments', count: departments.length }, { key: 'designations', label: 'Designations', count: designations.length }]" class="mb-4" />

        <DataTable v-if="tab === 'departments'" :empty="!departments.length" empty-title="No departments yet">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Parent</th><th>Users</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="d in departments" :key="d.id">
                        <td class="font-mono text-xs">{{ d.code }}</td>
                        <td class="font-medium text-ink">{{ d.name }}</td>
                        <td class="capitalize">{{ d.type }}</td>
                        <td>{{ d.parent ?? '—' }}</td>
                        <td>{{ d.users_count }}</td>
                        <td><StatusBadge :status="d.is_active ? 'active' : 'inactive'" /></td>
                        <td class="text-right"><AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${d.name}`" @click="editDept(d)" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <DataTable v-else :empty="!designations.length" empty-title="No designations yet">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Level</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    <tr v-for="d in designations" :key="d.id">
                        <td class="font-mono text-xs">{{ d.code }}</td>
                        <td class="font-medium text-ink">{{ d.name }}</td>
                        <td>{{ d.level ?? '—' }}</td>
                        <td><StatusBadge :status="d.is_active ? 'active' : 'inactive'" /></td>
                        <td class="text-right"><AppButton variant="ghost" size="sm" :icon="Pencil" :aria-label="`Edit ${d.name}`" @click="editDes(d)" /></td>
                    </tr>
                </tbody>
            </table>
        </DataTable>

        <ModalDialog :show="deptOpen" :title="deptEditing ? 'Edit department' : 'New department'" @close="deptOpen = false">
            <form class="space-y-4" @submit.prevent="saveDept">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" hint="Upper case, e.g. CSE. Matched against the directory's department attribute." :error="dept.errors.code" required><TextInput v-model="dept.code" /></FormField>
                    <FormField label="Type" :error="dept.errors.type" required><SelectInput v-model="dept.type" :options="types.map((t) => ({ value: t, label: t }))" /></FormField>
                </div>
                <FormField label="Name" :error="dept.errors.name" required><TextInput v-model="dept.name" /></FormField>
                <FormField label="Parent unit" :error="dept.errors.parent_id"><SelectInput v-model="dept.parent_id" :options="parentOptions()" placeholder="None (top level)" /></FormField>
                <CheckboxInput v-model="dept.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="deptOpen = false">Cancel</AppButton><AppButton type="submit" :loading="dept.processing">Save</AppButton></div>
            </form>
        </ModalDialog>

        <ModalDialog :show="desOpen" :title="desEditing ? 'Edit designation' : 'New designation'" @close="desOpen = false">
            <form class="space-y-4" @submit.prevent="saveDes">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" :error="des.errors.code" required><TextInput v-model="des.code" /></FormField>
                    <FormField label="Seniority level" hint="For sorting and reports only." :error="des.errors.level"><TextInput v-model="des.level" type="number" min="0" /></FormField>
                </div>
                <FormField label="Name" hint="Matched against the directory's title attribute." :error="des.errors.name" required><TextInput v-model="des.name" /></FormField>
                <CheckboxInput v-model="des.is_active" label="Active" />
                <div class="flex justify-end gap-2"><AppButton variant="ghost" @click="desOpen = false">Cancel</AppButton><AppButton type="submit" :loading="des.processing">Save</AppButton></div>
            </form>
        </ModalDialog>
    </AppLayout>
</template>
