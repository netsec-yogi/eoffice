<script setup lang="ts">
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import UserPicker from '@/Components/UserPicker.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DocumentField from './DocumentField.vue';
import { evaluate } from './formula';
import { passes } from './rules';
import TableField from './TableField.vue';
import type { FormField as Field, FormSchema } from './types';

/**
 * Renders a published form schema. Visibility, conditional requirements
 * and calculated values update as the user types; the server repeats every
 * check on submit (FormDataValidator), so this is convenience, not control.
 *
 * `editableAt`: null = initiator filling the form; a node key = an approver
 * editing only the fields marked editable at that step.
 */
const props = defineProps<{
    schema: FormSchema;
    errors?: Record<string, string>;
    editableAt?: string | null;
    readonly?: boolean;
    display?: Record<string, unknown>;
}>();
const data = defineModel<Record<string, unknown>>({ required: true });

const user = usePage().props.auth.user!;
const context = computed(() => ({ ...data.value, initiator: { id: user.id, category: user.category, department: user.department?.code } }));

const visibleSections = computed(() => props.schema.sections.filter((s) => passes(s.visible_if, context.value)));
const visible = (f: Field) => passes(f.visible_if, context.value);
const required = (f: Field) => !!f.required || (!!f.required_if && passes(f.required_if, context.value));
const editable = (f: Field) => {
    if (props.readonly) return false;
    if (['calculated', 'readonly', 'signature'].includes(f.type)) return false;
    if (props.editableAt) return (f.editable_at ?? []).includes(props.editableAt);
    return f.editable_at?.length ? f.editable_at.includes('start') : f.type !== 'remark';
};
const calculated = (f: Field) => {
    try {
        return f.formula ? evaluate(f.formula, context.value) : null;
    } catch {
        return null;
    }
};
const error = (key: string) => props.errors?.[`data.${key}`] ?? props.errors?.[key];
const set = (key: string, value: unknown) => (data.value = { ...data.value, [key]: value });
const options = (f: Field) => (f.options ?? []).map((o) => ({ value: String(o.value), label: o.label }));
const inputType = (t: string) => ({ number: 'number', decimal: 'number', date: 'date', time: 'time', datetime: 'datetime-local', email: 'email', tel: 'tel' })[t] ?? 'text';

function shown(f: Field): string {
    const v = data.value[f.key];
    const d = props.display?.[f.key];
    if (typeof d === 'string') return d;
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) return '—';
    if (typeof v === 'boolean') return v ? 'Yes' : 'No';
    return Array.isArray(v) ? v.join(', ') : String(v);
}
</script>

<template>
    <div class="space-y-6">
        <section v-for="section in visibleSections" :key="section.key" class="card p-6">
            <h2 class="font-semibold text-ink">{{ section.title }}</h2>
            <p v-if="section.description" class="mt-1 text-sm text-muted">{{ section.description }}</p>
            <div class="mt-5 grid gap-x-5 gap-y-4 sm:grid-cols-2">
                <template v-for="f in section.fields" :key="f.key">
                    <div v-if="visible(f)" :class="['textarea', 'table', 'files', 'readonly', 'remark', 'file'].includes(f.type) ? 'sm:col-span-2' : ''">
                        <!-- Static text -->
                        <div v-if="f.type === 'readonly'" class="rounded-xl bg-surface-muted px-4 py-3 text-sm text-ink-soft">
                            <p v-if="f.label" class="font-medium text-ink">{{ f.label }}</p>
                            <p class="whitespace-pre-line">{{ f.content }}</p>
                        </div>

                        <!-- Signature placeholder: filled by the signing step, never typed -->
                        <FormField v-else-if="f.type === 'signature'" :label="f.label" :hint="f.help">
                            <div class="flex h-20 items-center justify-center rounded-xl border border-dashed border-line-strong text-xs text-subtle">Signed at the signature step</div>
                        </FormField>

                        <FormField v-else-if="f.type === 'calculated'" :label="f.label" :hint="f.help ?? 'Calculated automatically'">
                            <p class="flex h-10 items-center rounded-xl bg-surface-sunken px-3 font-mono text-sm text-ink">{{ calculated(f) ?? '—' }}</p>
                        </FormField>

                        <!-- Read-only view of a value -->
                        <FormField v-else-if="!editable(f)" :label="f.label">
                            <DocumentField v-if="f.type === 'file' || f.type === 'files'" :model-value="data[f.key]" :display="display?.[f.key]" readonly />
                            <TableField v-else-if="f.type === 'table'" :field="f" :model-value="(data[f.key] as Record<string, unknown>[]) ?? []" readonly />
                            <p v-else class="min-h-10 rounded-xl bg-surface-muted px-3 py-2.5 text-sm whitespace-pre-line text-ink">{{ shown(f) }}</p>
                        </FormField>

                        <FormField v-else :label="f.label" :hint="f.help" :error="error(f.key)" :required="required(f)">
                            <TextArea v-if="f.type === 'textarea' || f.type === 'remark'" :model-value="(data[f.key] as string) ?? ''" rows="4" :placeholder="f.placeholder" :maxlength="f.max_length" @update:model-value="set(f.key, $event)" />
                            <SelectInput v-else-if="f.type === 'select'" :model-value="(data[f.key] as string) ?? ''" :options="options(f)" :placeholder="f.placeholder ?? 'Choose…'" @update:model-value="set(f.key, $event)" />
                            <MultiSelect v-else-if="f.type === 'multiselect'" :model-value="(data[f.key] as string[]) ?? []" :options="options(f)" :placeholder="f.placeholder ?? 'Choose…'" @update:model-value="set(f.key, $event)" />
                            <fieldset v-else-if="f.type === 'radio'" class="flex flex-wrap gap-x-5 gap-y-2 pt-1">
                                <legend class="sr-only">{{ f.label }}</legend>
                                <label v-for="o in options(f)" :key="o.value" class="flex items-center gap-2 text-sm text-ink">
                                    <input type="radio" :name="f.key" :value="o.value" :checked="String(data[f.key] ?? '') === o.value" class="text-brand-600 focus:ring-brand-500" @change="set(f.key, o.value)" />
                                    {{ o.label }}
                                </label>
                            </fieldset>
                            <CheckboxInput v-else-if="f.type === 'checkbox'" :model-value="!!data[f.key]" :label="f.placeholder ?? 'Yes'" @update:model-value="set(f.key, $event)" />
                            <UserPicker
                                v-else-if="['user', 'student', 'vendor'].includes(f.type)"
                                :model-value="(data[f.key] as number) ?? null"
                                :category="f.type === 'user' ? f.category : f.type"
                                :selected="typeof display?.[f.key] === 'string' ? { id: data[f.key] as number, name: display[f.key] as string, username: '' } : null"
                                @update:model-value="set(f.key, $event)"
                            />
                            <SelectInput
                                v-else-if="f.type === 'department'"
                                :model-value="(data[f.key] as number) ?? ''"
                                :options="(($page.props as Record<string, unknown>).departments as { id: number; name: string }[] | undefined)?.map((d) => ({ value: d.id, label: d.name })) ?? []"
                                @update:model-value="set(f.key, $event)"
                            />
                            <DocumentField v-else-if="f.type === 'file' || f.type === 'files'" :model-value="data[f.key]" :multiple="f.type === 'files'" :accept="f.accept" :max-files="f.max_files" :display="display?.[f.key]" @update:model-value="set(f.key, $event)" />
                            <TableField v-else-if="f.type === 'table'" :field="f" :model-value="(data[f.key] as Record<string, unknown>[]) ?? []" :errors="errors" @update:model-value="set(f.key, $event)" />
                            <TextInput
                                v-else
                                :model-value="(data[f.key] as string) ?? ''"
                                :type="inputType(f.type)"
                                :step="f.type === 'decimal' ? 'any' : undefined"
                                :min="f.min"
                                :max="f.max"
                                :maxlength="f.max_length"
                                :placeholder="f.placeholder"
                                @update:model-value="set(f.key, $event)"
                            />
                        </FormField>
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
