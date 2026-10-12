<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Plus, X } from 'lucide-vue-next';
import AssigneeEditor, { type AssigneeSpec } from './AssigneeEditor.vue';
import type { Catalogue } from './catalogue';

interface Escalation { after: number; unit: string; to: AssigneeSpec; action: 'notify' | 'reassign' }
interface Sla { duration?: number | null; unit?: string; due_field?: string | null; escalations?: Escalation[] }

const props = defineProps<{ catalogue: Catalogue; personFields: { value: string; label: string }[]; dateFields?: { value: string; label: string }[]; disabled?: boolean }>();
const model = defineModel<Sla | null | undefined>();

const unitLabels: Record<string, string> = { minutes: 'minutes', hours: 'hours', days: 'calendar days', working_hours: 'working hours', working_days: 'working days' };
const units = () => props.catalogue.units.map((u) => ({ value: u, label: unitLabels[u] ?? u }));
const sla = () => model.value ?? {};
const set = (patch: Partial<Sla>) => (model.value = { unit: 'working_days', ...sla(), ...patch });
const escalations = () => sla().escalations ?? [];
const setEsc = (i: number, patch: Partial<Escalation>) => set({ escalations: escalations().map((e, j) => (j === i ? { ...e, ...patch } : e)) });
const addEsc = () => set({ escalations: [...escalations(), { after: 1, unit: 'working_days', to: { type: 'reporting_manager', levels: 1 }, action: 'notify' }] });
</script>

<template>
    <div class="space-y-2">
        <div class="grid grid-cols-2 gap-1.5">
            <TextInput :model-value="sla().duration ?? ''" type="number" min="0" step="0.5" placeholder="No limit" :disabled="disabled" aria-label="Time limit" @update:model-value="set({ duration: $event === '' ? null : Number($event) })" />
            <SelectInput :model-value="sla().unit ?? 'working_days'" :options="units()" :disabled="disabled" aria-label="Unit" @update:model-value="set({ unit: String($event) })" />
        </div>
        <SelectInput v-if="dateFields?.length" :model-value="sla().due_field ?? ''" :options="dateFields" placeholder="…or due on a date from the form" :disabled="disabled" aria-label="Deadline from a form date" @update:model-value="set({ due_field: $event ? String($event) : null })" />
        <template v-if="sla().duration || sla().due_field">
            <p class="text-xs text-muted">A reminder is sent when 75% of the time is used and again when it runs out.</p>
            <div v-for="(e, i) in escalations()" :key="i" class="space-y-1.5 rounded-xl bg-surface-muted p-2.5">
                <div class="flex items-center justify-between text-xs font-semibold text-ink">Escalation level {{ i + 1 }}
                    <AppButton v-if="!disabled" variant="danger-ghost" size="sm" :icon="X" :aria-label="`Remove level ${i + 1}`" @click="set({ escalations: escalations().filter((_, j) => j !== i) })" />
                </div>
                <div class="grid grid-cols-2 gap-1.5">
                    <TextInput :model-value="e.after" type="number" min="0.5" step="0.5" :disabled="disabled" aria-label="After the deadline" @update:model-value="setEsc(i, { after: Number($event) })" />
                    <SelectInput :model-value="e.unit" :options="units()" :disabled="disabled" aria-label="Unit" @update:model-value="setEsc(i, { unit: String($event) })" />
                </div>
                <SelectInput :model-value="e.action" :options="[{ value: 'notify', label: 'Alert this person' }, { value: 'reassign', label: 'Move the task to this person' }]" :disabled="disabled" aria-label="Escalation action" @update:model-value="setEsc(i, { action: $event as 'notify' | 'reassign' })" />
                <AssigneeEditor :model-value="[e.to]" single :catalogue="catalogue" :person-fields="personFields" :disabled="disabled" @update:model-value="setEsc(i, { to: $event[0] ?? { type: 'reporting_manager' } })" />
            </div>
            <AppButton v-if="!disabled" variant="ghost" size="sm" :icon="Plus" @click="addEsc">Escalation level</AppButton>
        </template>
    </div>
</template>
