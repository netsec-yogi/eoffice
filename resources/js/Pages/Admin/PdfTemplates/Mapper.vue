<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import AppButton from '@/Components/AppButton.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ask } from '@/lib/confirm';
import { HttpError, json } from '@/lib/http';
import { router } from '@inertiajs/vue3';
import { Copy, Eye, EyeOff, FileDown, Plus, Save, Send, Trash2, Wand2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Visual field mapping (DOC-002). Boxes are stored in PDF points with a
 * top-left origin; the page image is scaled, so every pointer movement is
 * converted back to points. The server validates everything again.
 */
interface Column { path: string; x: number; w: number; align?: string; format?: Fmt }
interface Fmt { kind: string; pattern?: string; decimals?: number; prefix?: string; suffix?: string; empty?: string }
interface Field {
    key: string; label?: string; page: number; type: 'text' | 'multiline' | 'checkbox' | 'table'; source: string; text?: string;
    x: number; y: number; w: number; h: number; font?: string; font_size?: number; min_font_size?: number; align?: string; overflow?: string;
    format?: Fmt; required?: boolean; checkbox?: { match?: string; mark?: string }; condition?: { field: string; op: string; value: unknown } | null;
    table?: { row_height: number; max_rows: number; columns: Column[] };
}
interface Detected { name: string; type: string; multiline: boolean; page: number; x: number; y: number; w: number; h: number; options: string[] }
interface SourceItem { value: string; label: string; type?: string; columns?: { value: string; label: string }[] }
const props = defineProps<{
    template: { id: number; key: string; name: string };
    version: { id: number; version: number; status: string; pages: { width: number; height: number }[]; form_fields: Detected[]; mapping: Field[]; original_filename: string; editable: boolean };
    sources: { group: string; items: SourceItem[] }[];
}>();

const fields = ref<Field[]>(JSON.parse(JSON.stringify(props.version.mapping ?? [])));
const page = ref(1);
const selected = ref<number | null>(fields.value.length ? 0 : null);
const dirty = ref(false);
watch(fields, () => (dirty.value = true), { deep: true });
const current = computed(() => (selected.value === null ? null : fields.value[selected.value] ?? null));
const size = computed(() => props.version.pages[page.value - 1]!);
const ro = !props.version.editable;

// ---- scale between image pixels and PDF points
const img = ref<HTMLImageElement>();
const shown = ref(800);
const scale = computed(() => shown.value / size.value.width);
const measure = () => img.value && (shown.value = img.value.clientWidth);
const ro2 = new ResizeObserver(measure);
watch(img, (el) => el && ro2.observe(el));
onBeforeUnmount(() => ro2.disconnect());
const px = (pt: number) => `${pt * scale.value}px`;

// ---- sources
const allSources = computed(() => props.sources.flatMap((g) => g.items));
const sourceOptions = computed(() => [{ value: 'literal', label: 'Fixed text (typed below)' }, ...props.sources.flatMap((g) => g.items.map((i) => ({ value: i.value, label: `${g.group} · ${i.label}` })))]);
const tableSources = computed(() => allSources.value.filter((s) => s.type === 'table').map((s) => ({ value: s.value, label: s.label })));
const columnsOf = (src: string) => allSources.value.find((s) => s.value === src)?.columns ?? [];

function uniqueKey(base: string) {
    let k = base.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^[^a-z]+/, '').slice(0, 50) || 'field';
    const taken = new Set(fields.value.map((f) => f.key));
    let n = 2;
    const root = k;
    while (taken.has(k)) k = `${root}_${n++}`;
    return k;
}
function add(partial: Partial<Field> = {}) {
    const f: Field = { key: uniqueKey(partial.key ?? 'field'), label: '', page: page.value, type: 'text', source: '', x: 60, y: 60, w: 200, h: 18, font: 'sans', font_size: 10, min_font_size: 7, align: 'L', overflow: 'shrink', format: { kind: 'auto' }, required: false, ...partial };
    fields.value.push(f);
    selected.value = fields.value.length - 1;
}
const fromDetected = (d: Detected) =>
    add({ key: d.name, label: d.name, page: d.page, x: d.x, y: d.y, w: d.w, h: d.h, type: d.type === 'checkbox' || d.type === 'radio' ? 'checkbox' : d.multiline ? 'multiline' : 'text', checkbox: d.type === 'checkbox' ? { mark: '✓' } : undefined });
const mappedNames = computed(() => new Set(fields.value.map((f) => `${f.page}:${Math.round(f.x)}:${Math.round(f.y)}`)));
const unmapped = computed(() => props.version.form_fields.filter((d) => d.page === page.value && !mappedNames.value.has(`${d.page}:${Math.round(d.x)}:${Math.round(d.y)}`)));
function duplicate() {
    if (!current.value) return;
    add({ ...JSON.parse(JSON.stringify(current.value)), key: current.value.key, y: current.value.y + current.value.h + 4 });
}
async function remove() {
    if (selected.value === null || !(await ask(`Remove the field "${current.value?.label || current.value?.key}"?`, { confirmLabel: 'Remove', tone: 'danger' }))) return;
    fields.value.splice(selected.value, 1);
    selected.value = null;
}

// ---- dragging and resizing, in points
let drag: { i: number; mode: 'move' | 'size'; sx: number; sy: number; ox: number; oy: number; ow: number; oh: number } | null = null;
function start(e: PointerEvent, i: number, mode: 'move' | 'size') {
    selected.value = i;
    if (ro) return;
    const f = fields.value[i]!;
    drag = { i, mode, sx: e.clientX, sy: e.clientY, ox: f.x, oy: f.y, ow: f.w, oh: f.h };
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    e.preventDefault();
}
function move(e: PointerEvent) {
    if (!drag) return;
    const f = fields.value[drag.i]!;
    const dx = (e.clientX - drag.sx) / scale.value;
    const dy = (e.clientY - drag.sy) / scale.value;
    const r = (n: number) => Math.round(n * 2) / 2;
    if (drag.mode === 'move') {
        f.x = r(Math.min(Math.max(0, drag.ox + dx), size.value.width - f.w));
        f.y = r(Math.min(Math.max(0, drag.oy + dy), size.value.height - f.h));
    } else {
        f.w = r(Math.max(4, Math.min(drag.ow + dx, size.value.width - f.x)));
        f.h = r(Math.max(4, Math.min(drag.oh + dy, size.value.height - f.y)));
    }
}
const stop = () => (drag = null);
function nudge(e: KeyboardEvent) {
    if (ro || !current.value || (e.target as HTMLElement).closest('input, select, textarea')) return;
    const step = e.shiftKey ? 10 : 1;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
    if (!d) return;
    current.value.x = Math.max(0, current.value.x + d[0]!);
    current.value.y = Math.max(0, current.value.y + d[1]!);
    e.preventDefault();
}

// ---- type-dependent settings
watch(() => current.value?.type, (t) => {
    const f = current.value;
    if (!f) return;
    if (t === 'checkbox' && !f.checkbox) f.checkbox = { mark: '✓' };
    if (t === 'table' && !f.table) f.table = { row_height: 14, max_rows: 5, columns: [] };
});
const addColumn = () => current.value?.table?.columns.push({ path: '', x: current.value.table.columns.reduce((m, c) => Math.max(m, c.x + c.w), 0), w: 80, align: 'L', format: { kind: 'auto' } });
const condition = reactive({ on: false, field: '', op: 'eq', value: '' });
watch(selected, () => {
    const c = current.value?.condition;
    Object.assign(condition, { on: !!c, field: c?.field ?? '', op: c?.op ?? 'eq', value: c ? String(c.value ?? '') : '' });
}, { immediate: true });
watch(condition, (c) => current.value && (current.value.condition = c.on && c.field ? { field: c.field, op: c.op, value: c.value } : null), { deep: true });

// ---- save, preview, publish
const saving = ref(false);
const error = ref<string | null>(null);
function save(then?: () => void) {
    saving.value = true;
    router.put(route('admin.pdf-templates.mapping', props.version.id), { mapping: fields.value as never }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => ((dirty.value = false), (error.value = null), then?.()),
        onError: (e) => (error.value = e.mapping ?? e.workflow ?? 'Could not save.'),
        onFinish: () => (saving.value = false),
    });
}
const previews = ref<string[] | null>(null);
const previewing = ref(false);
async function preview() {
    previewing.value = true;
    error.value = null;
    try {
        previews.value = (await json<{ pages: string[] }>('POST', route('admin.pdf-templates.preview', props.version.id), { mapping: fields.value })).pages;
    } catch (e) {
        error.value = e instanceof HttpError ? e.message : 'Preview failed.';
        const key = e instanceof HttpError ? (e.body as { field?: string }).field : null;
        if (key) selected.value = fields.value.findIndex((f) => f.key === key.split('.')[0]);
        previews.value = null;
    } finally {
        previewing.value = false;
    }
}
async function publish() {
    if (!(await ask(`Publish version ${props.version.version}? Requests submitted from now on use it; it cannot be edited afterwards.`, { confirmLabel: 'Publish' }))) return;
    const go = () => router.post(route('admin.pdf-templates.publish', props.version.id));
    dirty.value ? save(go) : go();
}
const kinds = [['auto', 'As stored'], ['date', 'Date'], ['datetime', 'Date and time'], ['amount', 'Amount (Indian grouping)'], ['words', 'Amount in words (Rupees … Only)'], ['number', 'Number'], ['upper', 'UPPER CASE'], ['lower', 'lower case'], ['title', 'Title Case']].map(([value, label]) => ({ value: value!, label: label! }));
const ops = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains', 'empty', 'not_empty'].map((o) => ({ value: o, label: o }));
const guard = (e: BeforeUnloadEvent) => dirty.value && e.preventDefault();
window.addEventListener('beforeunload', guard);
onBeforeUnmount(() => window.removeEventListener('beforeunload', guard));
</script>

<template>
    <AppLayout :title="`Map ${template.name}`">
        <PageHeader :title="`${template.name} — version ${version.version}`" :description="`${version.original_filename} · ${version.pages.length} page(s) · ${ro ? 'published versions are read-only' : 'draft'}`"
            :breadcrumbs="[{ label: 'Official PDF templates', href: route('admin.pdf-templates.index') }, { label: template.name, href: route('admin.pdf-templates.show', template.id) }, { label: `Version ${version.version}` }]">
            <AppButton variant="outline" :icon="previews ? EyeOff : Eye" :loading="previewing" @click="previews ? (previews = null) : preview()">{{ previews ? 'Hide preview' : 'Preview with sample data' }}</AppButton>
            <AppButton v-if="!ro" variant="outline" :icon="Save" :loading="saving" :disabled="!dirty" @click="save()">Save</AppButton>
            <AppButton v-if="!ro" :icon="Send" @click="publish">Publish</AppButton>
            <a v-else :href="route('admin.pdf-templates.preview', version.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><FileDown :size="15" /> Sample PDF</a>
        </PageHeader>
        <AlertBox v-if="error" tone="danger" class="mb-4">{{ error }}</AlertBox>
        <AlertBox v-if="!ro && !sources.some((g) => g.items.some((i) => i.value.startsWith('form.')))" tone="info" class="mb-4">Attach this form to a workflow (template page) to map that workflow's form fields and approvals.</AlertBox>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]" @keydown="nudge" tabindex="-1">
            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <button v-for="(p, i) in version.pages" :key="i" type="button" :class="['rounded-lg px-3 py-1 text-sm', page === i + 1 ? 'bg-brand-600 text-white' : 'bg-surface-sunken text-ink-soft hover:bg-surface-muted']" @click="page = i + 1">Page {{ i + 1 }}</button>
                    <span class="flex-1" />
                    <AppButton v-if="!ro" size="sm" :icon="Plus" @click="add()">Add field</AppButton>
                </div>
                <div class="relative overflow-hidden rounded-lg border border-line bg-white shadow-sm select-none" @pointermove="move" @pointerup="stop" @pointercancel="stop">
                    <img ref="img" :src="previews ? previews[page - 1] : route('admin.pdf-templates.page', [version.id, page])" class="block w-full" :alt="`Page ${page} of the form`" draggable="false" @load="measure" />
                    <template v-if="!previews">
                        <button v-for="d in unmapped" :key="d.name" type="button" class="absolute border border-dashed border-amber-500 bg-amber-200/30 text-[10px] text-amber-900 hover:bg-amber-300/50"
                            :style="{ left: px(d.x), top: px(d.y), width: px(d.w), height: px(d.h) }" :title="`Interactive field “${d.name}”: click to map it`" :disabled="ro" @click="fromDetected(d)">
                            <span class="absolute -top-4 left-0 whitespace-nowrap"><Wand2 :size="10" class="inline" /> {{ d.name }}</span>
                        </button>
                        <div v-for="(f, i) in fields" v-show="f.page === page" :key="i"
                            :class="['absolute cursor-move border text-[10px] leading-tight', selected === i ? 'z-10 border-brand-600 bg-brand-500/20 ring-2 ring-brand-500/40' : 'border-brand-400 bg-brand-400/10 hover:bg-brand-400/20']"
                            :style="{ left: px(f.x), top: px(f.y), width: px(f.w), height: px(f.type === 'table' ? (f.table?.row_height ?? 14) * (f.table?.max_rows ?? 1) : f.h) }"
                            role="button" :aria-label="`Field ${f.label || f.key}`" tabindex="0" @pointerdown="start($event, i, 'move')" @focus="selected = i">
                            <span class="block truncate px-0.5 text-brand-900">{{ f.label || f.key }}</span>
                            <span v-if="!ro" class="absolute -right-1 -bottom-1 size-3 cursor-se-resize rounded-sm bg-brand-600" @pointerdown.stop="start($event, i, 'size')" />
                        </div>
                    </template>
                </div>
                <p class="mt-2 text-xs text-muted">Drag a box to move it, its corner to resize; arrow keys nudge by 1 pt (Shift: 10 pt). Dashed amber boxes are the PDF's own interactive fields.</p>
            </div>

            <aside class="space-y-4">
                <section class="card p-4">
                    <h2 class="mb-2 font-semibold text-ink">Fields ({{ fields.length }})</h2>
                    <ul class="max-h-56 space-y-0.5 overflow-auto text-sm">
                        <li v-for="(f, i) in fields" :key="i">
                            <button type="button" :class="['w-full truncate rounded px-2 py-1 text-left', selected === i ? 'bg-brand-50 font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200' : 'hover:bg-surface-sunken']" @click="(selected = i), (page = f.page)">
                                p{{ f.page }} · {{ f.label || f.key }} <span class="text-xs text-muted">← {{ f.source === 'literal' ? `“${f.text ?? ''}”` : f.source || 'no source' }}</span>
                            </button>
                        </li>
                    </ul>
                </section>

                <fieldset v-if="current" class="card space-y-3 p-4" :disabled="ro">
                    <div class="flex items-center justify-between"><h2 class="font-semibold text-ink">Field</h2>
                        <span v-if="!ro" class="flex gap-1"><AppButton size="sm" variant="ghost" :icon="Copy" aria-label="Duplicate field" @click="duplicate" /><AppButton size="sm" variant="danger-ghost" :icon="Trash2" aria-label="Remove field" @click="remove" /></span>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <FormField label="Label"><TextInput v-model="current.label" /></FormField>
                        <FormField label="Key"><TextInput v-model="current.key" class="font-mono" /></FormField>
                    </div>
                    <FormField label="Kind"><SelectInput v-model="current.type" :options="[{ value: 'text', label: 'Text, one line' }, { value: 'multiline', label: 'Text, several lines' }, { value: 'checkbox', label: 'Tick mark' }, { value: 'table', label: 'Table rows' }]" /></FormField>
                    <FormField label="Data"><SelectInput v-model="current.source" :options="current.type === 'table' ? tableSources : sourceOptions" placeholder="Choose what to print" /></FormField>
                    <FormField v-if="current.source === 'literal'" label="Fixed text" hint="May include values, e.g. {{ applicant.name }}, {{ applicant.designation }}"><TextInput v-model="current.text" /></FormField>
                    <div class="grid grid-cols-4 gap-2">
                        <FormField v-for="c in (['x', 'y', 'w', 'h'] as const)" :key="c" :label="c.toUpperCase() + ' (pt)'"><TextInput v-model.number="current[c]" type="number" step="0.5" /></FormField>
                    </div>
                    <template v-if="current.type !== 'checkbox'">
                        <div class="grid grid-cols-3 gap-2">
                            <FormField label="Font"><SelectInput v-model="current.font" :options="[{ value: 'sans', label: 'Sans' }, { value: 'serif', label: 'Serif' }, { value: 'mono', label: 'Mono' }]" /></FormField>
                            <FormField label="Size"><TextInput v-model.number="current.font_size" type="number" step="0.5" /></FormField>
                            <FormField label="Smallest"><TextInput v-model.number="current.min_font_size" type="number" step="0.5" /></FormField>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <FormField label="Align"><SelectInput v-model="current.align" :options="[{ value: 'L', label: 'Left' }, { value: 'C', label: 'Centre' }, { value: 'R', label: 'Right' }]" /></FormField>
                            <FormField label="If it does not fit"><SelectInput v-model="current.overflow" :options="[{ value: 'shrink', label: 'Shrink, then report' }, { value: 'error', label: 'Report' }]" /></FormField>
                        </div>
                        <div v-if="current.type !== 'table'" class="grid grid-cols-2 gap-2">
                            <FormField label="Format"><SelectInput :model-value="current.format?.kind ?? 'auto'" :options="kinds" @update:model-value="current.format = { ...(current.format ?? {}), kind: String($event) }" /></FormField>
                            <FormField v-if="['date', 'datetime'].includes(current.format?.kind ?? '')" label="Pattern" hint="e.g. d/m/Y"><TextInput :model-value="current.format?.pattern ?? ''" class="font-mono" @update:model-value="current.format = { ...current.format!, pattern: String($event ?? '') || undefined }" /></FormField>
                            <FormField v-if="['amount', 'number'].includes(current.format?.kind ?? '')" label="Prefix"><TextInput :model-value="current.format?.prefix ?? ''" @update:model-value="current.format = { ...current.format!, prefix: String($event ?? '') || undefined }" /></FormField>
                        </div>
                    </template>
                    <div v-if="current.type === 'checkbox'" class="grid grid-cols-2 gap-2">
                        <FormField label="Tick when value is" hint="blank = yes/true"><TextInput :model-value="current.checkbox?.match ?? ''" @update:model-value="current.checkbox = { ...current.checkbox, match: String($event ?? '') || undefined }" /></FormField>
                        <FormField label="Mark"><SelectInput :model-value="current.checkbox?.mark ?? '✓'" :options="[{ value: '✓', label: '✓' }, { value: 'X', label: 'X' }]" @update:model-value="current.checkbox = { ...current.checkbox, mark: String($event) }" /></FormField>
                    </div>
                    <div v-if="current.type === 'table' && current.table" class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <FormField label="Row height (pt)"><TextInput v-model.number="current.table.row_height" type="number" step="0.5" /></FormField>
                            <FormField label="Rows on the form"><TextInput v-model.number="current.table.max_rows" type="number" min="1" /></FormField>
                        </div>
                        <div v-for="(c, ci) in current.table.columns" :key="ci" class="grid grid-cols-[1fr_60px_60px_auto] items-end gap-1">
                            <FormField :label="`Column ${ci + 1}`"><SelectInput v-model="c.path" :options="[{ value: '#', label: 'Row number' }, ...columnsOf(current.source)]" placeholder="field" /></FormField>
                            <FormField label="X"><TextInput v-model.number="c.x" type="number" /></FormField>
                            <FormField label="W"><TextInput v-model.number="c.w" type="number" /></FormField>
                            <AppButton size="sm" variant="danger-ghost" :icon="Trash2" aria-label="Remove column" @click="current.table.columns.splice(ci, 1)" />
                        </div>
                        <AppButton size="sm" variant="outline" :icon="Plus" @click="addColumn">Column</AppButton>
                    </div>
                    <CheckboxInput :model-value="!!current.required" label="Required: stop and report if empty" @update:model-value="current.required = !!$event" />
                    <CheckboxInput v-model="condition.on" label="Print only when…" />
                    <div v-if="condition.on" class="grid grid-cols-3 gap-1">
                        <TextInput v-model="condition.field" placeholder="form.field" class="font-mono" aria-label="Condition field" />
                        <SelectInput v-model="condition.op" :options="ops" aria-label="Operator" />
                        <TextInput v-model="condition.value" placeholder="value" aria-label="Value" />
                    </div>
                </fieldset>
                <p v-else class="text-sm text-muted">Select a field, add one, or click a dashed interactive field on the page.</p>
            </aside>
        </div>
    </AppLayout>
</template>
