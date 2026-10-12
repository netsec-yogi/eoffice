<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { OPERATORS, type Operator, type Rule } from '../rules';

/**
 * Point-and-click editor for the rule language: "all"/"any" of simple
 * conditions. Nested rules (written elsewhere) are edited as JSON so nothing
 * is lost.
 */
const props = defineProps<{ fields: { value: string; label: string }[]; disabled?: boolean; emptyLabel?: string }>();
const model = defineModel<Rule | null | undefined>();

interface Cond { field: string; op: Operator; value: string }
type Flat = { mode: 'all' | 'any'; conditions: Cond[] };

function flatten(rule: Rule | null | undefined): Flat | null {
    if (!rule) return { mode: 'all', conditions: [] };
    const leaf = (r: Rule): Cond | null => ('field' in r ? { field: r.field, op: r.op, value: Array.isArray(r.value) ? r.value.join(', ') : r.value === undefined || r.value === null ? '' : String(r.value) } : null);
    if ('field' in rule) return { mode: 'all', conditions: [leaf(rule)!] };
    const group = 'all' in rule ? rule.all : 'any' in rule ? rule.any : null;
    if (!group || group.some((r) => !('field' in r))) return null;
    return { mode: 'all' in rule ? 'all' : 'any', conditions: group.map((r) => leaf(r)!) };
}

const flat = ref<Flat | null>(flatten(model.value));
const json = ref(JSON.stringify(model.value ?? null, null, 2));
const jsonError = ref('');

function parse(c: Cond): unknown {
    if (['in', 'not_in'].includes(c.op)) return c.value.split(',').map((s) => s.trim()).filter(Boolean);
    if (c.value === 'true' || c.value === 'false') return c.value === 'true';
    return c.value;
}
function emit() {
    if (!flat.value) return;
    const leaves: Rule[] = flat.value.conditions.filter((c) => c.field).map((c) => (OPERATORS.find((o) => o.value === c.op)?.needsValue ? { field: c.field, op: c.op, value: parse(c) } : { field: c.field, op: c.op }));
    model.value = leaves.length === 0 ? null : leaves.length === 1 ? leaves[0]! : flat.value.mode === 'all' ? { all: leaves } : { any: leaves };
}
watch(flat, emit, { deep: true });
watch(json, (v) => {
    if (flat.value) return;
    try {
        model.value = JSON.parse(v);
        jsonError.value = '';
    } catch {
        jsonError.value = 'Not valid JSON';
    }
});

const add = () => flat.value!.conditions.push({ field: props.fields[0]?.value ?? '', op: 'eq', value: '' });
const needsValue = (op: Operator) => OPERATORS.find((o) => o.value === op)?.needsValue;
const fieldOptions = computed(() => props.fields);
</script>

<template>
    <div v-if="flat" class="space-y-2">
        <p v-if="!flat.conditions.length" class="text-xs text-muted">{{ emptyLabel ?? 'Always.' }}</p>
        <div v-if="flat.conditions.length > 1" class="flex items-center gap-2 text-xs text-muted">
            Match
            <SelectInput v-model="flat.mode" :options="[{ value: 'all', label: 'all' }, { value: 'any', label: 'any' }]" class="!w-20" :disabled="disabled" aria-label="Match all or any" />
            of these:
        </div>
        <div v-for="(c, i) in flat.conditions" :key="i" class="grid grid-cols-[1fr_auto] gap-1.5 rounded-xl bg-surface-muted p-2">
            <div class="grid gap-1.5">
                <SelectInput v-model="c.field" :options="fieldOptions" placeholder="Field" :disabled="disabled" aria-label="Field" />
                <div class="grid grid-cols-2 gap-1.5">
                    <SelectInput v-model="c.op" :options="OPERATORS.map((o) => ({ value: o.value, label: o.label }))" :disabled="disabled" aria-label="Operator" />
                    <TextInput v-if="needsValue(c.op)" v-model="c.value" :placeholder="['in', 'not_in'].includes(c.op) ? 'a, b, c' : 'value'" :disabled="disabled" aria-label="Value" />
                </div>
            </div>
            <AppButton v-if="!disabled" variant="danger-ghost" size="sm" :icon="X" aria-label="Remove condition" @click="flat.conditions.splice(i, 1)" />
        </div>
        <AppButton v-if="!disabled" variant="ghost" size="sm" :icon="Plus" @click="add">Condition</AppButton>
    </div>
    <div v-else>
        <p class="mb-1 text-xs text-muted">This condition is nested; edit it as JSON.</p>
        <TextArea v-model="json" rows="6" class="font-mono text-xs" :disabled="disabled" />
        <p v-if="jsonError" class="text-xs text-red-600">{{ jsonError }}</p>
    </div>
</template>
