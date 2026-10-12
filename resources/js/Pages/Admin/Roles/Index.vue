<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { reactive } from 'vue';

interface Role { id: number; name: string; users_count: number; permissions: string[]; locked: boolean }
const props = defineProps<{ roles: Role[]; permissions: string[] }>();

const selected = reactive(Object.fromEntries(props.roles.map((r) => [r.id, [...r.permissions]])) as Record<number, string[]>);
const save = (role: Role) => router.put(route('admin.roles.update', role.id), { permissions: selected[role.id] }, { preserveScroll: true });
const create = useForm({ name: '' });
const add = () => create.post(route('admin.roles.store'), { preserveScroll: true, onSuccess: () => create.reset() });
</script>

<template>
    <AppLayout title="Roles">
        <PageHeader title="Roles and permissions" description="Roles grant access to platform functions. Who may approve what is decided by workflow assignment (authorities, committees, reporting lines), not by roles." />

        <form class="card mb-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="add">
            <FormField label="New role" hint="lower_snake_case, e.g. registrar_staff" :error="create.errors.name" class="min-w-64 flex-1"><TextInput v-model="create.name" /></FormField>
            <AppButton type="submit" :loading="create.processing">Create role</AppButton>
        </form>

        <div class="grid gap-4 lg:grid-cols-2">
            <section v-for="role in roles" :key="role.id" class="card p-5">
                <header class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-ink capitalize">{{ role.name.replace(/_/g, ' ') }} <span class="text-sm font-normal text-muted">· {{ role.users_count }} users</span></h2>
                    <Lock v-if="role.locked" :size="16" class="text-muted" aria-label="Fixed: always has every permission" />
                </header>
                <fieldset :disabled="role.locked" class="grid gap-1.5 sm:grid-cols-2">
                    <legend class="sr-only">Permissions of {{ role.name }}</legend>
                    <CheckboxInput v-for="p in permissions" :key="p" v-model="selected[role.id]" :value="p" :label="p" />
                </fieldset>
                <div v-if="!role.locked" class="mt-4 flex justify-end"><AppButton size="sm" variant="outline" @click="save(role)">Save</AppButton></div>
            </section>
        </div>
    </AppLayout>
</template>
