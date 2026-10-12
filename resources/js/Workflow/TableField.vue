<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import type { FormField } from './types';

/** Repeating rows (bills, items, travellers). */
const props = defineProps<{ field: FormField; readonly?: boolean; errors?: Record<string, string> }>();
const rows = defineModel<Record<string, unknown>[]>({ required: true });

const add = () => (rows.value = [...rows.value, Object.fromEntries((props.field.columns ?? []).map((c) => [c.key, null]))]);
const remove = (i: number) => (rows.value = rows.value.filter((_, j) => j !== i));
const set = (i: number, key: string, v: unknown) => (rows.value = rows.value.map((r, j) => (j === i ? { ...r, [key]: v } : r)));
const cellError = (i: number, key: string) => props.errors?.[`data.${props.field.key}.${i}.${key}`];
const type = (t: string) => ({ number: 'number', decimal: 'number', date: 'date', email: 'email', tel: 'tel' })[t] ?? 'text';
</script>

<template>
    <div class="overflow-x-auto rounded-xl ring-1 ring-line">
        <table class="w-full text-sm">
            <thead class="bg-surface-muted text-left text-xs text-muted">
                <tr>
                    <th class="w-10 px-3 py-2">#</th>
                    <th v-for="c in field.columns" :key="c.key" class="px-3 py-2 font-medium">{{ c.label }}<span v-if="c.required && !readonly" class="text-red-600"> *</span></th>
                    <th v-if="!readonly" class="w-12"><span class="sr-only">Remove</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, i) in rows" :key="i" class="border-t border-line-soft align-top">
                    <td class="px-3 py-2.5 text-muted">{{ i + 1 }}</td>
                    <td v-for="c in field.columns" :key="c.key" class="px-2 py-1.5">
                        <span v-if="readonly" class="block py-1 text-ink">{{ row[c.key] ?? '—' }}</span>
                        <template v-else>
                            <SelectInput v-if="c.type === 'select'" :model-value="(row[c.key] as string) ?? ''" :options="(c.options ?? []).map((o) => ({ value: String(o.value), label: o.label }))" :aria-label="`${c.label}, row ${i + 1}`" @update:model-value="set(i, c.key, $event)" />
                            <TextInput v-else :model-value="(row[c.key] as string) ?? ''" :type="type(c.type)" :step="c.type === 'decimal' ? 'any' : undefined" :aria-label="`${c.label}, row ${i + 1}`" @update:model-value="set(i, c.key, $event)" />
                            <p v-if="cellError(i, c.key)" class="mt-1 text-xs text-red-600">{{ cellError(i, c.key) }}</p>
                        </template>
                    </td>
                    <td v-if="!readonly" class="px-2 py-1.5"><AppButton variant="danger-ghost" size="sm" :icon="Trash2" :aria-label="`Remove row ${i + 1}`" @click="remove(i)" /></td>
                </tr>
                <tr v-if="!rows.length"><td :colspan="(field.columns?.length ?? 0) + 2" class="px-3 py-3 text-center text-muted">No rows.</td></tr>
            </tbody>
        </table>
        <div v-if="!readonly && rows.length < (field.max_rows ?? 50)" class="border-t border-line-soft px-3 py-2">
            <AppButton variant="ghost" size="sm" :icon="Plus" @click="add">Add row</AppButton>
        </div>
    </div>
</template>
