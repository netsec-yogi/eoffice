<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TabsNav from '@/Components/TabsNav.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import RuleEditor from '@/Workflow/Designer/RuleEditor.vue';
import DynamicForm from '@/Workflow/DynamicForm.vue';
import type { FormField as Field, FormSchema, FormSection } from '@/Workflow/types';
import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, CircleAlert, CopyPlus, Plus, Rocket, Save, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    template: { id: number; key: string; name: string; description: string | null };
    version: { id: number; version: number; status: string; schema: FormSchema; published_at: string | null };
    versions: { id: number; version: number; status: string }[];
    problems: string[];
    fieldTypes: string[];
    tableColumnTypes: string[];
    optionSources: string[];
    canPublish: boolean;
}>();

const readonly = computed(() => props.version.status !== 'draft');
const schema = ref<FormSchema>(JSON.parse(JSON.stringify(props.version.schema)));
const dirty = ref(false);
watch(schema, () => (dirty.value = true), { deep: true });

const typeLabels: Record<string, string> = {
    text: 'Text', textarea: 'Long text', number: 'Whole number', decimal: 'Decimal / amount', date: 'Date', time: 'Time', datetime: 'Date and time', email: 'E-mail', tel: 'Telephone',
    select: 'Dropdown', multiselect: 'Multi-select', radio: 'Radio buttons', checkbox: 'Checkbox / declaration', table: 'Repeating table', file: 'File upload', files: 'Multiple files',
    user: 'Employee selector', student: 'Student selector', department: 'Department selector', vendor: 'Vendor selector', calculated: 'Calculated value', readonly: 'Static text',
    remark: 'Approver remark', signature: 'Signature placeholder',
};

const sel = ref<{ s: number; f: number | null }>({ s: 0, f: null });
const section = computed<FormSection | undefined>(() => schema.value.sections[sel.value.s]);
const field = computed<Field | undefined>(() => (sel.value.f === null ? undefined : section.value?.fields[sel.value.f]));
const allFields = computed(() => schema.value.sections.flatMap((s) => s.fields));
const ruleFields = computed(() => [
    ...allFields.value.filter((f) => f !== field.value && !['readonly', 'signature', 'table', 'file', 'files'].includes(f.type)).map((f) => ({ value: f.key, label: f.label || f.key })),
    { value: 'initiator.category', label: 'Initiator category' },
    { value: 'initiator.department', label: 'Initiator department code' },
]);

function uniqueKey(base: string): string {
    const keys = new Set(allFields.value.map((f) => f.key));
    let k = base;
    let i = 2;
    while (keys.has(k)) k = `${base}_${i++}`;
    return k;
}
function addField(type: string) {
    if (!section.value) return;
    const f: Field = { key: uniqueKey(type), type, label: typeLabels[type] ?? type };
    if (['select', 'multiselect', 'radio'].includes(type)) f.options = [{ value: 'option_1', label: 'Option 1' }];
    if (type === 'table') f.columns = [{ key: 'description', type: 'text', label: 'Description', required: true }, { key: 'amount', type: 'decimal', label: 'Amount' }];
    if (type === 'calculated') f.formula = '';
    if (type === 'readonly') f.content = 'Explanatory text.';
    section.value.fields.push(f);
    sel.value = { s: sel.value.s, f: section.value.fields.length - 1 };
}
function addSection() {
    schema.value.sections.push({ key: uniqueKey('section').replace(/^section/, `section_${schema.value.sections.length + 1}`), title: 'New section', fields: [] });
    sel.value = { s: schema.value.sections.length - 1, f: null };
}
function move<T>(list: T[], i: number, d: number) {
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j]!, list[i]!];
}
async function removeField(s: number, f: number) {
    if (await ask('Remove this field? Rules that refer to it will be reported when you save.')) {
        schema.value.sections[s]!.fields.splice(f, 1);
        sel.value = { s, f: null };
    }
}
async function removeSection(s: number) {
    if (await ask('Remove this section and all its fields?')) {
        schema.value.sections.splice(s, 1);
        sel.value = { s: 0, f: null };
    }
}

const preview = ref<Record<string, unknown>>({});
const tab = ref<'build' | 'preview'>('build');
const saving = ref(false);
const save = (then?: () => void) =>
    router.put(route('admin.forms.versions.save', props.version.id), { schema: schema.value } as unknown as RequestPayload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onSuccess: () => {
            dirty.value = false;
            then?.();
        },
    });
const publish = () => save(() => router.post(route('admin.forms.versions.publish', props.version.id), {}, { preserveScroll: true }));
const newDraft = () => router.post(route('admin.forms.draft', props.template.id));
const openVersion = (id?: number | string | null) => router.get(route('admin.forms.edit', props.template.id), { version: id });
const optionsText = computed({
    get: () => (field.value?.options ?? []).map((o) => (o.value === o.label ? o.label : `${o.value}|${o.label}`)).join('\n'),
    set: (v: string) => {
        if (!field.value) return;
        field.value.options = v.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
            const [value, label] = l.includes('|') ? l.split('|', 2) : [l.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || l, l];
            return { value: value!.trim(), label: (label ?? value)!.trim() };
        });
    },
});
const csv = (v?: string[]) => (v ?? []).join(', ');
const fromCsv = (v: string) => v.split(',').map((x) => x.trim()).filter(Boolean);
</script>

<template>
    <AppLayout :title="`Form: ${template.name}`">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-mono text-xs text-muted">{{ template.key }}</p>
                <h1 class="flex items-center gap-2 text-xl font-bold text-ink">
                    {{ template.name }} <StatusBadge :status="version.status" :label="`v${version.version} ${version.status}`" />
                    <span v-if="dirty && !readonly" class="text-xs font-normal text-amber-700">unsaved changes</span>
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <SelectInput :model-value="version.id" :options="versions.map((v) => ({ value: v.id, label: `v${v.version} · ${v.status}` }))" class="!w-40" aria-label="Version" @update:model-value="openVersion" />
                <AppButton v-if="readonly" size="sm" :icon="CopyPlus" @click="newDraft">Open new draft</AppButton>
                <template v-else>
                    <AppButton variant="outline" size="sm" :icon="Save" :loading="saving" @click="save()">Save & check</AppButton>
                    <AppButton v-if="canPublish" size="sm" :icon="Rocket" @click="publish">Publish</AppButton>
                </template>
            </div>
        </header>

        <AlertBox v-if="readonly" tone="info" class="mb-4">This version is {{ version.status }} and cannot be changed. Open a new draft, publish it, then attach it to the workflow.</AlertBox>
        <AlertBox v-if="problems.length" tone="danger" class="mb-4" title="Fix before publishing">
            <ul class="list-disc pl-5"><li v-for="p in problems" :key="p">{{ p }}</li></ul>
        </AlertBox>

        <TabsNav v-model="tab" :items="[{ key: 'build', label: 'Build' }, { key: 'preview', label: 'Preview' }]" class="mb-4" />

        <div v-if="tab === 'preview'" class="max-w-4xl">
            <AlertBox tone="info" class="mb-4">Try the form: conditions and calculations work as they will for users. Nothing is saved.</AlertBox>
            <DynamicForm v-model="preview" :schema="schema" />
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-[1fr_24rem]">
            <div class="space-y-4">
                <section v-for="(s, si) in schema.sections" :key="si" :class="['card p-4', sel.s === si && sel.f === null && 'ring-2 ring-brand-500']">
                    <header class="flex items-center gap-2">
                        <button class="min-w-0 flex-1 text-left" @click="sel = { s: si, f: null }">
                            <p class="font-semibold text-ink">{{ s.title }}</p>
                            <p class="font-mono text-xs text-muted">{{ s.key }}<span v-if="s.visible_if"> · conditional</span></p>
                        </button>
                        <template v-if="!readonly">
                            <AppButton variant="ghost" size="sm" :icon="ArrowUp" aria-label="Move section up" @click="move(schema.sections, si, -1)" />
                            <AppButton variant="ghost" size="sm" :icon="ArrowDown" aria-label="Move section down" @click="move(schema.sections, si, 1)" />
                            <AppButton variant="danger-ghost" size="sm" :icon="Trash2" aria-label="Remove section" @click="removeSection(si)" />
                        </template>
                    </header>
                    <ul class="mt-3 space-y-1">
                        <li v-for="(f, fi) in s.fields" :key="f.key + fi" :class="['flex items-center gap-2 rounded-xl px-3 py-2', sel.s === si && sel.f === fi ? 'bg-brand-50 ring-1 ring-brand-300 dark:bg-brand-500/10' : 'bg-surface-muted']">
                            <button class="min-w-0 flex-1 text-left text-sm" @click="sel = { s: si, f: fi }">
                                <span class="font-medium text-ink">{{ f.label || f.key }}</span><span v-if="f.required" class="text-red-600"> *</span>
                                <span class="ml-2 text-xs text-muted">{{ typeLabels[f.type] ?? f.type }} · {{ f.key }}</span>
                                <span v-if="f.visible_if || f.required_if" class="ml-1 text-xs text-amber-700">· conditional</span>
                                <span v-if="f.editable_at?.length" class="ml-1 text-xs text-brand-700">· at {{ f.editable_at.join(', ') }}</span>
                            </button>
                            <template v-if="!readonly">
                                <AppButton variant="ghost" size="sm" :icon="ArrowUp" aria-label="Move up" @click="move(s.fields, fi, -1)" />
                                <AppButton variant="ghost" size="sm" :icon="ArrowDown" aria-label="Move down" @click="move(s.fields, fi, 1)" />
                                <AppButton variant="danger-ghost" size="sm" :icon="Trash2" aria-label="Remove field" @click="removeField(si, fi)" />
                            </template>
                        </li>
                        <li v-if="!s.fields.length" class="px-3 py-2 text-sm text-muted">No fields yet.</li>
                    </ul>
                    <div v-if="!readonly && sel.s === si" class="mt-3 flex flex-wrap items-center gap-2 border-t border-line-soft pt-3">
                        <SelectInput :model-value="''" :options="fieldTypes.map((t) => ({ value: t, label: typeLabels[t] ?? t }))" placeholder="Add a field…" class="!w-56" aria-label="Add a field" @update:model-value="(t) => t && addField(String(t))" />
                    </div>
                </section>
                <AppButton v-if="!readonly" variant="outline" :icon="Plus" @click="addSection">Section</AppButton>
            </div>

            <aside class="card h-fit space-y-3 p-4 lg:sticky lg:top-20">
                <fieldset v-if="field" :disabled="readonly" class="space-y-3">
                    <p class="text-xs font-semibold tracking-wider text-subtle uppercase">{{ typeLabels[field.type] }}</p>
                    <FormField label="Label"><TextInput v-model="field.label" /></FormField>
                    <FormField label="Key" hint="lower_snake_case; used by rules, workflows and reports. Avoid renaming after publishing."><TextInput v-model="field.key" /></FormField>
                    <FormField v-if="field.type === 'readonly'" label="Text"><TextArea v-model="field.content" rows="4" /></FormField>
                    <template v-else>
                        <FormField label="Help text"><TextInput v-model="field.help" /></FormField>
                        <FormField v-if="!['checkbox', 'calculated', 'signature', 'table', 'file', 'files'].includes(field.type)" label="Placeholder"><TextInput v-model="field.placeholder" /></FormField>
                        <CheckboxInput v-if="!['calculated', 'signature'].includes(field.type)" v-model="field.required" label="Required" />
                    </template>
                    <FormField v-if="['select', 'multiselect', 'radio'].includes(field.type) && optionSources.length" label="Options come from" hint="A live list kept by a register, or a fixed list below.">
                        <SelectInput :model-value="field.source ?? ''" :options="optionSources.map((s) => ({ value: s, label: s.replace(/_/g, ' ') }))" placeholder="Fixed list" @update:model-value="field.source = $event ? String($event) : undefined" />
                    </FormField>
                    <FormField v-if="['select', 'multiselect', 'radio'].includes(field.type) && !field.source" label="Options" hint="One per line. Use value|Label to set a stored value."><TextArea v-model="optionsText" rows="5" class="font-mono text-xs" /></FormField>
                    <div v-if="['number', 'decimal', 'date', 'datetime'].includes(field.type)" class="grid grid-cols-2 gap-2">
                        <FormField label="Minimum" :hint="field.type.startsWith('date') ? 'date, today, or a field key' : undefined"><TextInput v-model="field.min" /></FormField>
                        <FormField label="Maximum"><TextInput v-model="field.max" /></FormField>
                    </div>
                    <div v-if="['text', 'textarea', 'remark'].includes(field.type)" class="grid grid-cols-2 gap-2">
                        <FormField label="Min length"><TextInput v-model.number="field.min_length" type="number" min="0" /></FormField>
                        <FormField label="Max length"><TextInput v-model.number="field.max_length" type="number" min="1" /></FormField>
                    </div>
                    <FormField v-if="field.type === 'text'" label="Pattern" hint="Regular expression, e.g. ^[A-Z]{5}[0-9]{4}[A-Z]$"><TextInput v-model="field.pattern" class="font-mono" /></FormField>
                    <template v-if="['file', 'files'].includes(field.type)">
                        <FormField label="Allowed types" hint="Extensions, comma separated, e.g. pdf, jpg"><TextInput :model-value="csv(field.accept)" @update:model-value="field.accept = fromCsv(String($event ?? ''))" /></FormField>
                        <div class="grid grid-cols-2 gap-2">
                            <FormField label="Max size (KB)"><TextInput v-model.number="field.max_kb" type="number" min="1" /></FormField>
                            <FormField v-if="field.type === 'files'" label="Max files"><TextInput v-model.number="field.max_files" type="number" min="1" /></FormField>
                        </div>
                    </template>
                    <FormField v-if="field.type === 'user'" label="Limit to category"><SelectInput v-model="field.category" :options="['faculty', 'staff', 'student'].map((c) => ({ value: c, label: c }))" placeholder="Any" /></FormField>
                    <FormField v-if="field.type === 'calculated'" label="Formula" hint="Fields and + - * / ( ); sum(table.column), count(table), min, max, round(x, 2), days(from, to)"><TextInput v-model="field.formula" class="font-mono" /></FormField>
                    <template v-if="field.type === 'table'">
                        <p class="text-sm font-medium text-ink">Columns</p>
                        <div v-for="(c, ci) in field.columns" :key="ci" class="grid grid-cols-[1fr_1fr_auto] gap-1.5 rounded-xl bg-surface-muted p-2">
                            <TextInput v-model="c.label" aria-label="Column label" placeholder="Label" />
                            <SelectInput v-model="c.type" :options="tableColumnTypes.map((t) => ({ value: t, label: typeLabels[t] ?? t }))" aria-label="Column type" />
                            <AppButton variant="danger-ghost" size="sm" :icon="Trash2" aria-label="Remove column" @click="field.columns!.splice(ci, 1)" />
                            <TextInput v-model="c.key" aria-label="Column key" placeholder="key" class="font-mono" />
                            <div class="col-span-2 flex items-center"><CheckboxInput v-model="c.required" label="Required" /></div>
                        </div>
                        <AppButton variant="ghost" size="sm" :icon="Plus" @click="field.columns!.push({ key: `col_${field.columns!.length + 1}`, type: 'text', label: 'Column' })">Column</AppButton>
                        <div class="grid grid-cols-2 gap-2">
                            <FormField label="Min rows"><TextInput v-model.number="field.min_rows" type="number" min="0" /></FormField>
                            <FormField label="Max rows"><TextInput v-model.number="field.max_rows" type="number" min="1" /></FormField>
                        </div>
                    </template>
                    <FormField label="Editable at workflow steps" hint="Step keys, comma separated (e.g. hod). Empty = filled by the initiator. Add 'start' to allow both.">
                        <TextInput :model-value="csv(field.editable_at)" class="font-mono" @update:model-value="field.editable_at = fromCsv(String($event ?? ''))" />
                    </FormField>
                    <FormField label="Show only if"><RuleEditor v-model="field.visible_if" :fields="ruleFields" :disabled="readonly" empty-label="Always shown." /></FormField>
                    <FormField v-if="!field.required && !['calculated', 'readonly', 'signature'].includes(field.type)" label="Required only if"><RuleEditor v-model="field.required_if" :fields="ruleFields" :disabled="readonly" empty-label="Never required." /></FormField>
                </fieldset>

                <fieldset v-else-if="section" :disabled="readonly" class="space-y-3">
                    <p class="text-xs font-semibold tracking-wider text-subtle uppercase">Section</p>
                    <FormField label="Title"><TextInput v-model="section.title" /></FormField>
                    <FormField label="Key"><TextInput v-model="section.key" /></FormField>
                    <FormField label="Description"><TextArea v-model="section.description" rows="2" /></FormField>
                    <FormField label="Show only if"><RuleEditor v-model="section.visible_if" :fields="ruleFields" :disabled="readonly" empty-label="Always shown." /></FormField>
                </fieldset>
                <p v-else class="text-sm text-muted">Select a section or field.</p>
                <p v-if="problems.length" class="flex items-center gap-1 border-t border-line-soft pt-3 text-xs text-red-700"><CircleAlert :size="13" aria-hidden="true" />{{ problems.length }} problem(s) from the last save.</p>
            </aside>
        </div>
    </AppLayout>
</template>
