<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Plus, X } from 'lucide-vue-next';
import type { Catalogue } from './catalogue';

/** List of assignee rules for a step (authority, reporting line, role, committee, form field ...). */
export type AssigneeSpec = Record<string, unknown> & { type: string };
const props = defineProps<{ catalogue: Catalogue; personFields: { value: string; label: string }[]; disabled?: boolean; single?: boolean }>();
const model = defineModel<AssigneeSpec[]>({ default: () => [] });

const types = [
    { value: 'authority', label: 'Authority (office holder)' },
    { value: 'reporting_manager', label: 'Reporting officer' },
    { value: 'role', label: 'Anyone with a role' },
    { value: 'committee', label: 'Committee members' },
    { value: 'field', label: 'Person chosen on the form' },
    { value: 'initiator', label: 'The initiator' },
    { value: 'user', label: 'A named user (avoid)' },
];
const add = () => (model.value = [...model.value, { type: 'authority', authority: props.catalogue.authorities[0]?.code ?? '', department: 'initiator' }]);
const set = (i: number, patch: Record<string, unknown>) => (model.value = model.value.map((s, j) => (j === i ? { ...s, ...patch } : s)));
const setType = (i: number, type: string) => (model.value = model.value.map((s, j) => (j === i ? { type } : s)));
const scoped = (code: unknown) => props.catalogue.authorities.find((a) => a.code === code)?.scope === 'department';
</script>

<template>
    <div class="space-y-2">
        <div v-for="(s, i) in model" :key="i" class="space-y-1.5 rounded-xl bg-surface-muted p-2.5">
            <div class="flex gap-1.5">
                <SelectInput :model-value="s.type" :options="types" :disabled="disabled" aria-label="Assignee type" @update:model-value="setType(i, String($event))" />
                <AppButton v-if="!disabled && !single" variant="danger-ghost" size="sm" :icon="X" aria-label="Remove assignee rule" @click="model = model.filter((_, j) => j !== i)" />
            </div>
            <template v-if="s.type === 'authority'">
                <SelectInput :model-value="(s.authority as string) ?? ''" :options="catalogue.authorities.map((a) => ({ value: a.code, label: a.name }))" :disabled="disabled" aria-label="Authority" @update:model-value="set(i, { authority: $event })" />
                <template v-if="scoped(s.authority)">
                    <SelectInput :model-value="(s.department as string) ?? 'initiator'" :options="[{ value: 'initiator', label: `Initiator's department` }, { value: 'fixed', label: 'A fixed department' }, { value: 'field', label: 'Department chosen on the form' }]" :disabled="disabled" aria-label="Which department" @update:model-value="set(i, { department: $event })" />
                    <SelectInput v-if="s.department === 'fixed'" :model-value="(s.department_id as number) ?? ''" :options="catalogue.departments.map((d) => ({ value: d.id, label: d.name }))" :disabled="disabled" aria-label="Department" @update:model-value="set(i, { department_id: $event })" />
                    <TextInput v-if="s.department === 'field'" :model-value="(s.department_field as string) ?? ''" placeholder="form field key" :disabled="disabled" aria-label="Department field" @update:model-value="set(i, { department_field: $event })" />
                    <CheckboxInput :model-value="!!s.fallback_to_parent" label="If vacant, use the parent unit's holder" :disabled="disabled" @update:model-value="set(i, { fallback_to_parent: $event })" />
                </template>
            </template>
            <TextInput v-else-if="s.type === 'reporting_manager'" :model-value="(s.levels as number) ?? 1" type="number" min="1" max="5" :disabled="disabled" aria-label="Levels up" @update:model-value="set(i, { levels: Number($event) })" />
            <SelectInput v-else-if="s.type === 'role'" :model-value="(s.role as string) ?? ''" :options="catalogue.roles.map((r) => ({ value: r, label: r.replace(/_/g, ' ') }))" :disabled="disabled" aria-label="Role" @update:model-value="set(i, { role: $event })" />
            <SelectInput v-else-if="s.type === 'committee'" :model-value="(s.committee as string) ?? ''" :options="catalogue.committees.map((c) => ({ value: c.code, label: c.name }))" :disabled="disabled" aria-label="Committee" @update:model-value="set(i, { committee: $event })" />
            <SelectInput v-else-if="s.type === 'field'" :model-value="(s.field as string) ?? ''" :options="personFields" placeholder="Employee selector field" :disabled="disabled" aria-label="Form field" @update:model-value="set(i, { field: $event })" />
            <TextInput v-else-if="s.type === 'user'" :model-value="(s.user_id as number) ?? ''" type="number" placeholder="User ID" :disabled="disabled" aria-label="User ID" @update:model-value="set(i, { user_id: Number($event) })" />
        </div>
        <AppButton v-if="!disabled && (!single || !model.length)" variant="ghost" size="sm" :icon="Plus" @click="add">Assignee rule</AppButton>
    </div>
</template>
